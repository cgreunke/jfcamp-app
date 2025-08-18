import os
import random
from typing import Any, Dict, List, Optional, Tuple
from collections import Counter, defaultdict
from dataclasses import dataclass, field

from flask import Flask, jsonify, request
import requests

# =========================
# Basis-Konfiguration
# =========================
DRUPAL_URL = os.getenv("DRUPAL_URL", "http://drupal/jsonapi").rstrip("/")
HTTP_TIMEOUT = float(os.getenv("HTTP_TIMEOUT", "20"))
PAGE_CHUNK = int(os.getenv("PAGE_CHUNK", "200"))
PUBLISHED_ONLY = os.getenv("PUBLISHED_ONLY", "true").lower() in ("1", "true", "yes", "on")
LANGS = [s.strip() for s in os.getenv("DRUPAL_LANGS", "de,und,en").split(",") if s.strip()]
RANDOM_SEED = int(os.getenv("MATCHING_SEED", "2027"))

# Fallback-Werte – werden ggf. durch Matching-Config aus Drupal überschrieben
NUM_SLOTS_DEFAULT = int(os.getenv("NUM_SLOTS", "3"))
NUM_WISHES_DEFAULT = int(os.getenv("NUM_WISHES", "5"))

SESSION = requests.Session()
app = Flask(__name__)

# =========================
# Datenmodelle
# =========================
@dataclass
class Workshop:
    id: str
    title: str
    capacity_total: int  # Summe über alle Slots
    capacity_field_used: Optional[str] = None
    attr_langcode: Optional[str] = None

@dataclass
class Participant:
    id: str
    code: str
    first_name: str
    last_name: str
    region: Optional[str] = None
    wishes: List[str] = field(default_factory=list)

# =========================
# Helper
# =========================
def _lang_filters() -> Dict[str, Any]:
    params: Dict[str, Any] = {
        "filter[langcode][condition][path]": "langcode",
        "filter[langcode][condition][operator]": "IN",
    }
    for i, lc in enumerate(LANGS):
        params[f"filter[langcode][condition][value][{i}]"] = lc
    return params

def _status_filter() -> Dict[str, str]:
    return {"filter[status][value]": "1"} if PUBLISHED_ONLY else {}

def _fetch_all(url: str, extra_params: Optional[Dict[str, Any]] = None) -> List[Dict[str, Any]]:
    base_params: Dict[str, Any] = {}
    base_params.update(_lang_filters())
    base_params.update(_status_filter())
    if extra_params:
        base_params.update(extra_params)

    items: List[Dict[str, Any]] = []
    offset = 0
    while True:
        params = dict(base_params)
        params["page[limit]"] = str(PAGE_CHUNK)
        params["page[offset]"] = str(offset)

        resp = SESSION.get(url, params=params, timeout=HTTP_TIMEOUT)
        if resp.status_code >= 400:
            raise RuntimeError(f"GET {url} failed: {resp.status_code} {resp.text[:400]}")
        payload = resp.json()
        batch = payload.get("data", []) or []
        if isinstance(batch, dict):
            batch = [batch]
        if not batch:
            break
        items.extend(batch)
        offset += len(batch)
    return items

def _attr(node: Dict[str, Any], key: str, default=None):
    return ((node.get("attributes") or {}).get(key, default))

def _rel_ids(node: Dict[str, Any], rel: str) -> List[str]:
    rel_data = (((node.get("relationships") or {}).get(rel) or {}).get("data"))
    if not rel_data:
        return []
    if isinstance(rel_data, dict):
        rel_data = [rel_data]
    ids = []
    for x in rel_data:
        nid = x.get("id")
        if nid:
            ids.append(nid)
    return ids

def _rel_id(node: Dict[str, Any], rel: str) -> Optional[str]:
    arr = _rel_ids(node, rel)
    return arr[0] if arr else None

def _parse_int(val: Any, default: int = 0) -> int:
    if val is None:
        return default
    try:
        if isinstance(val, (int, float)):
            return int(val)
        s = str(val).strip()
        if s == "":
            return default
        return int(float(s.replace(",", ".")))
    except Exception:
        return default

# =========================
# Kapazitätserkennung
# =========================
# Klarer Primärschlüssel in deinem Setup:
_CAPACITY_FIELD_CANDIDATES = [
    "field_maximale_plaetze",  # <-- wichtig
    # zusätzliche übliche Varianten als Fallback:
    "field_max",
    "field_capacity",
    "field_kapazitaet",
    "field_kapazität",
    "field_max_teilnehmer",
    "max",
]
_CAPACITY_SUBSTRINGS = ["max", "kapaz", "capacity", "teilnehm", "plätze", "plaetze", "anzahl"]

