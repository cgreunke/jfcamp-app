import os
import json
import logging
import hashlib
from typing import Dict, List, Tuple, Any
from dataclasses import dataclass, field

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
MATCHING_SEED = os.getenv("MATCHING_SEED")  # optional fixed seed
HTTP_TIMEOUT = float(os.getenv("HTTP_TIMEOUT", "10"))
MAX_PAGE_LIMIT = int(os.getenv("MAX_PAGE_LIMIT", "2000"))

# Optional: pro Runde je Workshop-Zuweisungs-Limit (z. B. 1 für stärkere Streuung in Runde 1–N)
PER_ROUND_PER_WS_LIMIT = int(os.getenv("PER_ROUND_PER_WS_LIMIT", "0"))

# JSON:API endpoints
EP_WORKSHOP = f"{DRUPAL_URL}/node/workshop"
EP_TEILNEHMER = f"{DRUPAL_URL}/node/teilnehmer"
EP_WUNSCH = f"{DRUPAL_URL}/node/wunsch"
EP_MATCHING_CONFIG = f"{DRUPAL_URL}/node/matching_config"


# -----------------------------------------------------------------------------
# Logging
# -----------------------------------------------------------------------------

logging.basicConfig(
    level=os.getenv("LOG_LEVEL", "INFO"),
    format="%(asctime)s %(levelname)s %(message)s"
)
log = logging.getLogger("matching")


# -----------------------------------------------------------------------------
# HTTP Session with retries
# -----------------------------------------------------------------------------

def build_session() -> requests.Session:
    s = requests.Session()
    retries = Retry(
        total=5,
        connect=5,
        read=5,
        backoff_factor=0.5,
        status_forcelist=[429, 500, 502, 503, 504],
        allowed_methods=frozenset(["GET", "PATCH"]),
        raise_on_status=False,
    )
    adapter = HTTPAdapter(max_retries=retries, pool_connections=10, pool_maxsize=20)
    s.mount("http://", adapter)
    s.mount("https://", adapter)
    if DRUPAL_USER or DRUPAL_PASS:
        s.auth = (DRUPAL_USER, DRUPAL_PASS)
    s.headers.update({"Accept": "application/vnd.api+json"})
    return s


SESSION = build_session()


# -----------------------------------------------------------------------------
# Data structures
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
    wishes: List[str] = field(default_factory=list)  # ordered priority (1..N)
    assigned: List[str] = field(default_factory=list)
    current_assigned: List[str] = field(default_factory=list)  # what Drupal currently has
    tie_break: int = 0

    # Meta for exports
    first_name: str = ""
    last_name: str = ""
    code: str = ""
    region: str = ""


@dataclass
class MatchingConfig:
    num_wishes: int
    num_assign: int  # = number of slots


# -----------------------------------------------------------------------------
# Helpers: JSON:API fetch & patch
# -----------------------------------------------------------------------------

def _fetch_all(url: str, extra_params: Dict[str, str] = None) -> List[Dict[str, Any]]:
    params = {
        "filter[status][value]": "1",
        "page[limit]": str(MAX_PAGE_LIMIT),
    }
    if extra_params:
        params.update(extra_params)

    items: List[Dict[str, Any]] = []
    next_url = url
    while True:
        resp = SESSION.get(next_url, params=params, timeout=HTTP_TIMEOUT)
        if resp.status_code >= 400:
            raise RuntimeError(f"GET {next_url} failed: {resp.status_code} {resp.text[:300]}")
        payload = resp.json()
        data = payload.get("data", [])
        if isinstance(data, dict):
            data = [data]
        items.extend(data)
        links = payload.get("links", {})
        next_link = links.get("next", {}).get("href")
        if not next_link or len(data) < int((params or {}).get("page[limit]", MAX_PAGE_LIMIT)):
            break
        next_url = next_link
        params = None  # already encoded into next_link
    return items


