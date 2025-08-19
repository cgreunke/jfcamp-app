#!/usr/bin/env python3
import os
import random
import time
from dataclasses import dataclass
from typing import Any, Dict, List, Optional, Tuple
from collections import defaultdict, Counter
from urllib.parse import urlparse, urljoin, urlunparse, parse_qs

import requests
from flask import Flask, jsonify, request
from urllib3.util.retry import Retry
from requests.adapters import HTTPAdapter

# --------------------------------------------------------------------------------------
# Konfiguration
# --------------------------------------------------------------------------------------
DRUPAL_URL = os.getenv("DRUPAL_URL", "http://drupal/jsonapi").rstrip("/")
HTTP_TIMEOUT = float(os.getenv("HTTP_TIMEOUT", "30"))
PAGE_CHUNK = int(os.getenv("PAGE_CHUNK", "100"))
PUBLISHED_ONLY = os.getenv("PUBLISHED_ONLY", "1") not in ("0", "false", "False")
DEFAULT_SEED = os.getenv("MATCHING_SEED", "")
MAX_PAGES = int(os.getenv("MAX_PAGES") or os.getenv("MAX_PAGE_LIMIT", "1000"))

# HTTP-Session mit Retries
SESSION = requests.Session()
SESSION.trust_env = False
SESSION.headers.update({"Accept": "application/vnd.api+json, application/json;q=0.9"})
retry_strategy = Retry(total=4, connect=4, read=4, backoff_factor=0.5,
                       status_forcelist=[502, 503, 504], allowed_methods=["GET"],
                       raise_on_status=False)
adapter = HTTPAdapter(max_retries=retry_strategy)
SESSION.mount("http://", adapter)
SESSION.mount("https://", adapter)

# Basic Auth (beide Env-Namen unterstützt)
BASIC_USER = os.getenv("DRUPAL_BASIC_USER") or os.getenv("DRUPAL_USER") or ""
BASIC_PASS = os.getenv("DRUPAL_BASIC_PASS") or os.getenv("DRUPAL_PASS") or ""
if BASIC_USER and BASIC_PASS:
    SESSION.auth = (BASIC_USER, BASIC_PASS)

app = Flask(__name__)

# Diagnostik
FETCH_DIAG = {"root": None, "retries": 0, "last_error": None, "effective_limit": None}

# --------------------------------------------------------------------------------------
# JSON:API Root & robustes Fetching (next-first + Gap-Fill)
# --------------------------------------------------------------------------------------
from urllib.parse import urlparse, urljoin, urlunparse, parse_qs

def _jsonapi_root() -> str:
    base = DRUPAL_URL.rstrip("/")
    root = base if base.endswith("/jsonapi") else f"{base}/jsonapi"
    FETCH_DIAG["root"] = root
    return root

def _same_host_url(path_or_url: str) -> str:
    """Rewritet absolute next-Links (localhost) auf Container-Host (drupal)."""
    root = _jsonapi_root()
    pr = urlparse(root)
    pu = urlparse(path_or_url)
    if pu.scheme and pu.netloc:
        return urlunparse((pr.scheme, pr.netloc, pu.path, pu.params, pu.query, pu.fragment))
    return urljoin(root.split("/jsonapi")[0] + "/", path_or_url)

def _get_json(url: str, params: Optional[Dict[str, str]] = None) -> Dict[str, Any]:
    resp = SESSION.get(url, params=params, timeout=HTTP_TIMEOUT)
    resp.raise_for_status()
    return resp.json()

def _maybe_add_stable_sort(path: str, params: Dict[str, str], variant: int = 1) -> None:
    """Erzwinge stabile Sortierung für node/*-Ressourcen."""
    if not path.startswith("node/") or "sort" in params:
        return
    if variant == 1:
        params["sort"] = "drupal_internal__nid"; FETCH_DIAG["sort_used"] = "drupal_internal__nid"
    elif variant == 2:
        params["sort"] = "created";              FETCH_DIAG["sort_used"] = "created"

