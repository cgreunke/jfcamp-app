#!/usr/bin/env python3
import os
import random
from dataclasses import dataclass, field
from typing import Any, Dict, List, Optional, Tuple, Set
from collections import defaultdict, Counter

import requests
from flask import Flask, jsonify, request

# --------------------------------------------------------------------------------------
# Konfig
# --------------------------------------------------------------------------------------
DRUPAL_URL = os.getenv("DRUPAL_URL", "http://drupal/jsonapi").rstrip("/")
DRUPAL_LANGS = [s.strip() for s in os.getenv("DRUPAL_LANGS", "de,en").split(",") if s.strip()]
HTTP_TIMEOUT = float(os.getenv("HTTP_TIMEOUT", "20"))
PAGE_CHUNK = int(os.getenv("PAGE_CHUNK", "100"))
PUBLISHED_ONLY = os.getenv("PUBLISHED_ONLY", "1") not in ("0", "false", "False")
DEFAULT_SEED = os.getenv("MATCHING_SEED", "")  # leer = echte Zufallsquelle
SESSION = requests.Session()

app = Flask(__name__)

# --------------------------------------------------------------------------------------
# Helpers JSON:API Fetch
# --------------------------------------------------------------------------------------
def _lang_path(lang: str, path: str) -> str:
    # Drupal JSON:API: /{lang}/jsonapi/...
    base = DRUPAL_URL
    if base.endswith("/jsonapi"):
        base = base[:-8]  # strip "/jsonapi"
    return f"{base}/{lang}/jsonapi/{path.lstrip('/')}"

def _fetch_all(path: str, extra_params: Optional[Dict[str, str]] = None, *, published_only: Optional[bool] = None) -> List[Dict[str, Any]]:
    """
    Fetch all items via page[offset]/page[limit] – robust auch ohne links.next.
    Iteriert über DRUPAL_LANGS bis first non-empty Treffer; merged Duplikate per id.
    """
    if published_only is None:
        published_only = PUBLISHED_ONLY

    base_params: Dict[str, str] = {}
    if published_only:
        base_params["filter[status][value]"] = "1"

    if extra_params:
        base_params.update(extra_params)

    result_by_id: Dict[str, Dict[str, Any]] = {}

    for lang in DRUPAL_LANGS:
        url = _lang_path(lang, path)
        offset = 0
        while True:
            params = dict(base_params)
            params["page[limit]"] = str(PAGE_CHUNK)
            params["page[offset]"] = str(offset)

            resp = SESSION.get(url, params=params, timeout=HTTP_TIMEOUT)
            if resp.status_code >= 400:
                raise RuntimeError(f"GET {url} failed: {resp.status_code} {resp.text[:300]}")
            payload = resp.json()
            batch = payload.get("data", []) or []
            if isinstance(batch, dict):
                batch = [batch]

            if not batch:
                break

            for item in batch:
                result_by_id[item["id"]] = item

            offset += len(batch)

        # wenn wir in einer Sprache bereits Ergebnisse hatten, reicht das meist
        if result_by_id:
            break

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
    wishes: List[str]  # Liste von Workshop-IDs (in Prioritätsreihenfolge)
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
        "weights": {1: 1.0, 2: 0.8, 3: 0.6, 4: 0.4, 5: 0.2}
    }
    if not nodes:
        return cfg
    n = nodes[0]
    def fv(name, default=None):
        f = n.get("attributes", {}).get(name)
        return f if f not in (None, "") else default

    cfg["num_wishes"] = int(n.get("attributes", {}).get("field_num_wuensche", 5) or 5)
    cfg["num_assign"] = int(n.get("attributes", {}).get("field_num_zuteilung", 3) or 3)
    cfg["seed"] = fv("field_seed", DEFAULT_SEED) or DEFAULT_SEED
    cfg["topk_equals_slots"] = bool(n.get("attributes", {}).get("field_topk_equals_slots", True))
    cfg["slicing_mode"] = n.get("attributes", {}).get("field_slicing_mode", "relative") or "relative"
    sv = n.get("attributes", {}).get("field_slicing_value", 50)
    try:
        cfg["slicing_value"] = int(sv)
    except Exception:
        cfg["slicing_value"] = 50

    # Gewichte
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
    # Wünsche-Relationen: node/wunsch hat field_teilnehmer (1) und field_wuensche (multi, sortiert)
    wns = _fetch_all("node/wunsch", extra_params={"include": "field_wuensche,field_teilnehmer"})

    wishes_by_participant: Dict[str, List[str]] = defaultdict(list)
    # mapping included
    included = {i["id"]: i for i in sum(([x] for x in (sum([w.get("included", []) or [] for w in []], []))), [])}  # (leer, falls Drupal includes nicht liefert)
    # robust: wir lesen Beziehungen direkt aus data
    for w in wns:
        rel = w.get("relationships", {})
        tnode = rel.get("field_teilnehmer", {}).get("data")
        if not tnode:
            continue
        pid = tnode.get("id")
        wish_nodes = rel.get("field_wuensche", {}).get("data") or []
        # Reihenfolge beachten
        wish_ids = [x.get("id") for x in wish_nodes if x.get("id")]
        # nur top num_wishes
        if num_wishes > 0:
            wish_ids = wish_ids[:num_wishes]
        wishes_by_participant[pid] = wish_ids

    out: Dict[str, Participant] = {}
    for n in tns:
        attrs = n.get("attributes", {})
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
    per_user = {}
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
    if per_user:
        happy = sum(per_user.values()) / len(per_user)
    else:
        happy = 0.0
    return happy, per_user

