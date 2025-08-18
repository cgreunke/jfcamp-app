import os
import math
from typing import Dict, List, Tuple, Any, Optional
from flask import Flask, jsonify, request
import requests
from tenacity import retry, wait_fixed, stop_after_attempt, retry_if_exception_type

JSONAPI = os.environ.get("DRUPAL_URL", "http://drupal/jsonapi").rstrip("/")
DRUPAL_USER = os.environ.get("DRUPAL_USER")  # z.B. apiuser
DRUPAL_PASS = os.environ.get("DRUPAL_PASS")  # z.B. apipassword
DRUPAL_TOKEN = os.environ.get("DRUPAL_TOKEN")  # optional Bearer-Token

app = Flask(__name__)

def _auth_headers() -> Dict[str, str]:
    headers = {"Accept": "application/vnd.api+json"}
    if DRUPAL_TOKEN:
        headers["Authorization"] = f"Bearer {DRUPAL_TOKEN}"
    return headers

def _auth_tuple():
    if DRUPAL_TOKEN:
        return None
    if DRUPAL_USER and DRUPAL_PASS:
        return (DRUPAL_USER, DRUPAL_PASS)
    return None

@retry(wait=wait_fixed(1), stop=stop_after_attempt(5), retry=retry_if_exception_type(requests.RequestException))
def _get(url: str, params: Optional[Dict[str, Any]] = None) -> requests.Response:
    resp = requests.get(url, headers=_auth_headers(), params=params, auth=_auth_tuple(), timeout=15)
    resp.raise_for_status()
    return resp

@retry(wait=wait_fixed(1), stop=stop_after_attempt(5), retry=retry_if_exception_type(requests.RequestException))
def _patch(url: str, payload: Dict[str, Any]) -> requests.Response:
    headers = _auth_headers()
    headers["Content-Type"] = "application/vnd.api+json"
    resp = requests.patch(url, headers=headers, json=payload, auth=_auth_tuple(), timeout=20)
    resp.raise_for_status()
    return resp

def fetch_all(url: str, params: Dict[str, Any]) -> List[Dict[str, Any]]:
    """Follow JSON:API pagination and return concatenated data array."""
    items: List[Dict[str, Any]] = []
    next_url = url
    next_params = params.copy()
    while True:
        r = _get(next_url, next_params).json()
        items.extend(r.get("data", []))
        links = r.get("links", {})
        nxt = links.get("next", {})
        href = nxt.get("href")
        if not href:
            break
        # next link already has params embedded
        next_url = href
        next_params = {}
    return items

def get_matching_config() -> Tuple[int, int]:
    # newest published matching_config
    url = f"{JSONAPI}/node/matching_config"
    params = {
        "filter[status]": 1,
        "sort": "-created",
        "page[limit]": 1,
        "fields[node--matching_config]": "field_num_wuensche,field_num_zuteilung"
    }
    r = _get(url, params).json()
    data = r.get("data", [])
    if not data:
        # Fallback
        return (5, 3)
    attrs = data[0].get("attributes", {}) or {}
    wishes = int(attrs.get("field_num_wuensche") or 5)
    assign = int(attrs.get("field_num_zuteilung") or 3)
    return (wishes, assign)

def get_workshops_capacity() -> Dict[str, int]:
    """Return map: workshop_uuid -> capacity (None/empty means big capacity)."""
    url = f"{JSONAPI}/node/workshop"
    params = {
        "filter[status]": 1,
        "fields[node--workshop]": "title,field_maximale_plaetze",
        "page[limit]": 1000
    }
    items = fetch_all(url, params)
    caps: Dict[str, int] = {}
    for it in items:
        uuid = it["id"]
        attrs = it.get("attributes", {}) or {}
        cap_raw = attrs.get("field_maximale_plaetze")
        if cap_raw in (None, "", 0):
            caps[uuid] = 10**9  # praktisch unendlich
        else:
            try:
                caps[uuid] = max(0, int(cap_raw))
            except Exception:
                caps[uuid] = 10**9
    return caps