def _patch_participant_assignments(participant_id: str, workshop_ids: List[str]) -> Tuple[bool, str]:
    url = f"{EP_TEILNEHMER}/{participant_id}"
    payload = {
        "data": {
            "type": "node--teilnehmer",
            "id": participant_id,
            "relationships": {
                "field_zugewiesen": {
                    "data": [{"type": "node--workshop", "id": wid} for wid in workshop_ids]
                }
            }
        }
    }
    headers = {"Content-Type": "application/vnd.api+json"}
    resp = SESSION.patch(url, headers=headers, data=json.dumps(payload), timeout=HTTP_TIMEOUT)
    if resp.status_code >= 400:
        return False, f"{resp.status_code} {resp.text[:300]}"
    return True, "ok"


# -----------------------------------------------------------------------------
# Load data from Drupal
# -----------------------------------------------------------------------------

def load_config() -> MatchingConfig:
    items = _fetch_all(EP_MATCHING_CONFIG, {"sort": "-created", "page[limit]": "1"})
    if not items:
        raise RuntimeError("No published matching_config found.")
    cfg = items[0]["attributes"]
    num_wishes = int(cfg.get("field_num_wuensche") or 0)
    num_assign = int(cfg.get("field_num_zuteilung") or 0)
    if num_wishes <= 0 or num_assign <= 0:
        raise RuntimeError(f"Invalid matching_config values: num_wishes={num_wishes}, num_assign={num_assign}")
    return MatchingConfig(num_wishes=num_wishes, num_assign=num_assign)


def load_workshops() -> Dict[str, Workshop]:
    items = _fetch_all(EP_WORKSHOP)
    workshops: Dict[str, Workshop] = {}
    for it in items:
        wid = it["id"]
        attr = it.get("attributes", {})
        title = attr.get("title") or f"Workshop {wid[:8]}"
        cap = int(attr.get("field_maximale_plaetze") or 0)
        workshops[wid] = Workshop(id=wid, title=title, capacity_total=cap, capacity_remaining=cap)
    return workshops


def load_participants_and_wishes(workshops: Dict[str, Workshop], cfg: MatchingConfig) -> Dict[str, Participant]:
    participant_items = _fetch_all(EP_TEILNEHMER)
    participants: Dict[str, Participant] = {}

    for it in participant_items:
        pid = it["id"]
        attr = it.get("attributes", {}) or {}
        rels = it.get("relationships", {}) or {}
        current = [rid["id"] for rid in (rels.get("field_zugewiesen", {}).get("data", []) or [])]
        participants[pid] = Participant(
            id=pid,
            current_assigned=current,
            first_name=(attr.get("field_vorname") or "").strip(),
            last_name=(attr.get("field_name") or "").strip(),
            code=(attr.get("field_code") or "").strip(),
            region=(attr.get("field_regionalverband") or "").strip(),
        )

    # Wünsche – neueste je Teilnehmer
    wunsch_items = _fetch_all(EP_WUNSCH, {"sort": "-created"})
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

    return participants


# -----------------------------------------------------------------------------
# Deterministic seed
# -----------------------------------------------------------------------------

def compute_seed(workshops: Dict[str, Workshop], participants: Dict[str, Participant], cfg: MatchingConfig) -> int:
    if MATCHING_SEED is not None:
        try:
            return int(MATCHING_SEED)
        except ValueError:
            pass
    hasher = hashlib.sha256()
    hasher.update(str(cfg.num_wishes).encode())
    hasher.update(str(cfg.num_assign).encode())
    for wid in sorted(workshops):
        w = workshops[wid]
        hasher.update(w.id.encode())
        hasher.update(str(w.capacity_total).encode())
        hasher.update((w.title or "").encode())
    for pid in sorted(participants):
        p = participants[pid]
        hasher.update(p.id.encode())
        for w in p.wishes:
            hasher.update(w.encode())
    return int.from_bytes(hasher.digest()[:8], "big")


# -----------------------------------------------------------------------------
# Matching algorithm
#   - Round r = 0..num_assign-1 assigns priority (r+1) wishes fairly
#   - Per-round optional workshop-assign limit (PER_ROUND_PER_WS_LIMIT)
#   - Fill phase to ensure everyone reaches num_assign (if total capacity suffices)
# -----------------------------------------------------------------------------