def _detect_capacity_and_field(attr: Dict[str, Any]) -> Tuple[int, Optional[str]]:
    # 1) bevorzugte Kandidaten
    for key in _CAPACITY_FIELD_CANDIDATES:
        if key in attr:
            v = _parse_int(attr.get(key), 0)
            if v > 0:
                return v, key
    # 2) fuzzy: alle Attribute durchsuchen
    for k, v in attr.items():
        lk = k.lower()
        if any(sub in lk for sub in _CAPACITY_SUBSTRINGS):
            iv = _parse_int(v, 0)
            if iv > 0:
                return iv, k
    return 0, None

# =========================
# Loader
# =========================
def load_workshops(num_slots: int) -> Dict[str, Workshop]:
    url = f"{DRUPAL_URL}/node/workshop"
    nodes = _fetch_all(url)
    workshops: Dict[str, Workshop] = {}
    for n in nodes:
        nid = n.get("id")
        attr = (n.get("attributes") or {})
        title = attr.get("title", "") or ""
        cap_val, cap_field = _detect_capacity_and_field(attr)
        langcode = attr.get("langcode")
        if nid and title:
            total = cap_val * num_slots if cap_val > 0 else 0
            workshops[nid] = Workshop(
                id=nid,
                title=title,
                capacity_total=total,
                capacity_field_used=cap_field,
                attr_langcode=langcode,
            )
    return workshops

def load_participants() -> Dict[str, Participant]:
    url = f"{DRUPAL_URL}/node/teilnehmer"
    nodes = _fetch_all(url)
    participants: Dict[str, Participant] = {}
    for n in nodes:
        nid = n.get("id")
        code = (_attr(n, "field_code", _attr(n, "code", "")) or "").strip()
        first_name = (_attr(n, "field_vorname", _attr(n, "vorname", "")) or "").strip()
        last_name  = (_attr(n, "field_name", _attr(n, "nachname", "")) or "").strip()
        region     = _attr(n, "field_regionalverband", _attr(n, "regionalverband", None))
        if not nid:
            continue
        participants[nid] = Participant(
            id=nid, code=code, first_name=first_name, last_name=last_name, region=region or None
        )
    return participants

def load_wishes(participants: Dict[str, Participant], workshops: Dict[str, Workshop], num_wishes: int) -> Tuple[int, int]:
    url = f"{DRUPAL_URL}/node/wunsch"
    nodes = _fetch_all(url)
    seen = len(nodes)
    mapped = 0
    for n in nodes:
        p_id = _rel_id(n, "field_teilnehmer")
        if not p_id or p_id not in participants:
            continue
        wish_ids = _rel_ids(n, "field_wuensche")
        if not wish_ids:
            continue
        cleaned = [w for w in wish_ids if w in workshops]
        if not cleaned:
            continue
        participants[p_id].wishes = cleaned[:num_wishes]
        mapped += 1
    return seen, mapped

def load_matching_config() -> Tuple[int, int, Dict[str, Any]]:
    """
    Liest die veröffentlichte Matching-Konfiguration (falls vorhanden).
    Gibt (num_wishes, num_slots, info) zurück.
    """
    url = f"{DRUPAL_URL}/node/matching_config"
    nodes = _fetch_all(url)
    # Wenn es mehrere gibt, nimm einfach die erste veröffentlichte (Erweiterbar: sort by changed desc)
    num_wishes = NUM_WISHES_DEFAULT
    num_slots = NUM_SLOTS_DEFAULT
    picked = None
    for n in nodes:
        # published-Filter ist bereits aktiv, wenn PUBLISHED_ONLY True ist
        # ansonsten prüfen wir status sicherheitshalber:
        status = _attr(n, "status", 1)
        if status != 1 and PUBLISHED_ONLY:
            continue
        nw = _parse_int(_attr(n, "field_num_wuensche"), 0)
        ns = _parse_int(_attr(n, "field_num_zuteilung"), 0)
        if nw > 0:
            num_wishes = nw
        if ns > 0:
            num_slots = ns
        picked = n
        break
    info = {
        "source": "drupal" if picked else "env",
        "num_wishes": num_wishes,
        "num_slots": num_slots,
    }
    return num_wishes, num_slots, info

