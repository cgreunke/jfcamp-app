#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
JF Startercamp – Matching Service (kanonisches CP-SAT-Matching)

Node (persistente Basics):
- field_num_wuensche         → cfg["num_wishes"]
- field_num_zuteilung/field_zuteilung → cfg["num_assign"]
- field_slot_start, field_slot_end    → nur informativ (nicht für Matching nötig)

Service-Defaults (ändere bei Bedarf unten in SERVICE_DEFAULTS oder via ENV):
- kanonische Wunschgewichte und interne Solver-Zeitbudgets

Ad-hoc Overrides (pro Request-Body bei /matching/dry-run):
- num_assign, num_wishes (nur für technische Tests; produktiv kommt beides aus matching_config)
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
from ortools.sat.python import cp_model
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
    # Produktiv gibt es genau eine Matching-Strategie: CP-SAT.
    "strategy": "optimized",
    "allow_fillers": True,
    "topk_equals_slots": True,
    "weights": {1: 1.0, 2: 0.8, 3: 0.6, 4: 0.4, 5: 0.2},

    # Legacy-Werte bleiben intern erhalten, solange die alten Funktionen
    # noch für Regressionstests im Modul liegen. Die API nutzt sie nicht.
    "objective": "fair_maxmin",
    "round_cap_pct": 50,
    "alpha_fairness": 0.35,
    "seeds": 12,

    # Interne Schutzgrenzen. Diese sind bewusst keine Drupal-UI-Regler.
    # Summe: maximal 300 Sekunden Solver-Zeit pro Run.
    "solver_stage_limits": {
        1: 120.0,  # möglichst viele Wunsch-Zuteilungen
        2: 60.0,   # möglichst viele Teilnehmende vollständig versorgen
        3: 45.0,   # Top-k-Wünsche
        4: 45.0,   # gewichtete Wunschqualität
        5: 30.0,   # Fairness
    },
    "filler_solver_limit": 60.0,
    "slot_solver_limit": 60.0,
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
            order = sorted(pids, key=lambda pid: (len(assignments[pid]), rng.random()))
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
            key = (
                -int(s.get("assignments_total", 0)),
                -round(s.get("min_user_happy", 0.0), 4),
                -round(s.get("median_user_happy", 0.0), 4),
                round(s.get("gini_dissatisfaction", 1.0), 4),
            )
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
# VALIDATE MATCHING ASSIGNMENTS
# --------------------------------------------------------------------------------------


def validate_matching_assignments(
    assignments: Dict[str, Dict[int, str]],
    participants: Dict[str, 'Participant'],
    workshops: Dict[str, 'Workshop'],
    cfg: Dict[str, Any],
    *,
    allow_fillers: bool = False,
) -> None:
    """
    Validiert ein fertiges Matching unabhängig vom Solver-Modell.

    Wunschfremde Zuteilungen sind nur erlaubt, wenn ``allow_fillers=True``.
    Das kanonische Matching nutzt diese Möglichkeit ausschließlich als
    Fallback, damit bei ausreichender Gesamtkapazität alle Slots besetzt
    werden können.

    Raises:
        ValueError: wenn mindestens ein Hard Constraint verletzt ist.
    """
    num_assign = int(cfg["num_assign"])
    num_wishes = int(cfg["num_wishes"])

    errors: List[str] = []

    for pid in assignments:
        if pid not in participants:
            errors.append(
                f"Assignment enthält unbekannten Teilnehmer {pid}"
            )

    used_per_workshop_slot: Counter = Counter()

    for pid, participant in participants.items():
        slot_map = assignments.get(pid, {})

        if len(slot_map) > num_assign:
            errors.append(
                f"Teilnehmer {pid} hat {len(slot_map)} Zuteilungen, "
                f"erlaubt sind höchstens {num_assign}"
            )

        assigned_workshops = list(slot_map.values())

        if len(assigned_workshops) != len(set(assigned_workshops)):
            errors.append(
                f"Teilnehmer {pid} hat denselben Workshop mehrfach"
            )

        allowed_wishes = set(participant.wishes[:num_wishes])

        for slot, wid in slot_map.items():
            if not isinstance(slot, int) or not 1 <= slot <= num_assign:
                errors.append(
                    f"Teilnehmer {pid} hat ungültigen Slot {slot}"
                )

            if wid not in workshops:
                errors.append(
                    f"Teilnehmer {pid} ist unbekanntem Workshop "
                    f"{wid} zugeteilt"
                )
                continue

            if not allow_fillers and wid not in allowed_wishes:
                errors.append(
                    f"Teilnehmer {pid} ist Workshop {wid} zugeteilt, "
                    f"obwohl dieser nicht unter den ersten "
                    f"{num_wishes} Wünschen liegt"
                )

            used_per_workshop_slot[(wid, slot)] += 1

    for (wid, slot), used in used_per_workshop_slot.items():
        if wid not in workshops:
            continue

        capacity = max(0, int(workshops[wid].capacity))

        if used > capacity:
            errors.append(
                f"Workshop {wid} überschreitet in Slot {slot} "
                f"die Kapazität: {used}/{capacity}"
            )

    if errors:
        raise ValueError(
            "Ungültiges Matching-Ergebnis: "
            + "; ".join(errors)
        )


