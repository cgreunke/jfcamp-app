#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
JF Startercamp – Matching Service (3 Strategien, schlanke Node-Config)

Node (persistente Basics):
- field_num_wuensche         → cfg["num_wishes"]
- field_num_zuteilung/field_zuteilung → cfg["num_assign"]
- field_slot_start, field_slot_end    → nur informativ (nicht für Matching nötig)

Service-Defaults (ändere bei Bedarf unten in SERVICE_DEFAULTS oder via ENV):
- strategy (fair|greedy|solver), objective, round_cap_pct, alpha_fairness, seeds,
  topk_equals_slots, weights (Fallback)

Ad-hoc Overrides (pro Request-Body bei /matching/dry-run):
- strategy, objective, round_cap_pct, alpha_fairness, seeds, seed,
  topk_equals_slots, num_assign, num_wishes,
  weights (Mapping {"1":1.0,...}),
  weights_mode ("linear"|"geometric"),
  weights_base (geometric: default 0.8), linear_min (linear: default 0.2)
"""

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
# Konfiguration & Defaults
# --------------------------------------------------------------------------------------
DRUPAL_URL = os.getenv("DRUPAL_URL", "http://drupal/jsonapi").rstrip("/")
HTTP_TIMEOUT = float(os.getenv("HTTP_TIMEOUT", "30"))
PAGE_CHUNK = int(os.getenv("PAGE_CHUNK", "100"))
PUBLISHED_ONLY = os.getenv("PUBLISHED_ONLY", "1") not in ("0", "false", "False")
DEFAULT_SEED = os.getenv("MATCHING_SEED", "")
MAX_PAGES = int(os.getenv("MAX_PAGES") or os.getenv("MAX_PAGE_LIMIT", "1000"))

SERVICE_DEFAULTS = {
    "strategy": "fair",             # "fair" | "greedy" | "solver"
    "objective": "fair_maxmin",     # "fair_maxmin" | "happy_mean" | "leximin"
    "round_cap_pct": 50,            # nur "fair": Deckel in Runde 1 für Renner (% der Kapazität)
    "alpha_fairness": 0.35,         # nur "fair": Benachteiligte Gewichtung
    "seeds": 12,                    # nur "fair": Multi-Seed Auswahl
    "topk_equals_slots": True,      # i. d. R. True (Top-k == Anzahl Slots)
    "weights": {1: 1.0, 2: 0.8, 3: 0.6, 4: 0.4, 5: 0.2},  # Fallback
}

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

# Optional: Basic Auth
BASIC_USER = os.getenv("DRUPAL_BASIC_USER") or os.getenv("DRUPAL_USER") or ""
BASIC_PASS = os.getenv("DRUPAL_BASIC_PASS") or os.getenv("DRUPAL_PASS") or ""
if BASIC_USER and BASIC_PASS:
    SESSION.auth = (BASIC_USER, BASIC_PASS)

app = Flask(__name__)
FETCH_DIAG = {"root": None, "retries": 0, "last_error": None, "effective_limit": None}

# --------------------------------------------------------------------------------------
# JSON:API Fetch
# --------------------------------------------------------------------------------------
def _jsonapi_root() -> str:
    base = DRUPAL_URL.rstrip("/")
    root = base if base.endswith("/jsonapi") else f"{base}/jsonapi"
    FETCH_DIAG["root"] = root
    return root

def _same_host_url(path_or_url: str) -> str:
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
    params = dict(base_params); _maybe_add_stable_sort(path, params, 1)
    try:
        return _get_json(url, params), params
    except requests.exceptions.HTTPError as e:
        if getattr(e.response, "status_code", None) != 400:
            raise
    params2 = dict(base_params); _maybe_add_stable_sort(path, params2, 2)
    try:
        return _get_json(url, params2), params2
    except requests.exceptions.HTTPError as e2:
        if getattr(e2.response, "status_code", None) != 400:
            raise
    params3 = dict(base_params); FETCH_DIAG["sort_used"] = None
    return _get_json(url, params3), params3

def _fetch_all(path: str, extra_params: Optional[Dict[str, str]] = None, *, published_only: Optional[bool] = None) -> List[Dict[str, Any]]:
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

    params0 = dict(base_params); params0["page[offset]"] = "0"
    payload, used_params = _first_page_with_sort_fallback(base_url, params0, path)
    data = payload.get("data", []) or []
    if isinstance(data, dict): data = [data]

    result_by_id: Dict[str, Dict[str, Any]] = {item["id"]: item for item in data}

    requested = int(base_params.get("page[limit]", PAGE_CHUNK))
    eff_limit = _effective_limit_from_payload(payload, requested)
    FETCH_DIAG["effective_limit"] = eff_limit

    visited_offsets: set[int] = set()
    visited_urls: set[str] = set()
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
            break
        visited_urls.add(next_url)
        off, lim = _parse_offset_limit_from_url(next_url)
        if lim and lim > 0:
            eff_limit = lim
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
# Config & Daten laden
# --------------------------------------------------------------------------------------
def load_matching_config() -> Dict[str, Any]:
    """
    Nur schlanke Node-Felder lesen; alles andere aus SERVICE_DEFAULTS
    oder ad-hoc per Request-Body.
    """
    nodes = _fetch_all("node/matching_config", extra_params={"page[limit]": "1"})
    cfg = {
        "num_wishes": 5,
        "num_assign": 3,
        "slot_start": None,
        "slot_end": None,
        # Strategy/Tuning erst mal aus Service-Defaults (können per Body überschrieben werden)
        **SERVICE_DEFAULTS,
        "seed": DEFAULT_SEED,
    }
    if nodes:
        n = nodes[0]
        attrs = n.get("attributes", {}) or {}
        def av(name, default=None): 
            v = attrs.get(name)
            return v if v not in (None, "") else default
        cfg["num_wishes"] = int(av("field_num_wuensche", cfg["num_wishes"]) or cfg["num_wishes"])
        cfg["num_assign"] = int(av("field_zuteilung", av("field_num_zuteilung", cfg["num_assign"])) or cfg["num_assign"])
        cfg["slot_start"] = av("field_slot_start", None)
        cfg["slot_end"] = av("field_slot_end", None)
    return cfg

def load_workshops() -> Dict[str, Workshop]:
    nodes = _fetch_all("node/workshop")
    out: Dict[str, Workshop] = {}
    for d in nodes:
        attrs = d.get("attributes", {})
        cap = int(attrs.get("field_maximale_plaetze") or 0)
        out[d["id"]] = Workshop(
            id=d["id"], title=attrs.get("title") or "", capacity=cap,
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
# Metriken
# --------------------------------------------------------------------------------------
def _extend_weights(weights: Dict[int, float], topk: int) -> Dict[int, float]:
    """Fehlende Ränge mit letztem vorhandenen Gewicht auffüllen."""
    if not weights:
        return {i: 0.0 for i in range(1, topk + 1)}
    out = dict(weights)
    lastk = max(out.keys())
    lastw = out[lastk]
    for r in range(1, topk + 1):
        if r not in out:
            out[r] = lastw
    return out

def _gen_weights(mode: Optional[str], num_wishes: int, *, base: float = 0.8, linear_min: float = 0.2) -> Dict[int, float]:
    if not mode:
        return {}
    mode = mode.strip().lower()
    if mode == "geometric":
        w = {1: 1.0}
        for r in range(2, num_wishes + 1):
            w[r] = w[r - 1] * float(base)
        return w
    if mode == "linear":
        hi = 1.0; lo = float(linear_min)
        if num_wishes == 1:
            return {1: 1.0}
        step = (hi - lo) / (num_wishes - 1)
        return {r: round(hi - (r - 1) * step, 6) for r in range(1, num_wishes + 1)}
    return {}

def compute_happy_index(assignments: Dict[str, Dict[int, str]],
                        wishes: Dict[str, List[str]],
                        weights: Dict[int, float],
                        topk: int) -> Tuple[float, Dict[str, float]]:
    per_user: Dict[str, float] = {}

    def _wf(rank: int) -> float:
        return weights.get(rank, weights.get(max(weights.keys()) if weights else 1, 0.0))

    weight_sum = sum(_wf(p) for p in range(1, topk + 1)) or 1.0

    for pid, slot_map in assignments.items():
        score = 0.0
        for _, wid in slot_map.items():
            wl = wishes.get(pid, [])[:topk]
            if wid in wl:
                p = wl.index(wid) + 1
                score += _wf(p)
        per_user[pid] = score / weight_sum if topk else 0.0

    happy = sum(per_user.values()) / len(per_user) if per_user else 0.0
    return happy, per_user

def _gini(values: List[float]) -> float:
    vals = [v for v in values if v is not None]
    n = len(vals)
    if n == 0:
        return 0.0
    vals.sort()
    cum = 0.0
    for i, v in enumerate(vals, 1):
        cum += i * v
    s = sum(vals)
    if s <= 0:
        return 0.0
    return (2 * cum) / (n * s) - (n + 1) / n

def _jain(values: List[float]) -> float:
    vals = [max(0.0, v) for v in values]
    n = len(vals)
    if n == 0:
        return 0.0
    s1 = sum(vals)
    s2 = sum(v*v for v in vals)
    if s2 <= 0:
        return 0.0
    return (s1 * s1) / (n * s2)

def compute_quality_metrics(assignments: Dict[str, Dict[int, str]],
                            participants: Dict[str, 'Participant'],
                            weights: Dict[int, float],
                            topk: int,
                            num_assign: int) -> Dict[str, Any]:
    happy_mean, per_user_happy = compute_happy_index(assignments, {k: v.wishes for k, v in participants.items()}, weights, topk)

    topk_hits_hist = Counter()
    top1_hit = 0
    zero_topk = 0
    for pid, slots in assignments.items():
        wishes_k = participants[pid].wishes[:topk]
        hits = sum(1 for wid in slots.values() if wid in wishes_k)
        topk_hits_hist[hits] += 1
        if wishes_k and any(wid == wishes_k[0] for wid in slots.values()):
            top1_hit += 1
        if hits == 0:
            zero_topk += 1

    diss = [max(0.0, 1.0 - per_user_happy.get(pid, 0.0)) for pid in participants.keys()]
    gini_diss = _gini(diss) if diss else 0.0

    if per_user_happy:
        sorted_vals = sorted(per_user_happy.values())
        median_val = sorted_vals[len(sorted_vals)//2]
        min_val = min(sorted_vals)
        jain = _jain(list(per_user_happy.values()))
    else:
        median_val = min_val = jain = 0.0

    n_part = max(1, len(participants))
    return {
        "happy_mean": round(happy_mean, 4),
        "min_user_happy": round(min_val, 4),
        "median_user_happy": round(median_val, 4),
        "gini_dissatisfaction": round(gini_diss, 4),
        "jain_index": round(jain, 4),
        "top1_coverage": round(top1_hit / n_part, 4),
        "no_topk_rate": round(zero_topk / n_part, 4),
        "topk_coverage_hist": dict(topk_hits_hist),
    }

# --------------------------------------------------------------------------------------
# Strategy 1: Greedy
# --------------------------------------------------------------------------------------
def run_matching(participants: Dict[str, 'Participant'],
                 workshops: Dict[str, 'Workshop'],
                 cfg: Dict[str, Any]) -> Tuple[Dict[str, Dict[int, str]], Dict[str, Any]]:
    num_assign = cfg["num_assign"]
    num_wishes = cfg["num_wishes"]
    topk = num_assign if cfg.get("topk_equals_slots", True) else min(num_assign, num_wishes)
    seed = (cfg.get("seed") or "").strip()
    rng = random.Random(seed or None)

    cap_per_slot: Dict[int, Dict[str, int]] = {s: {w.id: w.capacity for w in workshops.values()} for s in range(1, num_assign + 1)}
    pids = list(participants.keys()); rng.shuffle(pids)
    assignments: Dict[str, Dict[int, str]] = {pid: {} for pid in pids}

    # (optional) Slicing aus Defaults nicht mehr aus Node — hier deaktiviert
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
                assignments[pid][s] = wid
                cap_per_slot[s][wid] -= 1
                used_per_slot[s][wid] += 1
                break

    # Weights vorbereiten
    weights = cfg.get("weights") or SERVICE_DEFAULTS["weights"]
    weights = _extend_weights({int(k): float(v) for k, v in weights.items()}, topk)

    quality = compute_quality_metrics(assignments, participants, weights, topk, num_assign)
    happy = quality["happy_mean"]

    per_slot_counts = {s: len([1 for pid in pids if assignments[pid].get(s)]) for s in range(1, num_assign + 1)}
    assign_dist = Counter([len(assignments[pid]) for pid in pids])
    cap_total = sum(w.capacity for w in workshops.values()) * num_assign
    cap_remaining = sum(sum(max(0, x) for x in cap_per_slot[s].values()) for s in cap_per_slot)

    filler_count = 0
    for pid in pids:
        for s_idx, wid in assignments[pid].items():
            if wid not in participants[pid].wishes[:num_wishes]:
                filler_count += 1

    unfilled = []
    for wid, w in workshops.items():
        remaining_total = sum(cap_per_slot[s][wid] for s in cap_per_slot)
        if remaining_total > 0:
            unfilled.append({"id": wid, "title": w.title, "remaining": remaining_total})

    per_priority_fulfilled = dict({i: 0 for i in range(1, num_wishes + 1)})
    for pid in pids:
        wish_list = participants[pid].wishes[:num_wishes]
        for s_idx, wid in assignments[pid].items():
            if wid in wish_list:
                prio = wish_list.index(wid) + 1
                per_priority_fulfilled[prio] = per_priority_fulfilled.get(prio, 0) + 1

    summary = {
        "seed": seed or "random",
        "participants_total": len(pids),
        "participants_no_wishes": len([1 for p in participants.values() if not p.wishes]),
        "assignments_total": sum(len(v) for v in assignments.values()),
        "target_assignments_total": len(pids) * num_assign,
        "assignment_distribution": dict(assign_dist),
        "per_slot_assigned_counts": per_slot_counts,
        "per_priority_fulfilled": per_priority_fulfilled,
        "capacity_total": cap_total,
        "capacity_remaining_total": cap_remaining,
        "filler_assignments": filler_count,
        "unfilled_workshops_count": len(unfilled),
        "warning_capacity_deficit": max(0, (len(pids) * num_assign) - cap_total),
        "all_filled_to_slots": all(len(assignments[pid]) == num_assign for pid in pids),
        "happy_index": round(happy, 4),
        "min_user_happy": quality["min_user_happy"],
        "median_user_happy": quality["median_user_happy"],
        "gini_dissatisfaction": quality["gini_dissatisfaction"],
        "jain_index": quality["jain_index"],
        "top1_coverage": quality["top1_coverage"],
        "no_topk_rate": quality["no_topk_rate"],
        "topk_coverage_hist": quality["topk_coverage_hist"],
    }
    meta = {"unfilled_workshops": unfilled}
    return assignments, {"summary": summary, **meta}

# --------------------------------------------------------------------------------------
# Strategy 2: Fair (Mehr-Runden + Deckel)
# --------------------------------------------------------------------------------------
def run_matching_fair(participants: Dict[str, 'Participant'],
                      workshops: Dict[str, 'Workshop'],
                      cfg: Dict[str, Any]) -> Tuple[Dict[str, Dict[int, str]], Dict[str, Any]]:
    num_assign = cfg["num_assign"]
    num_wishes = cfg["num_wishes"]
    topk = num_assign if cfg.get("topk_equals_slots", True) else min(num_assign, num_wishes)

    round_cap_pct = int(cfg.get("round_cap_pct", SERVICE_DEFAULTS["round_cap_pct"]))
    alpha = float(cfg.get("alpha_fairness", SERVICE_DEFAULTS["alpha_fairness"]))
    seeds = int(cfg.get("seeds", SERVICE_DEFAULTS["seeds"]))
    objective = (cfg.get("objective") or SERVICE_DEFAULTS["objective"]).strip()
    # Weights bauen
    weights = cfg.get("weights")
    if not weights:
        wm = (cfg.get("weights_mode") or "").strip().lower()
        if wm:
            weights = _gen_weights(wm, num_wishes, base=float(cfg.get("weights_base", 0.8)), linear_min=float(cfg.get("linear_min", 0.2)))
        else:
            weights = SERVICE_DEFAULTS["weights"]

    pop_counter = Counter()
    for p in participants.values():
        for wid in p.wishes[:topk]:
            pop_counter[wid] += 1
    renner_cut = max(1, int(0.2 * max(1, len(workshops))))
    renner_ids = {wid for wid, _ in pop_counter.most_common(renner_cut)}
    pids_all = list(participants.keys())

    def single_run(seed_val: Optional[str]) -> Tuple[Dict[str, Dict[int, str]], Dict[str, Any]]:
        rng = random.Random((seed_val or "").strip() or None)
        pids = pids_all[:]; rng.shuffle(pids)
        cap_per_slot: Dict[int, Dict[str, int]] = {s: {w.id: w.capacity for w in workshops.values()} for s in range(1, num_assign + 1)}
        used_per_slot: Dict[int, Counter] = {s: Counter() for s in range(1, num_assign + 1)}
        assignments: Dict[str, Dict[int, str]] = {pid: {} for pid in pids}

        # Runde 1: Top-k mit Deckel (Renner)
        for s in range(1, num_assign + 1):
            deckel: Dict[str, Optional[int]] = {}
            for w in workshops.values():
                deckel[w.id] = int(round((round_cap_pct / 100.0) * w.capacity)) if w.id in renner_ids else None
            for pid in pids:
                if s in assignments[pid]:
                    continue
                for wid in participants[pid].wishes[:topk]:
                    if wid in assignments[pid].values():
                        continue
                    if cap_per_slot[s].get(wid, 0) <= 0:
                        continue
                    d = deckel.get(wid)
                    if (wid in renner_ids) and (d is not None) and (used_per_slot[s][wid] >= d):
                        continue
                    assignments[pid][s] = wid
                    cap_per_slot[s][wid] -= 1
                    used_per_slot[s][wid] += 1
                    break

        # Runde 2: Benachteiligte zuerst
        def underserved_key(pid: str) -> Tuple[int, float, float]:
            got = len(assignments[pid])
            wishes_k = participants[pid].wishes[:topk]
            hits = sum(1 for wid in assignments[pid].values() if wid in wishes_k)
            _, per_user_happy = compute_happy_index(assignments, {k: v.wishes for k, v in participants.items()}, _extend_weights({int(k):float(v) for k,v in weights.items()}, topk), topk)
            unhappy = 1.0 - per_user_happy.get(pid, 0.0)
            return (got, -float(hits), unhappy + alpha * (num_assign - got))

        for s in range(1, num_assign + 1):
            order = sorted(pids, key=underserved_key)
            for pid in order:
                if s in assignments[pid]:
                    continue
                placed = False
                for wid in participants[pid].wishes[:topk]:
                    if wid in assignments[pid].values():
                        continue
                    if cap_per_slot[s].get(wid, 0) <= 0:
                        continue
                    assignments[pid][s] = wid
                    cap_per_slot[s][wid] -= 1
                    placed = True
                    break
                if placed:
                    continue
                for wid in participants[pid].wishes[:num_wishes]:
                    if wid in assignments[pid].values():
                        continue
                    if cap_per_slot[s].get(wid, 0) <= 0:
                        continue
                    assignments[pid][s] = wid
                    cap_per_slot[s][wid] -= 1
                    break

        # Runde 3: Auffüllen
        for s in range(1, num_assign + 1):
            order = sorted(pids, key=lambda pid: (len(assignments[pid]), random.random()))
            for pid in order:
                if s in assignments[pid]:
                    continue
                for wid, rest in cap_per_slot[s].items():
                    if rest <= 0:
                        continue
                    if wid in assignments[pid].values():
                        continue
                    assignments[pid][s] = wid
                    cap_per_slot[s][wid] -= 1
                    break

        # Metriken
        w_full = _extend_weights({int(k): float(v) for k, v in weights.items()}, topk)
        quality = compute_quality_metrics(assignments, participants, w_full, topk, num_assign)
        per_slot_counts = {s: len([1 for pid in pids_all if assignments[pid].get(s)]) for s in range(1, num_assign + 1)}
        assign_dist = Counter([len(assignments[pid]) for pid in pids_all])
        cap_total = sum(w.capacity for w in workshops.values()) * num_assign
        cap_remaining = sum(sum(max(0, x) for x in cap_per_slot[s].values()) for s in cap_per_slot)

        filler_count = 0
        for pid in pids_all:
            for s_idx, wid in assignments[pid].items():
                if wid not in participants[pid].wishes[:num_wishes]:
                    filler_count += 1

        unfilled = []
        for wid, w in workshops.items():
            remaining_total = sum(cap_per_slot[s][wid] for s in cap_per_slot)
            if remaining_total > 0:
                unfilled.append({"id": wid, "title": w.title, "remaining": remaining_total})

        per_priority_fulfilled = dict({i: 0 for i in range(1, num_wishes + 1)})
        for pid in pids_all:
            wish_list = participants[pid].wishes[:num_wishes]
            for s_idx, wid in assignments[pid].items():
                if wid in wish_list:
                    prio = wish_list.index(wid) + 1
                    per_priority_fulfilled[prio] = per_priority_fulfilled.get(prio, 0) + 1

        summary = {
            "seed": seed_val or "random",
            "participants_total": len(pids_all),
            "participants_no_wishes": len([1 for p in participants.values() if not p.wishes]),
            "assignments_total": sum(len(v) for v in assignments.values()),
            "target_assignments_total": len(pids_all) * num_assign,
            "assignment_distribution": dict(assign_dist),
            "per_slot_assigned_counts": per_slot_counts,
            "per_priority_fulfilled": per_priority_fulfilled,
            "capacity_total": cap_total,
            "capacity_remaining_total": cap_remaining,
            "filler_assignments": filler_count,
            "unfilled_workshops_count": len(unfilled),
            "warning_capacity_deficit": max(0, (len(pids_all) * num_assign) - cap_total),
            "all_filled_to_slots": all(len(assignments[pid]) == num_assign for pid in pids_all),
            "happy_index": quality["happy_mean"],
            "min_user_happy": quality["min_user_happy"],
            "median_user_happy": quality["median_user_happy"],
            "gini_dissatisfaction": quality["gini_dissatisfaction"],
            "jain_index": quality["jain_index"],
            "top1_coverage": quality["top1_coverage"],
            "no_topk_rate": quality["no_topk_rate"],
            "topk_coverage_hist": quality["topk_coverage_hist"],
            "objective": objective,
        }
        meta = {"unfilled_workshops": unfilled}
        return assignments, {"summary": summary, **meta}

    # Seeds vorbereiten
    base_seed = (cfg.get("seed") or "").strip()
    seed_list: List[Optional[str]] = []
    if base_seed:
        seed_list.append(base_seed)
    want = max(1, seeds - len(seed_list))
    for i in range(want):
        seed_list.append(f"AUTOSEED_{i+1}")

    best = None
    best_key = None
    for sv in seed_list:
        cand = single_run(sv)
        s = cand[1]["summary"]
        if objective == "fair_maxmin":
            key = (-round(s.get("min_user_happy", 0.0), 4),
                   -round(s.get("median_user_happy", 0.0), 4),
                   round(s.get("gini_dissatisfaction", 1.0), 4))
        elif objective == "leximin":
            key = (-round(s.get("min_user_happy", 0.0), 4),
                   round(s.get("gini_dissatisfaction", 1.0), 4),
                   -round(s.get("happy_index", 0.0), 4))
        else:  # happy_mean
            key = (-round(s.get("happy_index", 0.0), 4),
                   -round(s.get("median_user_happy", 0.0), 4),
                   round(s.get("gini_dissatisfaction", 1.0), 4))
        if (best_key is None) or (key < best_key):
            best_key = key
            best = cand
    return best

# --------------------------------------------------------------------------------------
# Strategy 3: Solver (leximin-heuristic)
# --------------------------------------------------------------------------------------
def run_matching_solver(participants: Dict[str, 'Participant'],
                        workshops: Dict[str, 'Workshop'],
                        cfg: Dict[str, Any]) -> Tuple[Dict[str, Dict[int, str]], Dict[str, Any]]:
    num_assign = cfg["num_assign"]
    num_wishes = cfg["num_wishes"]
    topk = num_assign if cfg.get("topk_equals_slots", True) else min(num_assign, num_wishes)
    seed = (cfg.get("seed") or "").strip()
    rng = random.Random(seed or None)

    # Weights bauen (wie bei fair)
    weights = cfg.get("weights")
    if not weights:
        wm = (cfg.get("weights_mode") or "").strip().lower()
        if wm:
            weights = _gen_weights(wm, num_wishes, base=float(cfg.get("weights_base", 0.8)), linear_min=float(cfg.get("linear_min", 0.2)))
        else:
            weights = SERVICE_DEFAULTS["weights"]
    weights = _extend_weights({int(k): float(v) for k, v in weights.items()}, topk)

    cap_per_slot: Dict[int, Dict[str, int]] = {s: {w.id: w.capacity for w in workshops.values()} for s in range(1, num_assign + 1)}
    pids = list(participants.keys()); rng.shuffle(pids)
    assignments: Dict[str, Dict[int, str]] = {pid: {} for pid in pids}

    def best_available_for(pid: str, s: int) -> Optional[str]:
        # 1) Top-k Wünsche zuerst
        for wid in participants[pid].wishes[:topk]:
            if wid in assignments[pid].values():
                continue
            if cap_per_slot[s].get(wid, 0) > 0:
                return wid
        # 2) restliche Wünsche bis num_wishes
        for wid in participants[pid].wishes[:num_wishes]:
            if wid in assignments[pid].values():
                continue
            if cap_per_slot[s].get(wid, 0) > 0:
                return wid
        # 3) Fallback
        for wid, rest in cap_per_slot[s].items():
            if rest > 0 and wid not in assignments[pid].values():
                return wid
        return None

    def current_per_user_happy() -> Dict[str, float]:
        _, pu = compute_happy_index(assignments, {k: v.wishes for k, v in participants.items()}, weights, topk)
        return pu

    for s in range(1, num_assign + 1):
        while True:
            needed = [pid for pid in pids if s not in assignments[pid]]
            if not needed:
                break
            per_user_h = current_per_user_happy()
            needed.sort(key=lambda pid: (per_user_h.get(pid, 0.0), len(assignments[pid]), rng.random()))
            progress = 0
            for pid in needed:
                wid = best_available_for(pid, s)
                if wid is None:
                    continue
                assignments[pid][s] = wid
                cap_per_slot[s][wid] -= 1
                progress += 1
            if progress == 0:
                break
        rng.shuffle(pids)

    quality = compute_quality_metrics(assignments, participants, weights, topk, num_assign)
    per_slot_counts = {s: len([1 for pid in pids if assignments[pid].get(s)]) for s in range(1, num_assign + 1)}
    assign_dist = Counter([len(assignments[pid]) for pid in pids])
    cap_total = sum(w.capacity for w in workshops.values()) * num_assign
    cap_remaining = sum(sum(max(0, x) for x in cap_per_slot[s].values()) for s in cap_per_slot)

    filler_count = 0
    for pid in pids:
        for s_idx, wid in assignments[pid].items():
            if wid not in participants[pid].wishes[:num_wishes]:
                filler_count += 1

    unfilled = []
    for wid, w in workshops.items():
        remaining_total = sum(cap_per_slot[s][wid] for s in cap_per_slot)
        if remaining_total > 0:
            unfilled.append({"id": wid, "title": w.title, "remaining": remaining_total})

    per_priority_fulfilled = dict({i: 0 for i in range(1, num_wishes + 1)})
    for pid in pids:
        wish_list = participants[pid].wishes[:num_wishes]
        for s_idx, wid in assignments[pid].items():
            if wid in wish_list:
                prio = wish_list.index(wid) + 1
                per_priority_fulfilled[prio] = per_priority_fulfilled.get(prio, 0) + 1

    summary = {
        "seed": seed or "random",
        "participants_total": len(pids),
        "participants_no_wishes": len([1 for p in participants.values() if not p.wishes]),
        "assignments_total": sum(len(v) for v in assignments.values()),
        "target_assignments_total": len(pids) * num_assign,
        "assignment_distribution": dict(assign_dist),
        "per_slot_assigned_counts": per_slot_counts,
        "per_priority_fulfilled": per_priority_fulfilled,
        "capacity_total": cap_total,
        "capacity_remaining_total": cap_remaining,
        "filler_assignments": filler_count,
        "unfilled_workshops_count": len(unfilled),
        "warning_capacity_deficit": max(0, (len(pids) * num_assign) - cap_total),
        "all_filled_to_slots": all(len(assignments[pid]) == num_assign for pid in pids),
        "happy_index": quality["happy_mean"],
        "min_user_happy": quality["min_user_happy"],
        "median_user_happy": quality["median_user_happy"],
        "gini_dissatisfaction": quality["gini_dissatisfaction"],
        "jain_index": quality["jain_index"],
        "top1_coverage": quality["top1_coverage"],
        "no_topk_rate": quality["no_topk_rate"],
        "topk_coverage_hist": quality["topk_coverage_hist"],
        "objective": (cfg.get("objective") or "leximin").strip().lower(),
    }
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

def _collect_body_overrides() -> Dict[str, Any]:
    try:
        body = request.get_json(silent=True) or {}
    except Exception:
        body = {}
    allowed = {
        "strategy", "objective", "round_cap_pct", "alpha_fairness", "seeds", "seed",
        "weights", "weights_mode", "weights_base", "linear_min",
        "num_assign", "num_wishes", "topk_equals_slots"
    }
    return {k: v for k, v in (body or {}).items() if k in allowed and v is not None}

def _apply_weights_generation(cfg: Dict[str, Any]) -> None:
    # Falls weights fehlen, aber weights_mode gesetzt ist → automatisch generieren
    if not cfg.get("weights"):
        wm = (cfg.get("weights_mode") or "").strip().lower()
        if wm:
            gen = _gen_weights(wm, int(cfg["num_wishes"]),
                               base=float(cfg.get("weights_base", 0.8)),
                               linear_min=float(cfg.get("linear_min", 0.2)))
            if gen:
                cfg["weights"] = gen

@app.post("/matching/dry-run")
def dry_run():
    cfg = load_matching_config()
    overrides = _collect_body_overrides()
    cfg.update(overrides or {})
    # Auto-Weights ggf. erzeugen
    _apply_weights_generation(cfg)

    workshops = load_workshops()
    participants = load_participants_and_wishes(cfg["num_wishes"])

    strategy = (cfg.get("strategy") or SERVICE_DEFAULTS["strategy"]).strip().lower()
    if strategy == "fair":
        assignments, meta = run_matching_fair(participants, workshops, cfg)
    elif strategy == "solver":
        assignments, meta = run_matching_solver(participants, workshops, cfg)
    else:
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
        "strategy": strategy,
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