def _parse_offset_limit_from_url(url: str) -> Tuple[Optional[int], Optional[int]]:
    qs = parse_qs(urlparse(url).query)
    def gi(name):
        try:
            v = qs.get(name, [None])[0]
            return int(v) if v is not None else None
        except Exception:
            return None
    return gi("page[offset]"), gi("page[limit]")

def _effective_limit_from_payload(payload: Dict[str, Any], requested: int) -> int:
    data = payload.get("data", []) or []
    if isinstance(data, dict): data = [data]
    if 0 < len(data) < max(1, requested):
        return len(data)
    for key in ("self", "next", "first"):
        link = payload.get("links", {}).get(key)
        href = link.get("href") if isinstance(link, dict) else (link if isinstance(link, str) else None)
        if href:
            _, lim = _parse_offset_limit_from_url(href)
            if lim and lim > 0: return lim
    return requested if requested > 0 else 50

def _first_page_with_sort_fallback(url: str, base_params: Dict[str, str], path: str) -> Tuple[Dict[str, Any], Dict[str, str]]:
    # 1) nid
    params = dict(base_params); _maybe_add_stable_sort(path, params, 1)
    try:
        return _get_json(url, params), params
    except requests.exceptions.HTTPError as e:
        if getattr(e.response, "status_code", None) != 400:
            raise
    # 2) created
    params2 = dict(base_params); _maybe_add_stable_sort(path, params2, 2)
    try:
        return _get_json(url, params2), params2
    except requests.exceptions.HTTPError as e2:
        if getattr(e2.response, "status_code", None) != 400:
            raise
    # 3) ohne sort
    params3 = dict(base_params); FETCH_DIAG["sort_used"] = None
    return _get_json(url, params3), params3

def _fetch_all(path: str, extra_params: Optional[Dict[str, str]] = None, *, published_only: Optional[bool] = None) -> List[Dict[str, Any]]:
    """
    Strategie:
      1) Erste Seite laden (offset=0) mit stabiler Sortierung.
      2) Der **links.next**-Kette folgen (Host-Rewrite!), IDs sammeln, besuchte Offsets/URLs merken.
      3) **Gap-Fill**: Offsets 0, L, 2L, ... bis leer nachladen – aber nur Offsets, die noch nicht besucht wurden.
      4) Dedupe per ID.
    """
    if published_only is None:
        published_only = PUBLISHED_ONLY

    root = _jsonapi_root().rstrip("/")
    base_url = f"{root}/{path.lstrip('/')}"
    base_params: Dict[str, str] = {}
    if published_only:
        base_params["filter[status][value]"] = "1"
    if extra_params:
        base_params.update(extra_params)
    if "page[limit]" not in base_params:
        base_params["page[limit]"] = str(PAGE_CHUNK)

    # --- 1) Erste Seite ---
    params0 = dict(base_params); params0["page[offset]"] = "0"
    payload, used_params = _first_page_with_sort_fallback(base_url, params0, path)
    data = payload.get("data", []) or []
    if isinstance(data, dict): data = [data]

    result_by_id: Dict[str, Dict[str, Any]] = {}
    for item in data: result_by_id[item["id"]] = item

    # effektives Limit bestimmen
    requested = int(base_params.get("page[limit]", PAGE_CHUNK))
    eff_limit = _effective_limit_from_payload(payload, requested)
    FETCH_DIAG["effective_limit"] = eff_limit

    # --- 2) links.next verfolgen (mit Host-Rewrite) ---
    visited_offsets: set[int] = set()
    visited_urls: set[str] = set()
    # offset der ersten Seite
    off0, _ = _parse_offset_limit_from_url(_same_host_url(payload.get("links", {}).get("self", {}).get("href", ""))) if isinstance(payload.get("links", {}).get("self"), dict) else (0, eff_limit)
    visited_offsets.add(off0 or 0)

    def _extract_next_href(pl: Dict[str, Any]) -> Optional[str]:
        ln = pl.get("links", {}).get("next")
        href = ln.get("href") if isinstance(ln, dict) else (ln if isinstance(ln, str) else None)
        return _same_host_url(href) if href else None

    pages = 1
    next_url = _extract_next_href(payload)
    while next_url and pages < MAX_PAGES:
        if next_url in visited_urls:
            break  # Loop-Schutz
        visited_urls.add(next_url)

        # Offset aus next_URL extrahieren (für Buchhaltung)
        off, lim = _parse_offset_limit_from_url(next_url)
        if lim and lim > 0:
            eff_limit = lim  # Server hat Limit geändert → übernehmen
            FETCH_DIAG["effective_limit"] = eff_limit
        if off is not None:
            visited_offsets.add(off)

        try:
            payload = _get_json(next_url)
        except Exception:
            break
        data = payload.get("data", []) or []
        if isinstance(data, dict): data = [data]
        if not data:
            break
        for item in data:
            result_by_id[item["id"]] = item

        pages += 1
        next_url = _extract_next_href(payload)

    # --- 3) Gap-Fill per Offset (nur fehlende Offsets) ---
    # wir scannen 0, L, 2L, ... bis eine Seite leer ist – und überspringen bereits besuchte Offsets
    for i in range(0, MAX_PAGES):
        offset = i * eff_limit
        if offset in visited_offsets:
            continue
        page_params = dict(used_params)
        page_params["page[limit]"] = str(eff_limit)
        page_params["page[offset]"] = str(offset)
        try:
            payload = _get_json(base_url, params=page_params)
        except Exception:
            break
        data = payload.get("data", []) or []
        if isinstance(data, dict): data = [data]
        if not data:
            break
        for item in data:
            result_by_id[item["id"]] = item
        visited_offsets.add(offset)

    if not result_by_id:
        raise RuntimeError(f"JSON:API fetch failed for '{path}' – keine Daten erhalten.")
    return list(result_by_id.values())