# --------------------------------------------------------------------------------------
# OPTIMIZED MATCHING
# --------------------------------------------------------------------------------------

def run_matching_optimized(
    participants: Dict[str, 'Participant'],
    workshops: Dict[str, 'Workshop'],
    cfg: Dict[str, Any],
) -> Tuple[Dict[str, Dict[int, str]], Dict[str, Any]]:
    """
    Kanonisches CP-SAT-Matching in drei klar getrennten Phasen.

    Phase A – Workshop-Auswahl ohne Slots
      Zuerst wird nur entschieden, welche Workshops eine Person bekommt.
      Dadurch verschwindet die große Slot-Symmetrie aus der eigentlichen
      Qualitätsoptimierung.

    Phase B – Vervollständigung
      Offene Plätze werden erst nach dem Wunsch-Matching ergänzt. Dabei
      werden noch mögliche Wünsche bevorzugt; echte Nicht-Wünsche sind
      nur der letzte Fallback.

    Phase C – Slot-Verteilung
      Erst die fertige Workshop-Auswahl wird anschließend auf konkrete
      Slots verteilt. Dabei gelten weiterhin alle Kapazitäten pro Slot.

    Lexikographische Wunschziele:
      1. möglichst viele Wunsch-Zuteilungen
      2. möglichst viele vollständig über Wünsche versorgte Teilnehmende
      3. möglichst viele Top-k-Wünsche
      4. bestmögliche gewichtete Wunschqualität
      5. möglichst faire Verteilung der Wunschqualität
    """
    num_assign = int(cfg["num_assign"])
    num_wishes = int(cfg["num_wishes"])

    if num_assign <= 0:
        raise ValueError("num_assign muss größer als 0 sein")
    if num_wishes <= 0:
        raise ValueError("num_wishes muss größer als 0 sein")

    topk = min(num_assign, num_wishes)
    allow_fillers = bool(cfg.get("allow_fillers", True))

    participant_ids = sorted(participants.keys())
    workshop_ids = sorted(workshops.keys())
    active_workshop_ids = [
        wid
        for wid in workshop_ids
        if max(0, int(workshops[wid].capacity)) > 0
    ]
    slots = list(range(1, num_assign + 1))

    allowed_wishes: Dict[str, List[str]] = {}
    wish_rank: Dict[Tuple[str, str], int] = {}

    for pid in participant_ids:
        seen = set()
        valid: List[str] = []

        for rank, wid in enumerate(
            participants[pid].wishes[:num_wishes],
            start=1,
        ):
            if wid in seen:
                continue
            seen.add(wid)

            if wid not in workshops:
                raise ValueError(
                    f"Teilnehmer {pid} referenziert unbekannten Workshop {wid}"
                )

            if max(0, int(workshops[wid].capacity)) <= 0:
                continue

            valid.append(wid)
            wish_rank[(pid, wid)] = rank

        allowed_wishes[pid] = valid

    weights = cfg.get("weights") or SERVICE_DEFAULTS["weights"]
    normalized_weights = _extend_weights(
        {int(k): float(v) for k, v in weights.items()},
        num_wishes,
    )
    rank_weight_int = {
        rank: max(1, int(round(normalized_weights[rank] * 1000)))
        for rank in range(1, num_wishes + 1)
    }

    stage_limits_raw = (
        cfg.get("solver_stage_limits")
        or SERVICE_DEFAULTS["solver_stage_limits"]
    )
    stage_limits = {
        int(k): float(v)
        for k, v in stage_limits_raw.items()
    }

    timing_started = time.perf_counter()
    stage_timings: Dict[str, float] = {}
    stage_statuses: Dict[str, str] = {}
    stage_proven_optimal: Dict[str, bool] = {}

    # ------------------------------------------------------------------
    # Phase A: Workshop-Auswahl ohne Slots.
    # ------------------------------------------------------------------
    model = cp_model.CpModel()
    y: Dict[Tuple[str, str], Any] = {}

    for pid in participant_ids:
        for wid in allowed_wishes[pid]:
            y[(pid, wid)] = model.NewBoolVar(f"wish_{pid}_{wid}")

    for pid in participant_ids:
        vars_for_participant = [
            y[(pid, wid)]
            for wid in allowed_wishes[pid]
        ]
        if vars_for_participant:
            model.Add(sum(vars_for_participant) <= num_assign)

    # Ein Workshop mit Kapazität C kann über num_assign Slots insgesamt
    # höchstens C * num_assign Personen aufnehmen. Die konkrete Verteilung
    # auf Slots erfolgt erst in Phase C.
    for wid in active_workshop_ids:
        vars_for_workshop = [
            y[(pid, wid)]
            for pid in participant_ids
            if (pid, wid) in y
        ]
        if vars_for_workshop:
            model.Add(
                sum(vars_for_workshop)
                <= max(0, int(workshops[wid].capacity)) * num_assign
            )

    full_participant_vars: Dict[str, Any] = {}
    for pid in participant_ids:
        participant_vars = [
            y[(pid, wid)]
            for wid in allowed_wishes[pid]
        ]
        full_var = model.NewBoolVar(f"full_wishes_{pid}")
        full_participant_vars[pid] = full_var

        if participant_vars:
            assigned_count = sum(participant_vars)
            model.Add(assigned_count == num_assign).OnlyEnforceIf(full_var)
            model.Add(assigned_count <= num_assign - 1).OnlyEnforceIf(
                full_var.Not()
            )
        else:
            model.Add(full_var == 0)

    solver = cp_model.CpSolver()
    solver.parameters.num_search_workers = 1
    solver.parameters.random_seed = 0

    class StopAtObjective(cp_model.CpSolverSolutionCallback):
        def __init__(self, target: Optional[int]) -> None:
            super().__init__()
            self.target = target

        def on_solution_callback(self) -> None:
            if self.target is None:
                return
            if int(round(self.ObjectiveValue())) >= int(self.target):
                self.StopSearch()

    def clear_hints() -> None:
        try:
            model.ClearHints()
        except AttributeError:
            try:
                model.clear_hints()
            except AttributeError:
                pass

    def set_solution_hint(values: Dict[Tuple[str, str], int]) -> None:
        if not values:
            return
        clear_hints()
        for key, value in values.items():
            var = y.get(key)
            if var is not None:
                try:
                    model.AddHint(var, int(value))
                except AttributeError:
                    model.add_hint(var, int(value))

    def capture_y_solution() -> Dict[Tuple[str, str], int]:
        return {
            key: int(solver.Value(var))
            for key, var in y.items()
        }

    best_y_solution: Dict[Tuple[str, str], int] = {}

    def solve_stage(
        stage: int,
        label: str,
        *,
        known_upper_bound: Optional[int] = None,
        require_solution: bool = False,
    ) -> Tuple[bool, Optional[int]]:
        nonlocal best_y_solution

        if best_y_solution:
            set_solution_hint(best_y_solution)

        solver.parameters.max_time_in_seconds = float(
            stage_limits.get(stage, 60.0)
        )

        print(
            f"[matching] priority {stage}/5 started: {label}",
            flush=True,
        )
        started = time.perf_counter()
        status = solver.Solve(
            model,
            StopAtObjective(known_upper_bound),
        )
        elapsed = time.perf_counter() - started

        stage_timings[f"priority_{stage}_seconds"] = elapsed
        status_name = solver.StatusName(status)
        stage_statuses[f"priority_{stage}_status"] = status_name

        if status in (cp_model.OPTIMAL, cp_model.FEASIBLE):
            objective_value = int(round(solver.ObjectiveValue()))
            best_y_solution = capture_y_solution()
            proven = (
                status == cp_model.OPTIMAL
                or (
                    known_upper_bound is not None
                    and objective_value >= int(known_upper_bound)
                )
            )
            stage_proven_optimal[
                f"priority_{stage}_proven_optimal"
            ] = proven

            print(
                f"[matching] priority {stage}/5 finished in "
                f"{elapsed:.3f}s (status={status_name}, "
                f"value={objective_value}, proven_optimal={proven})",
                flush=True,
            )
            return True, objective_value

        stage_proven_optimal[
            f"priority_{stage}_proven_optimal"
        ] = False

        if require_solution and not best_y_solution:
            raise RuntimeError(
                f"CP-SAT konnte Priorität {stage} nicht lösen "
                f"(Status: {status_name})."
            )

        print(
            f"[matching] priority {stage}/5 stopped after "
            f"{elapsed:.3f}s (status={status_name}); "
            "keeping previous valid solution",
            flush=True,
        )
        return False, None

    all_wish_vars = list(y.values())

    # Priorität 1: maximale Zahl an Wunsch-Zuteilungen.
    model.Maximize(sum(all_wish_vars) if all_wish_vars else 0)
    p1_upper_bound = min(
        sum(
            min(num_assign, len(allowed_wishes[pid]))
            for pid in participant_ids
        ),
        sum(
            max(0, int(workshops[wid].capacity)) * num_assign
            for wid in active_workshop_ids
        ),
    )
    ok, p1_value = solve_stage(
        1,
        "maximize wish assignments (slot-independent)",
        known_upper_bound=p1_upper_bound,
        require_solution=True,
    )
    best_wish_assignments_total = int(p1_value or 0)
    if all_wish_vars:
        model.Add(sum(all_wish_vars) == best_wish_assignments_total)

    target_assignments_total = len(participant_ids) * num_assign

    # Priorität 2: möglichst viele Personen ausschließlich über Wünsche voll.
    if best_wish_assignments_total == target_assignments_total:
        best_fully_by_wishes = len(participant_ids)
        stage_timings["priority_2_seconds"] = 0.0
        stage_statuses["priority_2_status"] = "SKIPPED"
        stage_proven_optimal["priority_2_proven_optimal"] = True
        model.Add(
            sum(full_participant_vars.values()) == best_fully_by_wishes
        )
        print(
            "[matching] priority 2/5 skipped: all participants already "
            "fully assigned by wishes",
            flush=True,
        )
    else:
        model.Maximize(sum(full_participant_vars.values()))
        p2_upper_bound = min(
            len(participant_ids),
            best_wish_assignments_total // num_assign,
            sum(
                1
                for pid in participant_ids
                if len(allowed_wishes[pid]) >= num_assign
            ),
        )
        ok, p2_value = solve_stage(
            2,
            "maximize participants fully assigned by wishes",
            known_upper_bound=p2_upper_bound,
        )

        if ok:
            best_fully_by_wishes = int(p2_value or 0)
        else:
            best_fully_by_wishes = sum(
                1
                for pid in participant_ids
                if sum(
                    best_y_solution.get((pid, wid), 0)
                    for wid in allowed_wishes[pid]
                ) == num_assign
            )
            print(
                "[matching] priority 2/5 continuing with previous "
                f"valid solution (fully_assigned={best_fully_by_wishes})",
                flush=True,
            )

        model.Add(
            sum(full_participant_vars.values()) == best_fully_by_wishes
        )

    # Priorität 3: Top-k.
    topk_terms = [
        y[(pid, wid)]
        for pid in participant_ids
        for wid in allowed_wishes[pid]
        if wish_rank[(pid, wid)] <= topk
    ]
    model.Maximize(sum(topk_terms) if topk_terms else 0)
    p3_upper_bound = min(
        best_wish_assignments_total,
        sum(
            min(
                num_assign,
                sum(
                    1
                    for wid in allowed_wishes[pid]
                    if wish_rank[(pid, wid)] <= topk
                ),
            )
            for pid in participant_ids
        ),
    )
    ok, p3_value = solve_stage(
        3,
        "maximize top-k wishes",
        known_upper_bound=p3_upper_bound,
    )

    if ok:
        best_topk_hits = int(p3_value or 0)
    else:
        best_topk_hits = sum(
            value
            for (pid, wid), value in best_y_solution.items()
            if wish_rank[(pid, wid)] <= topk
        )
        print(
            "[matching] priority 3/5 continuing with previous "
            f"valid solution (topk_hits={best_topk_hits})",
            flush=True,
        )

    if topk_terms:
        model.Add(sum(topk_terms) == best_topk_hits)

    # Priorität 4: gewichtete Wunschqualität.
    weighted_terms = [
        rank_weight_int[wish_rank[(pid, wid)]] * y[(pid, wid)]
        for pid in participant_ids
        for wid in allowed_wishes[pid]
    ]
    model.Maximize(sum(weighted_terms) if weighted_terms else 0)
    p4_upper_bound = sum(
        sum(
            sorted(
                (
                    rank_weight_int[wish_rank[(pid, wid)]]
                    for wid in allowed_wishes[pid]
                ),
                reverse=True,
            )[:num_assign]
        )
        for pid in participant_ids
    )
    ok, p4_value = solve_stage(
        4,
        "maximize weighted wish quality",
        known_upper_bound=p4_upper_bound,
    )

    if ok:
        best_weighted_quality = int(p4_value or 0)
    else:
        best_weighted_quality = sum(
            rank_weight_int[wish_rank[(pid, wid)]] * value
            for (pid, wid), value in best_y_solution.items()
        )
        print(
            "[matching] priority 4/5 continuing with previous "
            f"valid solution (weighted_quality={best_weighted_quality})",
            flush=True,
        )

    if weighted_terms:
        model.Add(sum(weighted_terms) == best_weighted_quality)

    # Priorität 5: schlechteste individuelle Wunschqualität anheben.
    fairness_participant_ids = [
        pid for pid in participant_ids if allowed_wishes[pid]
    ]
    best_min_participant_quality = 0

    if fairness_participant_ids:
        participant_quality_vars: Dict[str, Any] = {}
        individual_quality_upper: Dict[str, int] = {}

        for pid in fairness_participant_ids:
            quality_terms = [
                rank_weight_int[wish_rank[(pid, wid)]] * y[(pid, wid)]
                for wid in allowed_wishes[pid]
            ]
            upper = sum(
                sorted(
                    (
                        rank_weight_int[wish_rank[(pid, wid)]]
                        for wid in allowed_wishes[pid]
                    ),
                    reverse=True,
                )[:num_assign]
            )
            individual_quality_upper[pid] = upper
            quality_var = model.NewIntVar(
                0,
                max(0, upper),
                f"quality_{pid}",
            )
            participant_quality_vars[pid] = quality_var
            model.Add(quality_var == sum(quality_terms))

        fairness_upper_bound = min(individual_quality_upper.values())
        min_participant_quality = model.NewIntVar(
            0,
            max(0, fairness_upper_bound),
            "min_participant_quality",
        )
        for quality_var in participant_quality_vars.values():
            model.Add(min_participant_quality <= quality_var)

        model.Maximize(min_participant_quality)
        ok, p5_value = solve_stage(
            5,
            "maximize minimum participant wish quality",
            known_upper_bound=fairness_upper_bound,
        )

        if ok:
            best_min_participant_quality = int(p5_value or 0)
        else:
            per_pid_quality = [
                sum(
                    rank_weight_int[wish_rank[(pid, wid)]]
                    * best_y_solution.get((pid, wid), 0)
                    for wid in allowed_wishes[pid]
                )
                for pid in fairness_participant_ids
            ]
            best_min_participant_quality = min(per_pid_quality or [0])
            print(
                "[matching] priority 5/5 keeping previous valid "
                f"solution (min_quality={best_min_participant_quality})",
                flush=True,
            )
    else:
        stage_timings["priority_5_seconds"] = 0.0
        stage_statuses["priority_5_status"] = "SKIPPED"
        stage_proven_optimal["priority_5_proven_optimal"] = True
        print(
            "[matching] priority 5/5 skipped: no valid wishes available",
            flush=True,
        )

    if not best_y_solution and y:
        best_y_solution = capture_y_solution()

    selected_by_participant: Dict[str, List[str]] = {
        pid: [] for pid in participant_ids
    }
    selected_source: Dict[Tuple[str, str], str] = {}

    for (pid, wid), value in best_y_solution.items():
        if value:
            selected_by_participant[pid].append(wid)
            selected_source[(pid, wid)] = "wish"

    for pid in participant_ids:
        selected_by_participant[pid].sort(
            key=lambda wid: (
                wish_rank.get((pid, wid), num_wishes + 1),
                wid,
            )
        )

    # ------------------------------------------------------------------
    # Phase B: offene Workshop-Auswahl ergänzen, noch ohne Slots.
    # ------------------------------------------------------------------
    completion_solver_seconds = 0.0
    completion_solver_status = "SKIPPED"
    completion_proven_optimal = True
    completion_added = 0

    missing_total = sum(
        max(0, num_assign - len(selected_by_participant[pid]))
        for pid in participant_ids
    )

    if allow_fillers and missing_total > 0 and active_workshop_ids:
        fill_model = cp_model.CpModel()
        f: Dict[Tuple[str, str], Any] = {}

        selected_workshop_counts = Counter(
            wid
            for pid in participant_ids
            for wid in selected_by_participant[pid]
        )

        for pid in participant_ids:
            already = set(selected_by_participant[pid])
            missing = max(0, num_assign - len(already))
            if missing <= 0:
                continue

            candidate_vars = []
            for wid in active_workshop_ids:
                if wid in already:
                    continue
                var = fill_model.NewBoolVar(f"complete_{pid}_{wid}")
                f[(pid, wid)] = var
                candidate_vars.append(var)

            if candidate_vars:
                fill_model.Add(sum(candidate_vars) <= missing)

        for wid in active_workshop_ids:
            remaining_total_capacity = max(
                0,
                int(workshops[wid].capacity) * num_assign
                - selected_workshop_counts[wid],
            )
            vars_for_workshop = [
                f[(pid, wid)]
                for pid in participant_ids
                if (pid, wid) in f
            ]
            if vars_for_workshop:
                fill_model.Add(
                    sum(vars_for_workshop) <= remaining_total_capacity
                )

        fill_vars = list(f.values())
        fill_model.Maximize(sum(fill_vars) if fill_vars else 0)

        fill_solver = cp_model.CpSolver()
        fill_solver.parameters.num_search_workers = 1
        fill_solver.parameters.random_seed = 0
        fill_solver.parameters.max_time_in_seconds = float(
            cfg.get(
                "completion_solver_limit",
                cfg.get(
                    "filler_solver_limit",
                    SERVICE_DEFAULTS.get("filler_solver_limit", 60.0),
                ),
            )
        )

        class StopAtCompletionTarget(cp_model.CpSolverSolutionCallback):
            def __init__(self, target: int) -> None:
                super().__init__()
                self.target = target

            def on_solution_callback(self) -> None:
                if int(round(self.ObjectiveValue())) >= self.target:
                    self.StopSearch()

        print(
            f"[matching] completion phase started: {missing_total} assignments open",
            flush=True,
        )
        fill_started = time.perf_counter()
        fill_status = fill_solver.Solve(
            fill_model,
            StopAtCompletionTarget(missing_total),
        )
        first_elapsed = time.perf_counter() - fill_started
        completion_solver_seconds += first_elapsed
        completion_solver_status = fill_solver.StatusName(fill_status)

        if fill_status in (cp_model.OPTIMAL, cp_model.FEASIBLE):
            completion_added = int(round(fill_solver.ObjectiveValue()))
            completion_proven_optimal = (
                fill_status == cp_model.OPTIMAL
                or completion_added >= missing_total
            )

            # Bei maximaler Anzahl Ergänzungen bevorzugen wir echte Wünsche
            # (inkl. Ranggewicht) gegenüber Nicht-Wünschen.
            if fill_vars and completion_added > 0:
                fill_model.Add(sum(fill_vars) == completion_added)
                completion_quality_terms = []
                for (pid, wid), var in f.items():
                    rank = wish_rank.get((pid, wid))
                    if rank is not None:
                        # Jeder Wunsch schlägt sicher jeden Nicht-Wunsch;
                        # innerhalb der Wünsche entscheidet der Rang.
                        completion_quality_terms.append(
                            (10000 + rank_weight_int[rank]) * var
                        )

                if completion_quality_terms:
                    fill_model.Maximize(sum(completion_quality_terms))
                    try:
                        fill_model.ClearHints()
                    except AttributeError:
                        pass
                    for key, var in f.items():
                        try:
                            fill_model.AddHint(var, int(fill_solver.Value(var)))
                        except AttributeError:
                            pass

                    quality_solver = cp_model.CpSolver()
                    quality_solver.parameters.num_search_workers = 1
                    quality_solver.parameters.random_seed = 0
                    quality_solver.parameters.max_time_in_seconds = min(
                        15.0,
                        float(
                            cfg.get(
                                "completion_solver_limit",
                                cfg.get("filler_solver_limit", 60.0),
                            )
                        ),
                    )
                    quality_started = time.perf_counter()
                    quality_status = quality_solver.Solve(fill_model)
                    completion_solver_seconds += (
                        time.perf_counter() - quality_started
                    )
                    if quality_status in (
                        cp_model.OPTIMAL,
                        cp_model.FEASIBLE,
                    ):
                        fill_solver = quality_solver
                        completion_solver_status = (
                            f"{completion_solver_status}/"
                            f"{quality_solver.StatusName(quality_status)}"
                        )
                        completion_proven_optimal = (
                            completion_proven_optimal
                            and quality_status == cp_model.OPTIMAL
                        )

            for (pid, wid), var in f.items():
                if fill_solver.Value(var):
                    selected_by_participant[pid].append(wid)
                    selected_source[(pid, wid)] = (
                        "wish"
                        if (pid, wid) in wish_rank
                        else "filler"
                    )

            for pid in participant_ids:
                selected_by_participant[pid].sort(
                    key=lambda wid: (
                        0 if (pid, wid) in wish_rank else 1,
                        wish_rank.get((pid, wid), num_wishes + 1),
                        wid,
                    )
                )

            print(
                f"[matching] completion phase finished in "
                f"{completion_solver_seconds:.3f}s "
                f"(status={completion_solver_status}, "
                f"added={completion_added}/{missing_total})",
                flush=True,
            )
        else:
            completion_proven_optimal = False
            print(
                f"[matching] completion phase ended without solution "
                f"after {completion_solver_seconds:.3f}s "
                f"(status={completion_solver_status})",
                flush=True,
            )

    # ------------------------------------------------------------------
    # Phase C: die fertige Workshop-Auswahl auf konkrete Slots verteilen.
    # ------------------------------------------------------------------
    schedule_model = cp_model.CpModel()
    z: Dict[Tuple[str, str, int], Any] = {}

    selected_edges = [
        (pid, wid)
        for pid in participant_ids
        for wid in selected_by_participant[pid]
    ]

    for pid, wid in selected_edges:
        for slot in slots:
            z[(pid, wid, slot)] = schedule_model.NewBoolVar(
                f"slot_{pid}_{wid}_{slot}"
            )
        schedule_model.Add(
            sum(z[(pid, wid, slot)] for slot in slots) == 1
        )

    for pid in participant_ids:
        selected = selected_by_participant[pid]
        for slot in slots:
            slot_vars = [
                z[(pid, wid, slot)]
                for wid in selected
            ]
            if slot_vars:
                schedule_model.Add(sum(slot_vars) <= 1)

    for wid in active_workshop_ids:
        capacity = max(0, int(workshops[wid].capacity))
        for slot in slots:
            slot_vars = [
                z[(pid, wid, slot)]
                for pid in participant_ids
                if (pid, wid, slot) in z
            ]
            if slot_vars:
                schedule_model.Add(sum(slot_vars) <= capacity)

    schedule_solver = cp_model.CpSolver()
    schedule_solver.parameters.num_search_workers = 1
    schedule_solver.parameters.random_seed = 0
    schedule_solver.parameters.max_time_in_seconds = float(
        cfg.get("slot_solver_limit", 60.0)
    )

    print(
        f"[matching] slot phase started: {len(selected_edges)} assignments",
        flush=True,
    )
    schedule_started = time.perf_counter()
    schedule_status = schedule_solver.Solve(schedule_model)
    slot_solver_seconds = time.perf_counter() - schedule_started
    slot_solver_status = schedule_solver.StatusName(schedule_status)

    if schedule_status not in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        raise RuntimeError(
            "CP-SAT konnte die ausgewählten Workshops nicht auf Slots "
            f"verteilen (Status: {slot_solver_status})."
        )

    print(
        f"[matching] slot phase finished in {slot_solver_seconds:.3f}s "
        f"(status={slot_solver_status})",
        flush=True,
    )

    assignments: Dict[str, Dict[int, str]] = {
        pid: {} for pid in participant_ids
    }
    for (pid, wid, slot), var in z.items():
        if schedule_solver.Value(var):
            assignments[pid][slot] = wid

    validate_matching_assignments(
        assignments,
        participants,
        workshops,
        cfg,
        allow_fillers=allow_fillers,
    )

    # ------------------------------------------------------------------
    # Ergebnis / Kennzahlen.
    # ------------------------------------------------------------------
    assignment_details: Dict[str, Dict[int, Dict[str, Any]]] = {}
    filler_assignments = 0
    final_wish_assignments = 0
    final_topk_hits = 0

    for pid in participant_ids:
        assignment_details[pid] = {}
        for slot, wid in sorted(assignments[pid].items()):
            rank = wish_rank.get((pid, wid))
            is_wish = rank is not None
            source = "wish" if is_wish else "filler"

            if is_wish:
                final_wish_assignments += 1
                if rank <= topk:
                    final_topk_hits += 1
            else:
                filler_assignments += 1

            assignment_details[pid][slot] = {
                "workshop_id": wid,
                "source": source,
                "wish_rank": rank,
                "is_topk": bool(rank is not None and rank <= topk),
            }

    quality_weights = _extend_weights(
        {int(k): float(v) for k, v in weights.items()},
        topk,
    )
    quality = compute_quality_metrics(
        assignments,
        participants,
        quality_weights,
        topk,
        num_assign,
    )

    per_slot_counts = {
        slot: sum(
            1 for pid in participant_ids if slot in assignments[pid]
        )
        for slot in slots
    }
    assign_dist = Counter(
        len(assignments[pid]) for pid in participant_ids
    )

    capacity_total = (
        sum(max(0, int(w.capacity)) for w in workshops.values())
        * num_assign
    )
    used_per_workshop_slot = {
        (wid, slot): sum(
            1
            for pid in participant_ids
            if assignments[pid].get(slot) == wid
        )
        for wid in workshop_ids
        for slot in slots
    }
    capacity_remaining = sum(
        max(
            0,
            int(workshops[wid].capacity)
            - used_per_workshop_slot[(wid, slot)],
        )
        for wid in workshop_ids
        for slot in slots
    )

    unfilled = []
    for wid in workshop_ids:
        remaining_total = sum(
            max(
                0,
                int(workshops[wid].capacity)
                - used_per_workshop_slot[(wid, slot)],
            )
            for slot in slots
        )
        if remaining_total > 0:
            unfilled.append({
                "id": wid,
                "title": workshops[wid].title,
                "remaining": remaining_total,
            })

    per_priority_fulfilled = {
        i: 0 for i in range(1, num_wishes + 1)
    }
    for pid in participant_ids:
        for wid in assignments[pid].values():
            rank = wish_rank.get((pid, wid))
            if rank is not None:
                per_priority_fulfilled[rank] += 1

    assignments_total = sum(
        len(slot_map) for slot_map in assignments.values()
    )
    participants_fully_assigned = sum(
        1
        for pid in participant_ids
        if len(assignments[pid]) == num_assign
    )
    total_solver_seconds = time.perf_counter() - timing_started

    all_wish_stages_proven_optimal = all(
        stage_proven_optimal.get(
            f"priority_{stage}_proven_optimal",
            False,
        )
        for stage in range(1, 6)
    )

    matching_quality_status = (
        "optimal"
        if (
            all_wish_stages_proven_optimal
            and completion_proven_optimal
            and schedule_status in (cp_model.OPTIMAL, cp_model.FEASIBLE)
        )
        else "time_limited_feasible"
    )

    summary = {
        "solver_status": stage_statuses.get(
            "priority_5_status",
            stage_statuses.get("priority_4_status", "UNKNOWN"),
        ),
        "matching_quality_status": matching_quality_status,
        # Alias für ältere Benchmark-Skripte.
        "run_quality": matching_quality_status,
        **stage_timings,
        **stage_statuses,
        **stage_proven_optimal,
        "all_stages_proven_optimal": all_wish_stages_proven_optimal,
        "completion_solver_seconds": completion_solver_seconds,
        "completion_solver_status": completion_solver_status,
        "completion_proven_optimal": completion_proven_optimal,
        # Rückwärtskompatible Namen für die bisherige Filler-Auswertung.
        "filler_solver_seconds": completion_solver_seconds,
        "filler_solver_status": completion_solver_status,
        "filler_proven_optimal": completion_proven_optimal,
        "slot_solver_seconds": slot_solver_seconds,
        "slot_solver_status": slot_solver_status,
        "total_solver_seconds": total_solver_seconds,
        "participants_total": len(participant_ids),
        "participants_no_wishes": sum(
            1 for p in participants.values() if not p.wishes
        ),
        "assignments_total": assignments_total,
        "wish_assignments": final_wish_assignments,
        "filler_assignments": filler_assignments,
        "participants_fully_assigned": participants_fully_assigned,
        "participants_fully_assigned_by_wishes": best_fully_by_wishes,
        "objective_assignments_total": best_wish_assignments_total,
        "objective_topk_hits": best_topk_hits,
        "topk_hits": final_topk_hits,
        "objective_weighted_quality": best_weighted_quality,
        "objective_min_participant_quality": best_min_participant_quality,
        "target_assignments_total": target_assignments_total,
        "assignment_distribution": dict(assign_dist),
        "per_slot_assigned_counts": per_slot_counts,
        "per_priority_fulfilled": per_priority_fulfilled,
        "capacity_total": capacity_total,
        "capacity_remaining_total": capacity_remaining,
        "unfilled_workshops_count": len(unfilled),
        "warning_capacity_deficit": max(
            0,
            target_assignments_total - capacity_total,
        ),
        "all_filled_to_slots": all(
            len(assignments[pid]) == num_assign
            for pid in participant_ids
        ),
        "happy_index": quality["happy_mean"],
        "min_user_happy": quality["min_user_happy"],
        "median_user_happy": quality["median_user_happy"],
        "gini_dissatisfaction": quality["gini_dissatisfaction"],
        "jain_index": quality["jain_index"],
        "top1_coverage": quality["top1_coverage"],
        "no_topk_rate": quality["no_topk_rate"],
        "topk_coverage_hist": quality["topk_coverage_hist"],
    }

    return assignments, {
        "summary": summary,
        "unfilled_workshops": unfilled,
        "assignment_details": assignment_details,
    }

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
    """
    Produktiv werden keine Matching-Parameter aus dem Request-Body
    übernommen. num_assign und num_wishes stammen ausschließlich aus
    dem veröffentlichten matching_config-Node.
    """
    return {}

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

    # Produktiv gibt es genau eine Matching-Strategie.
    strategy = "optimized"
    assignments, meta = run_matching_optimized(
        participants,
        workshops,
        cfg,
    )

    rows = []
    for pid, slots in assignments.items():
        for s, wid in sorted(slots.items()):
            detail = (
                meta.get("assignment_details", {})
                .get(pid, {})
                .get(s, {})
            )
            rows.append({
                "participant_id": pid,
                "slot": s,
                "workshop_id": wid,
                "workshop_title": workshops.get(
                    wid,
                    Workshop(wid, "?", 0),
                ).title,
                "source": detail.get("source", "wish"),
                "wish_rank": detail.get("wish_rank"),
                "is_topk": detail.get("is_topk", False),
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