# =========================
# Matching (round-robin + fair)
# =========================
def run_matching(participants: Dict[str, Participant], workshops: Dict[str, Workshop], num_slots: int, num_wishes: int) -> Dict[str, Any]:
    random.seed(RANDOM_SEED)

    remaining_capacity = {w.id: w.capacity_total for w in workshops.values()}
    per_slot_assignments: Dict[int, List[Tuple[str, str]]] = {s: [] for s in range(1, num_slots + 1)}
    by_participant: Dict[str, List[Tuple[int, str]]] = {p_id: [] for p_id in participants.keys()}

    order = list(participants.keys())
    random.shuffle(order)
    filler_assignments = 0

    for slot in range(1, num_slots + 1):
        # Wünsche 1..num_wishes
        for pr_idx in range(num_wishes):
            for p in order:
                if any(s == slot for (s, _) in by_participant[p]):
                    continue
                wishes = participants[p].wishes
                if pr_idx < len(wishes):
                    w_id = wishes[pr_idx]
                    if remaining_capacity.get(w_id, 0) > 0:
                        per_slot_assignments[slot].append((p, w_id))
                        by_participant[p].append((slot, w_id))
                        remaining_capacity[w_id] -= 1
        # Filler, falls noch frei und TN leer im Slot
        empty_now = [p for p in order if not any(s == slot for (s, _) in by_participant[p])]
        if empty_now:
            fillable = [w for w, rem in remaining_capacity.items() if rem > 0]
            fi = 0
            for p in empty_now:
                if fi >= len(fillable):
                    break
                w_id = fillable[fi]
                per_slot_assignments[slot].append((p, w_id))
                by_participant[p].append((slot, w_id))
                remaining_capacity[w_id] -= 1
                filler_assignments += 1
                if remaining_capacity[w_id] == 0:
                    fi += 1

    per_slot_counts = {s: len(per_slot_assignments[s]) for s in per_slot_assignments}
    total_assigned = sum(per_slot_counts.values())
    cap_total = sum(w.capacity_total for w in workshops.values())
    cap_remaining = max(0, cap_total - total_assigned)
    assign_dist = Counter(len(v) for v in by_participant.values())

    per_prio = Counter()
    for pid, pairs in by_participant.items():
        wishes = participants[pid].wishes
        for _, w in pairs:
            if w in wishes:
                per_prio[wishes.index(w) + 1] += 1

    unfilled = []
    for w in workshops.values():
        rem = remaining_capacity.get(w.id, 0)
        if rem > 0:
            unfilled.append({"id": w.id, "title": w.title, "remaining": rem})

    participants_no_wishes = sum(1 for p in participants.values() if not p.wishes)

    return {
        "assignments_by_slot": {s: [{"participant_id": p, "workshop_id": w} for (p, w) in per_slot_assignments[s]] for s in per_slot_assignments},
        "by_participant": {p: [{"slot": s, "workshop_id": w} for (s, w) in pairs] for p, pairs in by_participant.items()},
        "summary": {
            "seed": str(RANDOM_SEED),
            "participants_total": len(participants),
            "participants_no_wishes": participants_no_wishes,
            "assignments_total": total_assigned,
            "per_slot_assigned_counts": per_slot_counts,
            "capacity_total": cap_total,
            "capacity_remaining_total": cap_remaining,
            "assignment_distribution": dict(assign_dist),
            "per_priority_fulfilled": dict(per_prio),
            "unfilled_workshops_count": len(unfilled),
            "filler_assignments": filler_assignments,
            "target_assignments_total": num_slots * len(participants),
            "all_filled_to_slots": (assign_dist.get(num_slots, 0) == len(participants)),
            "warning_capacity_deficit": max(0, num_slots * len(participants) - cap_total),
        },
        "unfilled_workshops": sorted(unfilled, key=lambda x: -x["remaining"]),
        "status": "ok",
        "mode": "dry-run",
    }

# =========================
# HTTP Endpoints
# =========================
@app.route("/matching/config", methods=["GET"])
def show_config():
    num_wishes, num_slots, info = load_matching_config()
    return jsonify({
        "status": "ok",
        "published_only": PUBLISHED_ONLY,
        "langs": LANGS,
        "source": info["source"],
        "num_wishes": num_wishes,
        "num_slots": num_slots,
        "env_defaults": {"NUM_WISHES_DEFAULT": NUM_WISHES_DEFAULT, "NUM_SLOTS_DEFAULT": NUM_SLOTS_DEFAULT},
    })

@app.route("/matching/probe", methods=["GET"])
def probe():
    num_wishes, num_slots, _ = load_matching_config()
    teilnehmer = load_participants()
    workshops = load_workshops(num_slots)
    wishes_seen, wishes_mapped = load_wishes(teilnehmer, workshops, num_wishes)

    codes = [p.code for p in teilnehmer.values() if p.code]
    dup_map = defaultdict(list)
    for p in teilnehmer.values():
        if p.code:
            dup_map[p.code].append(p.id)
    dup = {k: v for k, v in dup_map.items() if len(v) > 1}

    return jsonify({
        "status": "ok",
        "published_only": PUBLISHED_ONLY,
        "participants_seen": len(teilnehmer),
        "teilnehmer_nodes_raw": len(teilnehmer),
        "wunsch_nodes_seen": wishes_seen,
        "duplicate_codes_count": len(dup),
        "duplicate_codes_example": dict(list(dup.items())[:1]),
        "sample_participant_ids": list(teilnehmer.keys())[:10],
    })