# --------------------------------------------------------------------------------------
# Datenmodelle
# --------------------------------------------------------------------------------------
@dataclass
class Workshop:
    id: str
    title: str
    capacity: int
    capacity_field_used: str = "field_maximale_plaetze"

@dataclass
class Participant:
    id: str
    code: str
    wishes: List[str]
    region: Optional[str] = None

# --------------------------------------------------------------------------------------
# Daten aus Drupal lesen
# --------------------------------------------------------------------------------------
def load_matching_config() -> Dict[str, Any]:
    nodes = _fetch_all("node/matching_config", extra_params={"page[limit]": "1"})
    cfg = {
        "num_wishes": 5,
        "num_assign": 3,
        "seed": DEFAULT_SEED,
        "topk_equals_slots": True,
        "slicing_mode": "relative",  # off|relative|fixed
        "slicing_value": 50,
        "weights": {1: 1.0, 2: 0.8, 3: 0.6, 4: 0.4, 5: 0.2},
    }
    if not nodes:
        return cfg

    n = nodes[0]

    def fv(name, default=None):
        f = n.get("attributes", {}).get(name)
        return f if f not in (None, "") else default

    cfg["num_wishes"] = int(n.get("attributes", {}).get("field_num_wuensche", 5) or 5)
    cfg["num_assign"] = int(n.get("attributes", {}).get("field_zuteilung", n.get("attributes", {}).get("field_num_zuteilung", 3)) or 3)
    cfg["seed"] = fv("field_seed", DEFAULT_SEED) or DEFAULT_SEED
    cfg["topk_equals_slots"] = bool(n.get("attributes", {}).get("field_topk_equals_slots", True))
    cfg["slicing_mode"] = n.get("attributes", {}).get("field_slicing_mode", "relative") or "relative"

    sv = n.get("attributes", {}).get("field_slicing_value", 50)
    try:
        cfg["slicing_value"] = int(sv)
    except Exception:
        cfg["slicing_value"] = 50

    def ffloat(attr, d):
        v = n.get("attributes", {}).get(attr, d)
        try:
            return float(v)
        except Exception:
            return d

    cfg["weights"] = {
        1: ffloat("field_weight_p1", 1.0),
        2: ffloat("field_weight_p2", 0.8),
        3: ffloat("field_weight_p3", 0.6),
        4: ffloat("field_weight_p4", 0.4),
        5: ffloat("field_weight_p5", 0.2),
    }
    return cfg