def run_matching(workshops: Dict[str, Workshop], participants: Dict[str, Participant], cfg: MatchingConfig) -> Dict[str, Any]:
    seed = compute_seed(workshops, participants, cfg)
    # Deterministische Tie-Break-Werte
    digest = hashlib.sha256(f"{seed}".encode()).digest()
    tiebreak_map: Dict[str, int] = {}
    di = 0
    for pid in participants.keys():
        if di + 4 > len(digest):
            digest = hashlib.sha256(digest).digest()
            di = 0
        tiebreak_map[pid] = int.from_bytes(digest[di:di+4], "big")
        di += 4

    for pid, p in participants.items():
        p.assigned = []
        p.tie_break = tiebreak_map[pid]

    per_priority_fulfilled: Dict[int, int] = {}
    capacity_total = sum(w.capacity_total for w in workshops.values())
    total_capacity = capacity_total

    # Vorab: Workshops mit 0 Kapazität bleiben drin (für Metrik), werden aber nicht befüllt.
    max_possible_rounds = min(cfg.num_assign, cfg.num_wishes)

    for r in range(max_possible_rounds):  # r=0 => Priorität 1
        if total_capacity <= 0:
            break

        order = sorted(participants.values(), key=lambda p: (len(p.assigned), p.tie_break))
        round_assignments = 0
        round_ws_counts: Dict[str, int] = {}

        for p in order:
            if len(p.assigned) >= cfg.num_assign:
                continue
            if r >= len(p.wishes):
                continue

            desired_wid = p.wishes[r]
            w = workshops.get(desired_wid)
            if not w or w.capacity_remaining <= 0:
                continue
            if desired_wid in p.assigned:
                continue

            if PER_ROUND_PER_WS_LIMIT > 0 and round_ws_counts.get(desired_wid, 0) >= PER_ROUND_PER_WS_LIMIT:
                continue

            # Assign
            p.assigned.append(desired_wid)
            w.capacity_remaining -= 1
            total_capacity -= 1
            round_assignments += 1
            round_ws_counts[desired_wid] = round_ws_counts.get(desired_wid, 0) + 1
            per_priority_fulfilled[r + 1] = per_priority_fulfilled.get(r + 1, 0) + 1

        log.info(f"Round {r+1}: assigned {round_assignments} slots. Remaining capacity: {total_capacity}")

    # Füllphase: Jeder Teilnehmer soll genau num_assign Slots bekommen (wenn möglich)
    filler_assignments = 0
    if total_capacity > 0:
        changed = True
        while changed:
            changed = False
            # Fairness: zuerst die mit wenigen Zuteilungen
            order = sorted([p for p in participants.values() if len(p.assigned) < cfg.num_assign],
                           key=lambda p: (len(p.assigned), p.tie_break))
            if not order:
                break

            for p in order:
                if len(p.assigned) >= cfg.num_assign:
                    continue

                # 1) Versuche restliche Wünsche in Prioritätsreihenfolge
                chosen = None
                for wid in p.wishes:
                    if wid in p.assigned:
                        continue
                    w = workshops.get(wid)
                    if not w or w.capacity_remaining <= 0:
                        continue
                    chosen = wid
                    break

                # 2) Fallback: irgendein Workshop mit freier Kapazität (nicht doppelt, ausbalanciert)
                if not chosen:
                    # Sortierung deterministic: max remaining, dann Titel, dann ID
                    candidates = [
                        w for w in workshops.values()
                        if w.capacity_remaining > 0 and w.id not in p.assigned
                    ]
                    if candidates:
                        candidates.sort(key=lambda ww: (-ww.capacity_remaining, ww.title, ww.id))
                        chosen = candidates[0].id

                if chosen:
                    w = workshops[chosen]
                    p.assigned.append(chosen)
                    w.capacity_remaining -= 1
                    total_capacity -= 1
                    filler_assignments += 1
                    changed = True
                    if total_capacity <= 0:
                        break

            if total_capacity <= 0:
                break

    # Metriken
    assigned_counts = [len(p.assigned) for p in participants.values()]
    dist = {k: assigned_counts.count(k) for k in range(0, cfg.num_assign + 1)}
    by_workshop = {
        wid: {
            "title": w.title,
            "capacity_total": w.capacity_total,
            "capacity_remaining": w.capacity_remaining,
            "capacity_used": w.capacity_total - w.capacity_remaining,
        }
        for wid, w in workshops.items()
    }
    by_participant = {
        pid: {
            "requested": participants[pid].wishes,
            "assigned": participants[pid].assigned,
            "current_assigned": participants[pid].current_assigned,
        }
        for pid in participants
    }

    # Slot-Statistik & Exportvorbereitung
    slots = cfg.num_assign
    assignments_by_slot: Dict[int, Dict[str, List[str]]] = {i: {} for i in range(1, slots + 1)}
    per_slot_assigned_counts: Dict[int, int] = {i: 0 for i in range(1, slots + 1)}

    for p in participants.values():
        for idx, wid in enumerate(p.assigned[:slots]):
            slot = idx + 1  # 1-indexiert
            bucket = assignments_by_slot[slot].setdefault(wid, [])
            bucket.append(p.id)
            per_slot_assigned_counts[slot] += 1

    capacity_remaining_total = sum(w.capacity_remaining for w in workshops.values())
    unfilled_workshops = [
        {"id": wid, "title": w.title, "remaining": w.capacity_remaining}
        for wid, w in workshops.items() if w.capacity_remaining > 0
    ]
    unfilled_workshops.sort(key=lambda x: (-x["remaining"], x["title"]))

    participants_without_wishes = [pid for pid, p in participants.items() if not p.wishes]
    participants_without_wishes = participants_without_wishes[:500]

    summary = {
        "participants_total": len(participants),
        "assignments_total": sum(len(p.assigned) for p in participants.values()),
        "participants_no_wishes": sum(1 for p in participants.values() if len(p.wishes) == 0),
        "per_priority_fulfilled": {str(k): v for k, v in sorted(per_priority_fulfilled.items())},
        "assignment_distribution": dist,
        "seed": str(seed),
        "capacity_total": capacity_total,
        "capacity_remaining_total": capacity_remaining_total,
        "per_slot_assigned_counts": per_slot_assigned_counts,
        "unfilled_workshops_count": len(unfilled_workshops),
        "filler_assignments": filler_assignments,
        "target_assignments_total": len(participants) * slots,
        "all_filled_to_slots": (dist.get(slots, 0) == len(participants)),
        "warning_capacity_deficit": max(0, (len(participants) * slots) - (capacity_total)),
    }

    # Exportzeilen (alle Slots)
    export_rows = []
    def pinfo(pid: str) -> Dict[str, str]:
        q = participants[pid]
        return {
            "participant_id": q.id,
            "first_name": q.first_name,
            "last_name": q.last_name,
            "code": q.code,
            "region": q.region,
        }

    for slot in range(1, slots + 1):
        for wid, pids in assignments_by_slot[slot].items():
            w = workshops[wid]
            for pid in pids:
                pi = pinfo(pid)
                export_rows.append({
                    "slot": slot,
                    "workshop_id": wid,
                    "workshop_title": w.title,
                    **pi
                })

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
# Flask API
# -----------------------------------------------------------------------------

