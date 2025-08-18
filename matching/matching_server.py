
import os
import json
import logging
import hashlib
from typing import Dict, List, Tuple, Any
from dataclasses import dataclass, field
import traceback

import requests
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry
from flask import Flask, jsonify, request, Response

import csv
from io import StringIO

# -----------------------------------------------------------------------------
# Config
# -----------------------------------------------------------------------------

DRUPAL_URL = os.getenv("DRUPAL_URL", "http://drupal/jsonapi").rstrip("/")
DRUPAL_USER = os.getenv("DRUPAL_USER", "")
DRUPAL_PASS = os.getenv("DRUPAL_PASS", "")
PUBLISHED_ONLY = os.getenv("PUBLISHED_ONLY", "true").lower() in ("1", "true", "yes", "y")
MATCHING_SEED = os.getenv("MATCHING_SEED")
HTTP_TIMEOUT = float(os.getenv("HTTP_TIMEOUT", "10"))
MAX_PAGE_LIMIT = int(os.getenv("MAX_PAGE_LIMIT", "2000"))
PER_ROUND_PER_WS_LIMIT = int(os.getenv("PER_ROUND_PER_WS_LIMIT", "0"))

EP_WORKSHOP = f"{DRUPAL_URL}/node/workshop"
EP_TEILNEHMER = f"{DRUPAL_URL}/node/teilnehmer"
EP_WUNSCH = f"{DRUPAL_URL}/node/wunsch"
EP_MATCHING_CONFIG = f"{DRUPAL_URL}/node/matching_config"