def load_workshops() -> Dict[str, Workshop]:
    nodes = _fetch_all("node/workshop")
    out: Dict[str, Workshop] = {}
    for d in nodes:
        attrs = d.get("attributes", {})
        cap = int(attrs.get("field_maximale_plaetze") or 0)
        out[d["id"]] = Workshop(
            id=d["id"],
            title=attrs.get("title") or "",
            capacity=cap,
            capacity_field_used="field_maximale_plaetze",
        )
    return out

def load_participants_and_wishes(num_wishes: int) -> Dict[str, Participant]:
    tns = _fetch_all("node/teilnehmer")
    wns = _fetch_all("node/wunsch")

    wishes_by_participant: Dict[str, List[str]] = defaultdict(list)
    for w in wns:
        rel = w.get("relationships", {}) or {}
        tnode = rel.get("field_teilnehmer", {}).get("data")
        if not tnode:
            continue
        pid = tnode.get("id")
        wish_nodes = rel.get("field_wuensche", {}).get("data") or []
        wish_ids = [x.get("id") for x in wish_nodes if x and x.get("id")]
        if num_wishes > 0:
            wish_ids = wish_ids[:num_wishes]
        wishes_by_participant[pid] = wish_ids

    out: Dict[str, Participant] = {}
    for n in tns:
        attrs = n.get("attributes", {}) or {}
        pid = n["id"]
        out[pid] = Participant(
            id=pid,
            code=attrs.get("field_code") or "",
            region=attrs.get("field_regionalverband") or None,
            wishes=wishes_by_participant.get(pid, []),
        )
    return out

# --------------------------------------------------------------------------------------
# Matching-Algorithmus
# --------------------------------------------------------------------------------------
def compute_happy_index(assignments: Dict[str, Dict[int, str]], wishes: Dict[str, List[str]], weights: Dict[int, float], topk: int) -> Tuple[float, Dict[str, float]]:
    per_user: Dict[str, float] = {}
    weight_sum = sum(weights.get(p, 0.0) for p in range(1, topk + 1))
    if weight_sum <= 0:
        weight_sum = 1.0
    for pid, slot_map in assignments.items():
        score = 0.0
        for slot, wid in slot_map.items():
            if wid in wishes.get(pid, [])[:topk]:
                p = wishes[pid][:topk].index(wid) + 1
                score += weights.get(p, 0.0)
        per_user[pid] = score / weight_sum if topk else 0.0
    happy = sum(per_user.values()) / len(per_user) if per_user else 0.0
    return happy, per_user