def run_matching(participants: Dict[str, Participant], workshops: Dict[str, Workshop], cfg: Dict[str, Any]) -> Tuple[Dict[str, Dict[int, str]], Dict[str, Any]]:
    num_assign = cfg["num_assign"]
    num_wishes = cfg["num_wishes"]
    topk = num_assign if cfg.get("topk_equals_slots", True) else min(num_assign, num_wishes)
    seed = cfg.get("seed") or ""
    rng = random.Random(seed or None)

    # Kapazitäten pro Slot
    cap_per_slot: Dict[int, Dict[str, int]] = {s: {w.id: w.capacity for w in workshops.values()} for s in range(1, num_assign + 1)}

    # Teilnehmer-Reihenfolge stabilisieren (Seed)
    pids = list(participants.keys())
    rng.shuffle(pids)

    assignments: Dict[str, Dict[int, str]] = {pid: {} for pid in pids}

    # Optionales Slicing der Renner
    slicing_mode = cfg.get("slicing_mode", "off")
    slicing_value = int(cfg.get("slicing_value", 0))

    # Popularität zählen auf Basis TopK-Wünsche
    pop_counter = Counter()
    for p in participants.values():
        for wid in p.wishes[:topk]:
            pop_counter[wid] += 1
    # Schwelle: renner sind Top 20% nach Nachfrage
    renner_cut = max(1, int(0.2 * max(1, len(workshops))))
    renner_ids = {wid for wid, _ in pop_counter.most_common(renner_cut)}

    # Allowed per slot bei slicing
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

    # Belegung verfolgen pro slot
    used_per_slot: Dict[int, Counter] = {s: Counter() for s in range(1, num_assign + 1)}

    # Zuteilung: für jeden Slot, rotiere Teilnehmer (balanced fair)
    for s in range(1, num_assign + 1):
        # Rotation für Fairness
        rotated = pids[s-1:] + pids[:s-1]
        for pid in rotated:
            p = participants[pid]
            # Wünsche entlang priorität
            assigned = False
            for wid in p.wishes[:num_wishes]:
                # keine Doppel-Workshop über alle Slots
                if wid in assignments[pid].values():
                    continue
                # Kapazität?
                if cap_per_slot[s].get(wid, 0) <= 0:
                    continue
                # Slicing-Deckel für Renner
                deckel = per_slot_cap_deckel[s][wid]
                if (slicing_mode != "off") and (wid in renner_ids) and (deckel is not None):
                    if used_per_slot[s][wid] >= deckel:
                        continue
                # Zuteilen
                assignments[pid][s] = wid
                cap_per_slot[s][wid] -= 1
                used_per_slot[s][wid] += 1
                assigned = True
                break

            if assigned:
                continue

            # Filler: irgendein Workshop mit Restkapazität, der noch nicht beim TN liegt
            for wid, rest in list(cap_per_slot[s].items()):
                if rest <= 0:
                    continue
                if wid in assignments[pid].values():
                    continue
                # optional deckel nur für renner anwenden
                deckel = per_slot_cap_deckel[s][wid]
                if (slicing_mode != "off") and (wid in renner_ids) and (deckel is not None):
                    if used_per_slot[s][wid] >= deckel:
                        continue
                assignments[pid][s] = wid
                cap_per_slot[s][wid] -= 1
                used_per_slot[s][wid] += 1
                break

    # Statistik
    weights = cfg.get("weights", {1:1.0,2:0.8,3:0.6,4:0.4,5:0.2})
    happy, per_user_happy = compute_happy_index(assignments, {k:v.wishes for k,v in participants.items()}, weights, topk)
    per_slot_counts = {s: len([1 for pid in pids if assignments[pid].get(s)]) for s in range(1, num_assign+1)}
    assign_dist = Counter([len(assignments[pid]) for pid in pids])
    cap_total = sum(w.capacity for w in workshops.values()) * num_assign
    cap_remaining = sum(sum(max(0, x) for x in cap_per_slot[s].values()) for s in cap_per_slot)
    filler_count = 0
    for pid in pids:
        for s, wid in assignments[pid].items():
            if wid not in participants[pid].wishes[:num_wishes]:
                filler_count += 1

    # unfilled workshops per slot (remaining)
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
        "per_priority_fulfilled": dict({i:0 for i in range(1, num_wishes+1)}),
        "capacity_total": cap_total,
        "capacity_remaining_total": cap_remaining,
        "filler_assignments": filler_count,
        "unfilled_workshops_count": len(unfilled),
        "warning_capacity_deficit": max(0, (len(pids) * num_assign) - cap_total),
        "all_filled_to_slots": all(len(assignments[pid]) == num_assign for pid in pids),
        "happy_index": round(happy, 4),
    }

    # Prioritäten-Erfüllung zählen
    for pid in pids:
        wish_list = participants[pid].wishes[:num_wishes]
        for s, wid in assignments[pid].items():
            if wid in wish_list:
                prio = wish_list.index(wid) + 1
                summary["per_priority_fulfilled"][prio] = summary["per_priority_fulfilled"].get(prio, 0) + 1

    meta = {
        "unfilled_workshops": unfilled,
    }
    return assignments, {"summary": summary, **meta}