def get_wishes() -> Dict[str, List[str]]:
    """
    Return map: participant_uuid -> [workshop_uuid, ...] in priority order.
    Reads node--wunsch with relationships field_teilnehmer and field_wuensche.
    """
    url = f"{JSONAPI}/node/wunsch"
    params = {
        "filter[status]": 1,
        "include": "field_teilnehmer,field_wuensche",
        "fields[node--wunsch]": "field_teilnehmer,field_wuensche",
        "page[limit]": 2000
    }
    items = fetch_all(url, params)
    wishes: Dict[str, List[str]] = {}
    for it in items:
        rel = it.get("relationships", {}) or {}
        p = rel.get("field_teilnehmer", {}).get("data")
        if not p:
            continue
        participant_uuid = p.get("id")
        ws_list = rel.get("field_wuensche", {}).get("data") or []
        # Keep order, dedup
        seen = set()
        ordered: List[str] = []
        for ref in ws_list:
            wid = ref.get("id")
            if wid and wid not in seen:
                seen.add(wid)
                ordered.append(wid)
        if participant_uuid:
            wishes[participant_uuid] = ordered
    return wishes

def assign_greedy(
    wishes: Dict[str, List[str]],
    capacities: Dict[str, int],
    per_participant_limit: int
) -> Dict[str, List[str]]:
    """
    Round-based greedy: go through priority 1..N and give each participant one slot per round.
    """
    result: Dict[str, List[str]] = {p: [] for p in wishes.keys()}
    if not wishes:
        return result
    max_depth = max((len(v) for v in wishes.values()), default=0)
    for round_idx in range(max_depth):
        for participant, prefs in wishes.items():
            if len(result[participant]) >= per_participant_limit:
                continue
            if round_idx >= len(prefs):
                continue
            wid = prefs[round_idx]
            if capacities.get(wid, 0) <= 0:
                continue
            if wid in result[participant]:
                continue
            # assign
            result[participant].append(wid)
            capacities[wid] = capacities.get(wid, 0) - 1
    return result

def patch_assignment(participant_uuid: str, workshop_uuids: List[str]) -> None:
    url = f"{JSONAPI}/node/teilnehmer/{participant_uuid}"
    payload = {
        "data": {
            "type": "node--teilnehmer",
            "id": participant_uuid,
            "relationships": {
                "field_zugewiesen": {
                    "data": [{"type": "node--workshop", "id": w} for w in workshop_uuids]
                }
            }
        }
    }
    _patch(url, payload)

@app.get("/health")
def health():
    return jsonify({"ok": True, "jsonapi": JSONAPI})

@app.post("/matching/dry-run")
@app.get("/matching/dry-run")
def dry_run():
    wishes_max, assign_max = get_matching_config()
    caps = get_workshops_capacity()
    wishes = get_wishes()
    caps_copy = caps.copy()
    result = assign_greedy(wishes, caps_copy, assign_max)

    # Summaries
    assigned_counts = {p: len(ws) for p, ws in result.items()}
    remaining_caps = {w: c for w, c in caps_copy.items() if c < (10**9)}
    return jsonify({
        "ok": True,
        "config": {"max_wishes": wishes_max, "max_assign": assign_max},
        "participants": len(wishes),
        "assigned_ok": sum(1 for k in assigned_counts.values() if k == assign_max),
        "assigned_distribution": assigned_counts,
        "remaining_caps": remaining_caps,
        "preview": result
    })

@app.post("/matching/run")
@app.get("/matching/run")
def run_and_write():
    wishes_max, assign_max = get_matching_config()
    caps = get_workshops_capacity()
    wishes = get_wishes()
    result = assign_greedy(wishes, caps, assign_max)

    errors: Dict[str, str] = {}
    success = 0
    for participant_uuid, ws in result.items():
        try:
            patch_assignment(participant_uuid, ws)
            success += 1
        except Exception as e:
            errors[participant_uuid] = str(e)

    return jsonify({
        "ok": len(errors) == 0,
        "written": success,
        "failed": errors,
        "config": {"max_assign": assign_max},
    })