def run_matching(participants: Dict[str, Participant], workshops: Dict[str, Workshop], cfg: Dict[str, Any]) -> Tuple[Dict[str, Dict[int, str]], Dict[str, Any]]:
    num_assign = cfg["num_assign"]
    num_wishes = cfg["num_wishes"]
    topk = num_assign if cfg.get("topk_equals_slots", True) else min(num_assign, num_wishes)
    seed = cfg.get("seed") or ""
    rng = random.Random(seed or None)

    cap_per_slot: Dict[int, Dict[str, int]] = {s: {w.id: w.capacity for w in workshops.values()} for s in range(1, num_assign + 1)}
    pids = list(participants.keys())
    rng.shuffle(pids)
    assignments: Dict[str, Dict[int, str]] = {pid: {} for pid in pids}

    slicing_mode = cfg.get("slicing_mode", "off")
    slicing_value = int(cfg.get("slicing_value", 0))

    pop_counter = Counter()
    for p in participants.values():
        for wid in p.wishes[:topk]:
            pop_counter[wid] += 1
    renner_cut = max(1, int(0.2 * max(1, len(workshops))))
    renner_ids = {wid for wid, _ in pop_counter.most_common(renner_cut)}

    per_slot_cap_deckel: Dict[int, Dict[str, Optional[int]]] = {s: {} for s in range(1, num_assign + 1)}
    if slicing_mode == "relative":
        for s in range(1, num_assign + 1):
            for w in workshops.values():
                per_slot_cap_deckel[s][w.id] = int(round((slicing_value / 100.0) * w.capacity))
    elif slicing_mode == "fixed":
        for s in range(1, num_assign + 1):
            for w in workshops.values():
                per_slot_cap_deckel[s][w.id] = min(slicing_value, w.capacity)
    else:
        for s in range(1, num_assign + 1):
            for w in workshops.values():
                per_slot_cap_deckel[s][w.id] = None

    used_per_slot: Dict[int, Counter] = {s: Counter() for s in range(1, num_assign + 1)}

    for s in range(1, num_assign + 1):
        rotated = pids[s - 1:] + pids[:s - 1]
        for pid in rotated:
            p = participants[pid]
            assigned = False

            for wid in p.wishes[:num_wishes]:
                if wid in assignments[pid].values():
                    continue
                if cap_per_slot[s].get(wid, 0) <= 0:
                    continue
                deckel = per_slot_cap_deckel[s][wid]
                if (slicing_mode != "off") and (wid in renner_ids) and (deckel is not None) and (used_per_slot[s][wid] >= deckel):
                    continue
                assignments[pid][s] = wid
                cap_per_slot[s][wid] -= 1
                used_per_slot[s][wid] += 1
                assigned = True
                break

            if assigned:
                continue

            for wid, rest in list(cap_per_slot[s].items()):
                if rest <= 0:
                    continue
                if wid in assignments[pid].values():
                    continue
                deckel = per_slot_cap_deckel[s][wid]
                if (slicing_mode != "off") and (wid in renner_ids) and (deckel is not None) and (used_per_slot[s][wid] >= deckel):
                    continue
                assignments[pid][s] = wid
                cap_per_slot[s][wid] -= 1
                used_per_slot[s][wid] += 1
                break

    weights = cfg.get("weights", {1: 1.0, 2: 0.8, 3: 0.6, 4: 0.4, 5: 0.2})
    happy, per_user_happy = compute_happy_index(assignments, {k: v.wishes for k, v in participants.items()}, weights, topk)
    per_slot_counts = {s: len([1 for pid in pids if assignments[pid].get(s)]) for s in range(1, num_assign + 1)}
    assign_dist = Counter([len(assignments[pid]) for pid in pids])
    cap_total = sum(w.capacity for w in workshops.values()) * num_assign
    cap_remaining = sum(sum(max(0, x) for x in cap_per_slot[s].values()) for s in cap_per_slot)

    filler_count = 0
    for pid in pids:
        for s, wid in assignments[pid].items():
            if wid not in participants[pid].wishes[:num_wishes]:
                filler_count += 1

    unfilled = []
    for wid, w in workshops.items():
        remaining_total = sum(cap_per_slot[s][wid] for s in cap_per_slot)
        if remaining_total > 0:
            unfilled.append({"id": wid, "title": w.title, "remaining": remaining_total})

    summary = {
        "seed": seed or "random",
        "participants_total": len(pids),
        "participants_no_wishes": len([1 for p in participants.values() if not p.wishes]),
        "assignments_total": sum(len(v) for v in assignments.values()),
        "target_assignments_total": len(pids) * num_assign,
        "assignment_distribution": dict(assign_dist),
        "per_slot_assigned_counts": per_slot_counts,
        "per_priority_fulfilled": dict({i: 0 for i in range(1, num_wishes + 1)}),
        "capacity_total": cap_total,
        "capacity_remaining_total": cap_remaining,
        "filler_assignments": filler_count,
        "unfilled_workshops_count": len(unfilled),
        "warning_capacity_deficit": max(0, (len(pids) * num_assign) - cap_total),
        "all_filled_to_slots": all(len(assignments[pid]) == num_assign for pid in pids),
        "happy_index": round(happy, 4),
    }

    for pid in pids:
        wish_list = participants[pid].wishes[:num_wishes]
        for s, wid in assignments[pid].items():
            if wid in wish_list:
                prio = wish_list.index(wid) + 1
                summary["per_priority_fulfilled"][prio] = summary["per_priority_fulfilled"].get(prio, 0) + 1

    meta = {"unfilled_workshops": unfilled}
    return assignments, {"summary": summary, **meta}