app = Flask(__name__)


@app.get("/health")
def health():
    return jsonify({"ok": True})


def _compute() -> Dict[str, Any]:
    cfg = load_config()
    workshops = load_workshops()
    participants = load_participants_and_wishes(workshops, cfg)

    log.info(f"Loaded {len(workshops)} workshops, {len(participants)} participants, cfg(num_wishes={cfg.num_wishes}, num_assign={cfg.num_assign})")
    result = run_matching(workshops, participants, cfg)

    s = result["summary"]
    log.info(f"Summary: participants={s['participants_total']} assignments={s['assignments_total']} no_wishes={s['participants_no_wishes']} filler={s['filler_assignments']} all_filled={s['all_filled_to_slots']}")
    if s.get("warning_capacity_deficit", 0) > 0:
        log.warning(f"Capacity deficit: total capacity {s['capacity_total']} < required {s['target_assignments_total']}")

    remaining = {wid: w for wid, w in result["workshops"].items() if w["capacity_remaining"] > 0}
    if remaining:
        log.info(f"Workshops with remaining capacity ({len(remaining)}): " +
                 ", ".join([f"{w['title']}:{w['capacity_remaining']}" for w in remaining.values()]))
    return result


@app.post("/matching/dry-run")
def dry_run():
    try:
        result = _compute()
        return jsonify({"status": "ok", "mode": "dry-run", **result})
    except Exception as e:
        log.exception("dry-run failed")
        return jsonify({"status": "error", "message": str(e)}), 500