logging.basicConfig(level=os.getenv("LOG_LEVEL", "INFO"),
                    format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger("matching")

def build_session() -> requests.Session:
    s = requests.Session()
    try:
        retries = Retry(
            total=5, connect=5, read=5, backoff_factor=0.5,
            status_forcelist=[429,500,502,503,504],
            allowed_methods=frozenset(["GET","PATCH"]),
            raise_on_status=False
        )
    except TypeError:
        retries = Retry(
            total=5, connect=5, read=5, backoff_factor=0.5,
            status_forcelist=[429,500,502,503,504],
            method_whitelist=frozenset(["GET","PATCH"]),
            raise_on_status=False
        )
    adapter = HTTPAdapter(max_retries=retries, pool_connections=10, pool_maxsize=20)
    s.mount("http://", adapter); s.mount("https://", adapter)
    if DRUPAL_USER or DRUPAL_PASS:
        s.auth = (DRUPAL_USER, DRUPAL_PASS)
    s.headers.update({"Accept":"application/vnd.api+json"})
    return s

SESSION = build_session()

# -----------------------------------------------------------------------------

@dataclass
class Workshop:
    id: str
    title: str
    capacity_total: int
    capacity_remaining: int

@dataclass
class Participant:
    id: str
    wishes: List[str] = field(default_factory=list)
    assigned: List[str] = field(default_factory=list)
    current_assigned: List[str] = field(default_factory=list)
    tie_break: int = 0
    first_name: str = ""
    last_name: str = ""
    code: str = ""
    region: str = ""

@dataclass
class MatchingConfig:
    num_wishes: int
    num_assign: int

# -----------------------------------------------------------------------------
# Robust offset pagination
# -----------------------------------------------------------------------------

def _fetch_all(url: str, extra_params: Dict[str, str] = None) -> List[Dict[str, Any]]:
    default_chunk = int(os.getenv("PAGE_CHUNK", "100"))
    base_params: Dict[str, str] = {}
    if PUBLISHED_ONLY:
        base_params["filter[status][value]"] = "1"
    if extra_params:
        base_params.update(extra_params)
    items: List[Dict[str, Any]] = []
    offset = 0
    try:
        requested_limit = int(base_params.get("page[limit]", default_chunk))
    except Exception:
        requested_limit = default_chunk

    seen_guard = 0
    while True:
        params = dict(base_params)
        params["page[limit]"] = str(requested_limit)
        params["page[offset]"] = str(offset)
        resp = SESSION.get(url, params=params, timeout=HTTP_TIMEOUT)
        if resp.status_code >= 400:
            raise RuntimeError(f"GET {url} failed: {resp.status_code} {resp.text[:300]}")
        payload = resp.json()
        batch = payload.get("data", []) or []
        if isinstance(batch, dict):
            batch = [batch]
        count = len(batch)
        if count == 0:
            break
        items.extend(batch)
        offset += count
        seen_guard += count
        if seen_guard > 50000:
            raise RuntimeError("Pagination guard triggered (>50k items)")
    return items

# -----------------------------------------------------------------------------

def _patch_participant_assignments(participant_id: str, workshop_ids: List[str]) -> Tuple[bool, str]:
    url = f"{EP_TEILNEHMER}/{participant_id}"
    payload = {"data": {"type":"node--teilnehmer","id":participant_id,
                        "relationships":{"field_zugewiesen":{"data":[{"type":"node--workshop","id":wid} for wid in workshop_ids]}}}}
    headers = {"Content-Type":"application/vnd.api+json"}
    resp = SESSION.patch(url, headers=headers, data=json.dumps(payload), timeout=HTTP_TIMEOUT)
    if resp.status_code >= 400:
        return False, f"{resp.status_code} {resp.text[:300]}"
    return True, "ok"

def load_config() -> MatchingConfig:
    items = _fetch_all(EP_MATCHING_CONFIG, {"sort":"-created","page[limit]":"1"})
    if not items:
        raise RuntimeError("No matching_config found.")
    cfg = items[0]["attributes"]
    num_wishes = int(cfg.get("field_num_wuensche") or 0)
    num_assign = int(cfg.get("field_num_zuteilung") or 0)
    if num_wishes<=0 or num_assign<=0:
        raise RuntimeError(f"Invalid matching_config values: num_wishes={num_wishes}, num_assign={num_assign}")
    return MatchingConfig(num_wishes=num_wishes, num_assign=num_assign)

def load_workshops() -> Dict[str, Workshop]:
    items = _fetch_all(EP_WORKSHOP)
    workshops: Dict[str, Workshop] = {}
    for it in items:
        wid = it["id"]
        attr = it.get("attributes", {}) or {}
        title = attr.get("title") or f"Workshop {wid[:8]}"
        cap = int(attr.get("field_maximale_plaetze") or 0)
        workshops[wid] = Workshop(id=wid, title=title, capacity_total=cap, capacity_remaining=cap)
    return workshops

def load_participants_and_wishes(workshops: Dict[str, Workshop], cfg: MatchingConfig) -> Tuple[Dict[str, Participant], int, int]:
    teilnehmer_items = _fetch_all(EP_TEILNEHMER)
    participants: Dict[str, Participant] = {}
    for it in teilnehmer_items:
        pid = it["id"]
        attr = it.get("attributes", {}) or {}
        rels = it.get("relationships", {}) or {}
        current = [rid["id"] for rid in (rels.get("field_zugewiesen", {}).get("data", []) or [])]
        participants[pid] = Participant(
            id=pid, current_assigned=current,
            first_name=(attr.get("field_vorname") or "").strip(),
            last_name=(attr.get("field_name") or "").strip(),
            code=(attr.get("field_code") or "").strip(),
            region=(attr.get("field_regionalverband") or "").strip(),
        )

    wunsch_items = _fetch_all(EP_WUNSCH, {"sort":"-created"})
    seen_pids = set()
    for wn in wunsch_items:
        rel = wn.get("relationships", {}) or {}
        teil_rel = rel.get("field_teilnehmer", {}).get("data")
        if not teil_rel:
            continue
        pid = teil_rel.get("id")
        if not pid or pid in seen_pids or pid not in participants:
            continue
        seen_pids.add(pid)
        wish_rel = rel.get("field_wuensche", {}).get("data", []) or []
        wish_ids = [r["id"] for r in wish_rel if r.get("id") in workshops]
        wish_ids = wish_ids[: cfg.num_wishes]
        participants[pid].wishes = wish_ids

    return participants, len(wunsch_items), len(teilnehmer_items)

def get_raw_counts() -> Dict[str, int]:
    try:
        w_all = _fetch_all(EP_WORKSHOP)
        t_all = _fetch_all(EP_TEILNEHMER)
        wn_all = _fetch_all(EP_WUNSCH)
        return {"workshops_all": len(w_all), "teilnehmer_all": len(t_all), "wunsch_all": len(wn_all)}
    except Exception as e:
        log.warning(f"raw count probe failed: {e}")
        return {"workshops_all": -1, "teilnehmer_all": -1, "wunsch_all": -1}

def compute_seed(workshops: Dict[str, Workshop], participants: Dict[str, Participant], cfg: MatchingConfig) -> int:
    if MATCHING_SEED is not None:
        try:
            return int(MATCHING_SEED)
        except ValueError:
            pass
    hasher = hashlib.sha256()
    hasher.update(str(cfg.num_wishes).encode()); hasher.update(str(cfg.num_assign).encode())
    for wid in sorted(workshops):
        w = workshops[wid]
        hasher.update(w.id.encode()); hasher.update(str(w.capacity_total).encode()); hasher.update((w.title or "").encode())
    for pid in sorted(participants):
        p = participants[pid]
        hasher.update(p.id.encode())
        for w in p.wishes:
            hasher.update(w.encode())
    return int.from_bytes(hasher.digest()[:8], "big")

def run_matching(workshops: Dict[str, Workshop], participants: Dict[str, Participant], cfg: MatchingConfig) -> Dict[str, Any]:
    seed = compute_seed(workshops, participants, cfg)
    digest = hashlib.sha256(f"{seed}".encode()).digest()
    tiebreak_map: Dict[str, int] = {}
    di = 0
    for pid in participants.keys():
        if di + 4 > len(digest):
            digest = hashlib.sha256(digest).digest(); di = 0
        tiebreak_map[pid] = int.from_bytes(digest[di:di+4], "big"); di += 4
    for pid, p in participants.items():
        p.assigned = []; p.tie_break = tiebreak_map[pid]

    slots = cfg.num_assign
    workshop_ids = list(workshops.keys())
    workshops_by_slot: Dict[int, Dict[str, int]] = {}
    for s in range(1, slots + 1):
        workshops_by_slot[s] = {wid: workshops[wid].capacity_total for wid in workshop_ids}

    capacity_per_slot = {s: sum(workshops_by_slot[s].values()) for s in range(1, slots + 1)}
    per_priority_fulfilled: Dict[int, int] = {}
    max_possible_rounds = cfg.num_wishes  # WICHTIG: mehr Wünsche als Slots zulassen

    # Für jeden Slot, Wünsche in Prioritätsreihenfolge 1..num_wishes durchgehen
    for slot in range(1, slots + 1):
        if capacity_per_slot[slot] <= 0:
            continue
        for r in range(max_possible_rounds):  # r=0 => Wunsch Prio 1
            order = sorted(participants.values(), key=lambda p: (len(p.assigned), p.tie_break))
            round_assignments = 0
            round_ws_counts: Dict[str, int] = {}
            for p in order:
                if len(p.assigned) >= slot:  # pro Participant Slot für Slot befüllen
                    continue
                if r >= len(p.wishes):
                    continue
                wid = p.wishes[r]
                if wid in p.assigned:
                    continue
                cap_here = workshops_by_slot[slot].get(wid, 0)
                if cap_here <= 0:
                    continue
                if PER_ROUND_PER_WS_LIMIT > 0 and round_ws_counts.get(wid, 0) >= PER_ROUND_PER_WS_LIMIT:
                    continue
                # assign
                p.assigned.append(wid)
                workshops_by_slot[slot][wid] = cap_here - 1
                capacity_per_slot[slot] -= 1
                round_assignments += 1
                round_ws_counts[wid] = round_ws_counts.get(wid, 0) + 1
                per_priority_fulfilled[r + 1] = per_priority_fulfilled.get(r + 1, 0) + 1
                if capacity_per_slot[slot] <= 0:
                    break
            log.info(f"Slot {slot}, Prio {r+1}: +{round_assignments} (frei: {capacity_per_slot[slot]})")
            if capacity_per_slot[slot] <= 0:
                break

    # Fill: Rest im Slot aus gleichen Wünschen, sonst beliebige freie Plätze
    filler_assignments = 0
    for slot in range(1, slots + 1):
        if capacity_per_slot[slot] <= 0:
            continue
        changed = True
        while changed and capacity_per_slot[slot] > 0:
            changed = False
            order = sorted([p for p in participants.values() if len(p.assigned) < slot],
                           key=lambda p: (len(p.assigned), p.tie_break))
            if not order:
                break
            for p in order:
                if len(p.assigned) >= slot:
                    continue
                chosen = None
                for wid in p.wishes:
                    if wid in p.assigned:
                        continue
                    if workshops_by_slot[slot].get(wid, 0) > 0:
                        chosen = wid; break
                if not chosen:
                    candidates = [(wid, cap) for wid, cap in workshops_by_slot[slot].items() if cap > 0 and wid not in p.assigned]
                    if candidates:
                        candidates.sort(key=lambda t: (-t[1], workshops[t[0]].title, t[0]))
                        chosen = candidates[0][0]
                if chosen:
                    workshops_by_slot[slot][chosen] -= 1
                    capacity_per_slot[slot] -= 1
                    p.assigned.append(chosen)
                    filler_assignments += 1
                    changed = True
                    if capacity_per_slot[slot] <= 0:
                        break

    assigned_counts = [len(p.assigned) for p in participants.values()]
    dist = {k: assigned_counts.count(k) for k in range(0, slots + 1)}

    by_workshop: Dict[str, Dict[str, int | str]] = {}
    for wid, w in workshops.items():
        cap_total_all_slots = w.capacity_total * slots
        cap_remaining_all_slots = sum(workshops_by_slot[s].get(wid, 0) for s in range(1, slots + 1))
        by_workshop[wid] = {
            "title": w.title,
            "capacity_total": cap_total_all_slots,
            "capacity_remaining": cap_remaining_all_slots,
            "capacity_used": cap_total_all_slots - cap_remaining_all_slots,
        }

    assignments_by_slot: Dict[int, Dict[str, List[str]]] = {i: {} for i in range(1, slots + 1)}
    per_slot_assigned_counts: Dict[int, int] = {i: 0 for i in range(1, slots + 1)}
    for p in participants.values():
        for idx, wid in enumerate(p.assigned[:slots]):
            slot = idx + 1
            bucket = assignments_by_slot[slot].setdefault(wid, [])
            bucket.append(p.id)
            per_slot_assigned_counts[slot] += 1

    capacity_remaining_total = sum(by_workshop[wid]["capacity_remaining"] for wid in by_workshop)
    unfilled_workshops = []
    for wid, meta in by_workshop.items():
        if meta["capacity_remaining"] > 0:
            unfilled_workshops.append({"id": wid, "title": workshops[wid].title, "remaining": meta["capacity_remaining"]})
    unfilled_workshops.sort(key=lambda x: (-x["remaining"], x["title"]))

    participants_without_wishes = [pid for pid, p in participants.items() if not p.wishes][:500]

    summary = {
        "participants_total": len(participants),
        "assignments_total": sum(len(p.assigned) for p in participants.values()),
        "participants_no_wishes": sum(1 for p in participants.values() if len(p.wishes) == 0),
        "per_priority_fulfilled": {str(k): v for k, v in sorted(per_priority_fulfilled.items())},
        "assignment_distribution": dist,
        "seed": str(seed),
        "capacity_total": sum(w.capacity_total for w in workshops.values()) * slots,
        "capacity_remaining_total": capacity_remaining_total,
        "per_slot_assigned_counts": per_slot_assigned_counts,
        "unfilled_workshops_count": len(unfilled_workshops),
        "filler_assignments": filler_assignments,
        "target_assignments_total": len(participants) * slots,
        "all_filled_to_slots": (dist.get(slots, 0) == len(participants)),
        "warning_capacity_deficit": max(0, (len(participants) * slots) - (sum(w.capacity_total for w in workshops.values()) * slots)),
    }

    export_rows = []
    def pinfo(pid: str) -> Dict[str, str]:
        q = participants[pid]
        return {"participant_id": q.id, "first_name": q.first_name, "last_name": q.last_name, "code": q.code, "region": q.region}
    for slot in range(1, slots + 1):
        for wid, pids in assignments_by_slot[slot].items():
            w = workshops[wid]
            for pid in pids:
                export_rows.append({"slot": slot, "workshop_id": wid, "workshop_title": w.title, **pinfo(pid)})

    by_participant = {
        pid: {
            "requested": participants[pid].wishes,
            "assigned": participants[pid].assigned,
            "current_assigned": participants[pid].current_assigned,
        } for pid in participants
    }

    return {
        "summary": summary,
        "workshops": by_workshop,
        "by_participant": by_participant,
        "assignments_by_slot": assignments_by_slot,
        "unfilled_workshops": unfilled_workshops,
        "participants_without_wishes": participants_without_wishes,
        "export_rows": export_rows,
    }

# -----------------------------------------------------------------------------
# Flask
# -----------------------------------------------------------------------------

app = Flask(__name__)

@app.get("/health")
def health():
    return jsonify({"ok": True})

def _compute() -> Dict[str, Any]:
    cfg = load_config()
    workshops = load_workshops()
    participants, wunsch_nodes_n, teilnehmer_nodes_n = load_participants_and_wishes(workshops, cfg)

    raw_counts = get_raw_counts()
    source_counts = {
        "workshops_seen": len(workshops),
        "teilnehmer_seen": len(participants),
        "wunsch_nodes_seen": wunsch_nodes_n,
        "published_only": PUBLISHED_ONLY,
        **raw_counts,
    }

    result = run_matching(workshops, participants, cfg)
    result["summary"]["source_counts"] = source_counts
    return result

@app.post("/matching/dry-run")
def dry_run():
    try:
        result = _compute()
        return jsonify({"status": "ok", "mode": "dry-run", **result})
    except Exception as e:
        log.exception("dry-run failed")
        return jsonify({"status": "error", "message": str(e), "trace": traceback.format_exc()}), 500

@app.post("/matching/run")
def run_endpoint():
    try:
        result = _compute()
        by_participant = result["by_participant"]
        changed = 0; errors: List[str] = []
        for pid, info in by_participant.items():
            new_list = info["assigned"]; current_list = info["current_assigned"]
            if new_list == current_list:
                continue
            ok, msg = _patch_participant_assignments(pid, new_list)
            if ok: changed += 1
            else: errors.append(f"{pid}: {msg}")
        payload = {"status": "ok", "mode": "run", "patched": changed, **result}
        if errors: payload["patch_errors"] = errors[:50]
        return jsonify(payload)
    except Exception as e:
        log.exception("run failed")
        return jsonify({"status": "error", "message": str(e), "trace": traceback.format_exc()}), 500

# CSV exports --------------------------------------------------------------

def _csv_response(rows: List[Dict[str, Any]], fieldnames: List[str], filename: str) -> Response:
    sio = StringIO(); w = csv.DictWriter(sio, fieldnames=fieldnames)
    w.writeheader()
    for r in rows:
        w.writerow({k: r.get(k, "") for k in fieldnames})
    out = sio.getvalue()
    return Response(out, mimetype="text/csv",
                    headers={"Content-Disposition": f'attachment; filename="{filename}"'})

@app.get("/export/slots.csv")
def export_all_slots():
    try:
        result = _compute()
        rows = result["export_rows"]
        fields = ["slot", "workshop_title", "workshop_id", "participant_id","first_name","last_name","code","region"]
        return _csv_response(rows, fields, "assignments_by_slots.csv")
    except Exception as e:
        log.exception("export slots failed")
        return jsonify({"status": "error", "message": str(e)}), 500

@app.get("/export/slot/<int:slot_index>.csv")
def export_one_slot(slot_index: int):
    try:
        result = _compute()
        rows = [r for r in result["export_rows"] if int(r["slot"]) == int(slot_index)]
        fields = ["slot", "workshop_title", "workshop_id", "participant_id","first_name","last_name","code","region"]
        return _csv_response(rows, fields, f"slot_{slot_index}.csv")
    except Exception as e:
        log.exception("export slot failed")
        return jsonify({"status": "error", "message": str(e)}), 500

@app.get("/export/regions.csv")
def export_regions():
    try:
        result = _compute()
        byp = result["by_participant"]
        slots = len(result["summary"].get("per_slot_assigned_counts", {})) or 3
        rows = []
        meta_map = {}
        for r in result["export_rows"]:
            meta_map[r["participant_id"]] = {"first_name": r["first_name"], "last_name": r["last_name"], "code": r["code"], "region": r["region"]}
        for pid, info in byp.items():
            meta = meta_map.get(pid, {"first_name":"","last_name":"","code":"","region":""})
            assigned = info.get("assigned", [])
            row = {"participant_id": pid, "first_name": meta["first_name"], "last_name": meta["last_name"], "code": meta["code"], "region": meta["region"]}
            for i in range(slots):
                row[f"slot_{i+1}"] = ""
            for i, wid in enumerate(assigned[:slots]):
                title = result["workshops"].get(wid, {}).get("title", wid[:8])
                row[f"slot_{i+1}"] = title
            rows.append(row)
        fields = ["region","last_name","first_name","code","participant_id"] + [f"slot_{i+1}" for i in range(slots)]
        rows.sort(key=lambda r: (r["region"], r["last_name"], r["first_name"]))
        return _csv_response(rows, fields, "participants_by_region.csv")
    except Exception as e:
        log.exception("export regions failed")
        return jsonify({"status": "error", "message": str(e)}), 500

@app.get("/export/overview.csv")
def export_overview():
    try:
        result = _compute()
        rows = []
        for wid, w in result["workshops"].items():
            rows.append({"workshop_title": w["title"], "workshop_id": wid, "capacity_total": w["capacity_total"], "capacity_used": w["capacity_used"], "capacity_remaining": w["capacity_remaining"]})
        rows.sort(key=lambda r: (r["workshop_title"]))
        fields = ["workshop_title","workshop_id","capacity_total","capacity_used","capacity_remaining"]
        return _csv_response(rows, fields, "workshops_overview.csv")
    except Exception as e:
        log.exception("export overview failed")
        return jsonify({"status": "error", "message": str(e)}), 500

@app.get("/export/pending.csv")
def export_pending():
    try:
        result = _compute()
        ids = set(result["participants_without_wishes"])
        rows = [{"participant_id": pid} for pid in ids]
        return _csv_response(rows, ["participant_id"], "participants_without_wishes.csv")
    except Exception as e:
        log.exception("export pending failed")
        return jsonify({"status": "error", "message": str(e)}), 500

# Probe endpoints ----------------------------------------------------------

@app.get("/matching/debug")
def debug_info():
    try:
        w_seen = len(_fetch_all(EP_WORKSHOP))
        t_seen = len(_fetch_all(EP_TEILNEHMER))
        wn_seen = len(_fetch_all(EP_WUNSCH))
        raw = get_raw_counts()
        return jsonify({"status":"ok","published_only":PUBLISHED_ONLY,"counts_seen":{"workshops":w_seen,"teilnehmer":t_seen,"wunsch_nodes":wn_seen},"counts_all":raw})
    except Exception as e:
        log.exception("debug failed")
        return jsonify({"status":"error","message":str(e)}), 500

@app.get("/matching/probe")
def probe_info():
    """
    Load participants the same way the matcher does and show the count,
    so we can see why /debug (raw fetch) vs matching view differ.
    """
    try:
        cfg = load_config()
        workshops = load_workshops()
        participants, wunsch_nodes_n, teilnehmer_nodes_n = load_participants_and_wishes(workshops, cfg)
        sample_ids = list(participants.keys())[:20]
        return jsonify({
            "status":"ok",
            "published_only": PUBLISHED_ONLY,
            "participants_seen": len(participants),
            "sample_participant_ids": sample_ids,
            "teilnehmer_nodes_raw": teilnehmer_nodes_n,
            "wunsch_nodes_seen": wunsch_nodes_n
        })
    except Exception as e:
        log.exception("probe failed")
        return jsonify({"status":"error","message":str(e), "trace": traceback.format_exc()}), 500

@app.get("/matching/probe/missing")
def probe_missing():
    try:
        raw = _fetch_all(EP_TEILNEHMER)
        raw_ids = [it["id"] for it in raw]
        cfg = load_config()
        workshops = load_workshops()
        participants, _, _ = load_participants_and_wishes(workshops, cfg)
        seen_ids = set(participants.keys())
        missing = [i for i in raw_ids if i not in seen_ids][:100]
        return jsonify({"status":"ok","missing_count": len(raw_ids)-len(seen_ids), "missing_sample_ids": missing})
    except Exception as e:
        log.exception("probe missing failed")
        return jsonify({"status":"error","message":str(e)}), 500

if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5001, debug=False)