# --------------------------------------------------------------------------------------
# API
# --------------------------------------------------------------------------------------
@app.get("/matching/health")
def health():
    return jsonify({"status": "ok"})

@app.get("/health")
def health_root():
    return jsonify({"status": "ok"})

@app.get("/")
def root():
    return jsonify({"status": "ok", "hint": "use /matching/stats or POST /matching/dry-run"})

@app.get("/matching/debug")
def debug():
    cfg = load_matching_config()
    workshops = load_workshops()
    participants = load_participants_and_wishes(cfg["num_wishes"])
    return jsonify({
        "status": "ok",
        "published_only": PUBLISHED_ONLY,
        "counts_all": {
            "teilnehmer_all": len(participants),
            "workshops_all": len(workshops),
            "wunsch_all": len(_fetch_all("node/wunsch")),
        },
        "diag": {
            "root": FETCH_DIAG.get("root"),
            "effective_limit": FETCH_DIAG.get("effective_limit"),
            "retries": FETCH_DIAG.get("retries", 0),
            "last_error": FETCH_DIAG.get("last_error"),
        },
    })

@app.get("/matching/probe")
def probe():
    cfg = load_matching_config()
    workshops = load_workshops()
    participants = load_participants_and_wishes(cfg["num_wishes"])
    sample = list(participants.keys())[:10]
    return jsonify({
        "status": "ok",
        "published_only": PUBLISHED_ONLY,
        "participants_seen": len(participants),
        "wunsch_nodes_seen": len(_fetch_all("node/wunsch")),
        "teilnehmer_nodes_raw": len(participants),
        "sample_participant_ids": sample,
    })

@app.get("/matching/probe/missing")
def probe_missing():
    cfg = load_matching_config()
    participants = load_participants_and_wishes(cfg["num_wishes"])
    missing = [pid for pid, p in participants.items() if not p.wishes]
    sample = []
    return jsonify({"status": "ok", "missing_count": len(missing), "missing_sample_ids": sample})

@app.get("/matching/stats")
def stats():
    cfg = load_matching_config()
    workshops = load_workshops()
    participants = load_participants_and_wishes(cfg["num_wishes"])

    cap_fields_counter = Counter((w.capacity_field_used or "UNDETECTED") for w in workshops.values())
    topk = cfg["num_assign"] if cfg.get("topk_equals_slots", True) else min(cfg["num_assign"], cfg["num_wishes"])
    popularity = Counter()
    for p in participants.values():
        for wid in p.wishes[:topk]:
            popularity[wid] += 1

    pop_preview = [
        {"id": wid, "title": workshops.get(wid, Workshop(wid, "?", 0)).title, "topk_demand": cnt}
        for wid, cnt in popularity.most_common(10)
    ]

    capacity_preview = [{"id": w.id, "title": w.title, "capacity": w.capacity} for w in workshops.values()]
    wishes_hist = Counter(len(p.wishes) for p in participants.values())

    return jsonify({
        "status": "ok",
        "published_only": PUBLISHED_ONLY,
        "config": {"num_assign": cfg["num_assign"], "num_wishes": cfg["num_wishes"]},
        "counts": {"teilnehmer_seen": len(participants), "workshops": len(workshops)},
        "wishes_per_participant_histogram": dict(wishes_hist),
        "capacity_fields_used_histogram": dict(cap_fields_counter),
        "popularity_topk_preview": pop_preview,
        "capacity_preview": capacity_preview,
    })