@app.post("/matching/run")
def run_endpoint():
    try:
        result = _compute()
        by_participant = result["by_participant"]

        changed = 0
        errors: List[str] = []
        for pid, info in by_participant.items():
            new_list = info["assigned"]
            current_list = info["current_assigned"]
            if new_list == current_list:
                continue
            ok, msg = _patch_participant_assignments(pid, new_list)
            if ok:
                changed += 1
            else:
                errors.append(f"{pid}: {msg}")

        log.info(f"Patched {changed} participants with new assignments. Errors: {len(errors)}")

        payload = {"status": "ok", "mode": "run", "patched": changed, **result}
        if errors:
            payload["patch_errors"] = errors[:50]
        return jsonify(payload)
    except Exception as e:
        log.exception("run failed")
        return jsonify({"status": "error", "message": str(e)}), 500


# ---------------------------- CSV helpers & exports --------------------------

def _csv_response(rows: List[Dict[str, Any]], fieldnames: List[str], filename: str) -> Response:
    sio = StringIO()
    w = csv.DictWriter(sio, fieldnames=fieldnames)
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
        fields = ["slot", "workshop_title", "workshop_id", "participant_id",
                  "first_name", "last_name", "code", "region"]
        return _csv_response(rows, fields, "assignments_by_slots.csv")
    except Exception as e:
        log.exception("export slots failed")
        return jsonify({"status": "error", "message": str(e)}), 500

@app.get("/export/slot/<int:slot_index>.csv")
def export_one_slot(slot_index: int):
    try:
        result = _compute()
        if slot_index < 1:
            slot_index = 1
        rows = [r for r in result["export_rows"] if int(r["slot"]) == int(slot_index)]
        fields = ["slot", "workshop_title", "workshop_id", "participant_id",
                  "first_name", "last_name", "code", "region"]
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
        # Teilnehmer-Metadaten pro Teilnehmer einmalig sammeln:
        meta_map = {}
        for r in result["export_rows"]:
            meta_map[r["participant_id"]] = {
                "first_name": r["first_name"], "last_name": r["last_name"],
                "code": r["code"], "region": r["region"]
            }
        for pid, info in byp.items():
            meta = meta_map.get(pid, {"first_name": "", "last_name": "", "code": "", "region": ""})
            assigned = info.get("assigned", [])
            row = {
                "participant_id": pid,
                "first_name": meta["first_name"],
                "last_name": meta["last_name"],
                "code": meta["code"],
                "region": meta["region"],
            }
            for i in range(slots):
                row[f"slot_{i+1}"] = ""
            for i, wid in enumerate(assigned[:slots]):
                title = result["workshops"].get(wid, {}).get("title", wid[:8])
                row[f"slot_{i+1}"] = title
            rows.append(row)
        fields = ["region", "last_name", "first_name", "code", "participant_id"] + [f"slot_{i+1}" for i in range(slots)]
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
            rows.append({
                "workshop_title": w["title"],
                "workshop_id": wid,
                "capacity_total": w["capacity_total"],
                "capacity_used": w["capacity_used"],
                "capacity_remaining": w["capacity_remaining"],
            })
        rows.sort(key=lambda r: (r["workshop_title"]))
        fields = ["workshop_title", "workshop_id", "capacity_total", "capacity_used", "capacity_remaining"]
        return _csv_response(rows, fields, "workshops_overview.csv")
    except Exception as e:
        log.exception("export overview failed")
        return jsonify({"status": "error", "message": str(e)}), 500

@app.get("/export/pending.csv")
def export_pending():
    """Teilnehmer ohne Wünsche (für Nachfassen)."""
    try:
        result = _compute()
        ids = set(result["participants_without_wishes"])
        # Meta aus export_rows (falls niemand exportiert wurde, direkt aus TEILNEHMER ziehen):
        rows = []
        # Fallback: einfache Liste mit IDs; Metadaten nicht garantiert verfügbar
        for pid in ids:
            rows.append({"participant_id": pid})
        fields = ["participant_id"]
        return _csv_response(rows, fields, "participants_without_wishes.csv")
    except Exception as e:
        log.exception("export pending failed")
        return jsonify({"status": "error", "message": str(e)}), 500


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5001, debug=False)