@app.route("/matching/stats", methods=["GET"])
def stats():
    num_wishes, num_slots, info = load_matching_config()
    teilnehmer = load_participants()
    workshops = load_workshops(num_slots)
    wishes_seen, wishes_mapped = load_wishes(teilnehmer, workshops, num_wishes)
    hist = Counter(len(p.wishes) for p in teilnehmer.values())

    def _norm_cap_key(v):
        if isinstance(v, (list, tuple)):
            return ",".join(str(x) for x in v)
        return str(v or "UNDETECTED")

    cap_fields_counter = Counter(_norm_cap_key(w.capacity_field_used) for w in workshops.values())

    cap_preview = {
        w.id: {
            "title": w.title,
            "capacity_total": w.capacity_total,
            "capacity_field_used": w.capacity_field_used,
            "langcode": w.attr_langcode,
        } for w in list(workshops.values())[:5]
    }

    return jsonify({
        "status": "ok",
        "published_only": PUBLISHED_ONLY,
        "langs": LANGS,
        "config_source": info["source"],
        "config": {"num_assign": num_slots, "num_wishes": num_wishes},
        "counts": {
            "teilnehmer_raw": len(teilnehmer),
            "teilnehmer_seen": len(teilnehmer),
            "workshops": len(workshops),
            "wunsch_nodes": wishes_seen,
            "wunsch_mapped_to_participants": wishes_mapped,
        },
        "capacity_fields_used_histogram": dict(cap_fields_counter),
        "capacity_preview": cap_preview,
        "wishes_per_participant_histogram": dict(sorted(hist.items())),
    })

@app.route("/matching/dry-run", methods=["POST"])
def dry_run():
    num_wishes, num_slots, info = load_matching_config()
    teilnehmer = load_participants()
    workshops = load_workshops(num_slots)
    wishes_seen, _ = load_wishes(teilnehmer, workshops, num_wishes)
    result = run_matching(teilnehmer, workshops, num_slots, num_wishes)
    result["summary"]["source_counts"] = {
        "published_only": PUBLISHED_ONLY,
        "teilnehmer_all": len(teilnehmer),
        "teilnehmer_seen": len(teilnehmer),
        "workshops_all": len(workshops),
        "workshops_seen": len(workshops),
        "wunsch_all": wishes_seen,
        "wunsch_nodes_seen": wishes_seen,
    }
    result["summary"]["config_source"] = info["source"]
    return jsonify(result)

@app.route("/matching/debug", methods=["GET"])
def debug():
    num_wishes, num_slots, info = load_matching_config()
    teilnehmer = load_participants()
    workshops = load_workshops(num_slots)
    wishes_seen, _ = load_wishes(teilnehmer, workshops, num_wishes)
    return jsonify({
        "status": "ok",
        "published_only": PUBLISHED_ONLY,
        "langs": LANGS,
        "config_source": info["source"],
        "counts_all": {"teilnehmer_all": len(teilnehmer), "workshops_all": len(workshops), "wunsch_all": wishes_seen},
        "counts_seen": {"teilnehmer": len(teilnehmer), "workshops": len(workshops), "wunsch_nodes": wishes_seen},
    })

@app.route("/matching/inspect/workshops", methods=["GET"])
def inspect_workshops():
    """
    Diagnose: zeigt, welches Feld wir als Kapazität genommen haben.
    ?limit=10  -> Anzahl der Zeilen
    """
    limit = int(request.args.get("limit", "10"))
    num_wishes, num_slots, _ = load_matching_config()
    url = f"{DRUPAL_URL}/node/workshop"
    nodes = _fetch_all(url)
    out = []
    for n in nodes[:limit]:
        nid = n.get("id")
        attr = (n.get("attributes") or {})
        title = attr.get("title")
        langcode = attr.get("langcode")
        cap_val, cap_field = _detect_capacity_and_field(attr)
        out.append({
            "id": nid,
            "title": title,
            "langcode": langcode,
            "detected_capacity_value_single_slot": cap_val,
            "detected_capacity_field": cap_field,
            "calculated_capacity_total_over_slots": cap_val * num_slots if cap_val > 0 else 0,
            "attribute_keys": list(attr.keys())[:40],
        })
    return jsonify({"status": "ok", "items": out})

if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5001, debug=False)