@app.get("/matching/debug/crawl")
def debug_crawl():
    path = request.args.get("path", "node/teilnehmer")
    limit = int(request.args.get("limit", str(PAGE_CHUNK)))
    max_pages = int(request.args.get("pages", "20"))

    root = _jsonapi_root().rstrip("/")
    url = f"{root}/{path.lstrip('/')}"

    details = []
    total = 0
    offset = 0
    for _ in range(max_pages):
        params = {"page[limit]": str(limit), "page[offset]": str(offset)}
        if PUBLISHED_ONLY:
            params["filter[status][value]"] = "1"
        try:
            t0 = time.time()
            resp = SESSION.get(url, params=params, timeout=HTTP_TIMEOUT)
            ms = round((time.time() - t0) * 1000)
            ok = resp.status_code
            data = (resp.json().get("data", []) or [])
            count = len(data) if isinstance(data, list) else 1
            details.append({"root": url, "offset": offset, "count": count, "status": ok, "ms": ms})
        except Exception as e:
            details.append({"root": url, "offset": offset, "count": 0, "status": f"ERR {type(e).__name__}", "ms": None})
            break
        total += count
        if count == 0:
            break
        offset += limit

    return jsonify({"published_only": PUBLISHED_ONLY, "page_limit": limit, "total_seen": total, "steps": details})

@app.get("/matching/debug/http")
def debug_http():
    import socket
    report = {"env": {
        "DRUPAL_URL": DRUPAL_URL,
        "DRUPAL_USER_set": bool(os.getenv("DRUPAL_USER") or os.getenv("DRUPAL_BASIC_USER")),
        "DRUPAL_PASS_set": bool(os.getenv("DRUPAL_PASS") or os.getenv("DRUPAL_BASIC_PASS")),
        "PUBLISHED_ONLY": PUBLISHED_ONLY,
        "PAGE_CHUNK": PAGE_CHUNK,
        "HTTP_TIMEOUT": HTTP_TIMEOUT,
    }, "dns": {}, "probes": []}

    try:
        report["dns"]["drupal_getaddrinfo"] = str(socket.getaddrinfo("drupal", 80))
    except Exception as e:
        report["dns"]["drupal_getaddrinfo_error"] = str(e)

    for url in [f"{_jsonapi_root().rstrip('/')}/node/teilnehmer",
                "http://drupal/jsonapi/node/teilnehmer"]:
        try:
            r = SESSION.get(url, params={"page[limit]": "1"}, timeout=HTTP_TIMEOUT)
            report["probes"].append({"url": url, "status": getattr(r, "status_code", None),
                                     "ct": r.headers.get("content-type", ""), "len": len(r.content)})
        except Exception as e:
            report["probes"].append({"url": url, "error": type(e).__name__, "msg": str(e)})
    report["diag"] = {"root": FETCH_DIAG.get("root"),
                      "effective_limit": FETCH_DIAG.get("effective_limit"),
                      "retries": FETCH_DIAG.get("retries", 0),
                      "last_error": FETCH_DIAG.get("last_error")}
    return jsonify(report)

@app.post("/matching/dry-run")
def dry_run():
    cfg = load_matching_config()
    workshops = load_workshops()
    participants = load_participants_and_wishes(cfg["num_wishes"])
    assignments, meta = run_matching(participants, workshops, cfg)

    rows = []
    for pid, slots in assignments.items():
        for s, wid in sorted(slots.items()):
            rows.append({
                "participant_id": pid,
                "slot": s,
                "workshop_id": wid,
                "workshop_title": workshops.get(wid, Workshop(wid, "?", 0)).title,
            })

    return jsonify({
        "status": "ok",
        "mode": "dry-run",
        "summary": meta["summary"],
        "unfilled_workshops": meta["unfilled_workshops"],
        "assignments_by_slot": {str(s): [r for r in rows if r["slot"] == s] for s in range(1, cfg["num_assign"] + 1)},
        "by_participant": {pid: {str(s): wid for s, wid in slots.items()} for pid, slots in assignments.items()},
        "export_rows": rows[:2000],
        "diag": {"root": FETCH_DIAG.get("root"),
                 "effective_limit": FETCH_DIAG.get("effective_limit"),
                 "retries": FETCH_DIAG.get("retries", 0),
                 "last_error": FETCH_DIAG.get("last_error")},
    })

if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5001)