# --------------------------------------------------------------------------------------
# API
# --------------------------------------------------------------------------------------
@app.get("/matching/health")
def health():
    return jsonify({"status": "ok"})

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
        "counts_seen": {
            "teilnehmer": len(participants),
            "workshops": len(workshops),
            "wunsch_nodes": len(_fetch_all("node/wunsch")),
        },
    })

@app.get("/matching/probe")
def probe():
    cfg = load_matching_config()
    workshops = load_workshops()
    participants = load_participants_and_wishes(cfg["num_wishes"])
    sample = list(participants.keys())[:10]
    return jsonify({
        "status":"ok",
        "published_only": PUBLISHED_ONLY,
        "participants_seen": len(participants),
        "wunsch_nodes_seen": len(_fetch_all("node/wunsch")),
        "teilnehmer_nodes_raw": len(participants),
        "sample_participant_ids": sample,
        "duplicate_codes_count": 0,  # optional: hier könntest du deduplizieren
        "duplicate_codes_example": {},
    })

@app.get("/matching/probe/missing")
def probe_missing():
    cfg = load_matching_config()
    participants = load_participants_and_wishes(cfg["num_wishes"])
    missing = [pid for pid, p in participants.items() if not p.wishes]
    sample = missing[:0]  # bewusst leer lassen, um keine IDs zu leaken
    return jsonify({"status":"ok", "missing_count": len(missing), "missing_sample_ids": sample})

@app.get("/matching/stats")
def stats():
    cfg = load_matching_config()
    workshops = load_workshops()
    participants = load_participants_and_wishes(cfg["num_wishes"])

    # Kapazitätsfelder-Histogramm (hier stets String)
    cap_fields_counter = Counter((w.capacity_field_used or "UNDETECTED") for w in workshops.values())

    # Popularität (TopK) über Wünsche
    topk = cfg["num_assign"] if cfg.get("topk_equals_slots", True) else min(cfg["num_assign"], cfg["num_wishes"])
    popularity = Counter()
    for p in participants.values():
        for wid in p.wishes[:topk]:
            popularity[wid] += 1

    pop_preview = [
        {"id": wid, "title": workshops.get(wid, Workshop(wid,"?",0)).title, "topk_demand": cnt}
        for wid, cnt in popularity.most_common(10)
    ]

    capacity_preview = [
        {"id": w.id, "title": w.title, "capacity": w.capacity}
        for w in workshops.values()
    ]

    wishes_hist = Counter(len(p.wishes) for p in participants.values())

    return jsonify({
        "status":"ok",
        "published_only": PUBLISHED_ONLY,
        "config": {"num_assign": cfg["num_assign"], "num_wishes": cfg["num_wishes"]},
        "counts": {
            "teilnehmer_seen": len(participants),
            "workshops": len(workshops),
        },
        "wishes_per_participant_histogram": dict(wishes_hist),
        "capacity_fields_used_histogram": dict(cap_fields_counter),
        "popularity_topk_preview": pop_preview,
        "capacity_preview": capacity_preview,
    })

@app.post("/matching/dry-run")
def dry_run():
    cfg = load_matching_config()
    workshops = load_workshops()
    participants = load_participants_and_wishes(cfg["num_wishes"])
    assignments, meta = run_matching(participants, workshops, cfg)

    # Export-ähnliche Darstellung
    rows = []
    for pid, slots in assignments.items():
        for s, wid in sorted(slots.items()):
            rows.append({
                "participant_id": pid,
                "slot": s,
                "workshop_id": wid,
                "workshop_title": workshops.get(wid, Workshop(wid,"?",0)).title,
            })

    return jsonify({
        "status":"ok",
        "mode":"dry-run",
        "summary": meta["summary"],
        "unfilled_workshops": meta["unfilled_workshops"],
        "assignments_by_slot": {str(s): [r for r in rows if r["slot"]==s] for s in range(1, cfg["num_assign"]+1)},
        "by_participant": {pid: {str(s): wid for s, wid in slots.items()} for pid, slots in assignments.items()},
        "export_rows": rows[:2000],  # harte Kappung für Response
    })

if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5001)
