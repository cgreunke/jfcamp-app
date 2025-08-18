
import os
import json
import logging
import hashlib
from typing import Dict, List, Tuple, Any
from dataclasses import dataclass, field

import requests
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry
from flask import Flask, jsonify, request

# -----------------------------------------------------------------------------
# Config
# -----------------------------------------------------------------------------

DRUPAL_URL = os.getenv("DRUPAL_URL", "http://drupal/jsonapi").rstrip("/")
DRUPAL_USER = os.getenv("DRUPAL_USER", "")
DRUPAL_PASS = os.getenv("DRUPAL_PASS", "")
MATCHING_SEED = os.getenv("MATCHING_SEED")  # optional fixed seed
HTTP_TIMEOUT = float(os.getenv("HTTP_TIMEOUT", "10"))
MAX_PAGE_LIMIT = int(os.getenv("MAX_PAGE_LIMIT", "2000"))

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
    wishes: List[str] = field(default_factory=list)  # ordered by priority (1..N)
    assigned: List[str] = field(default_factory=list)
    current_assigned: List[str] = field(default_factory=list)  # what Drupal currently has
    tie_break: int = 0  # deterministic random for fairness order


@dataclass
class MatchingConfig:
    num_wishes: int
    num_assign: int


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
        # JSON:API pagination might use 'links' with 'next'
        links = payload.get("links", {})
        next_link = links.get("next", {}).get("href")
        if not next_link or len(data) < int(params["page[limit]"]):
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
    # Load participants
    participant_items = _fetch_all(EP_TEILNEHMER)
    participants: Dict[str, Participant] = {
        it["id"]: Participant(
            id=it["id"],
            current_assigned=[rid["id"] for rid in (it.get("relationships", {})
                                                    .get("field_zugewiesen", {})
                                                    .get("data", []) or [])]
        )
        for it in participant_items
    }

    # Load 'wunsch' nodes; if multiple per participant, keep the latest by created
    wunsch_items = _fetch_all(EP_WUNSCH, {"sort": "-created"})
    seen_pids = set()
    for wn in wunsch_items:
        rel = wn.get("relationships", {})
        teil_rel = rel.get("field_teilnehmer", {}).get("data")
        if not teil_rel:
            continue
        pid = teil_rel.get("id")
        if not pid:
            continue
        if pid in seen_pids:
            continue  # we already took the latest
        seen_pids.add(pid)

        wish_rel = rel.get("field_wuensche", {}).get("data", []) or []
        wish_ids = [r["id"] for r in wish_rel if r.get("id") in workshops]
        # Enforce configured max wishes
        wish_ids = wish_ids[: cfg.num_wishes]
        if pid not in participants:
            # Some wish may refer to a participant that isn't published; skip
            continue
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
            # fall back to hash of provided string
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

    return int.from_bytes(hasher.digest()[:8], "big")  # 64-bit int


# -----------------------------------------------------------------------------
# Matching algorithm: layered rounds (priority 1..N), fairness by tie-break & fewest assigned first
# -----------------------------------------------------------------------------

def run_matching(workshops: Dict[str, Workshop], participants: Dict[str, Participant], cfg: MatchingConfig) -> Dict[str, Any]:
    # Determine deterministic tie-break order
    seed = compute_seed(workshops, participants, cfg)
    rnd = hashlib.sha256(f"{seed}".encode()).digest()
    # Assign a stable integer tie-break per participant from the digest stream
    # (This avoids relying on random.shuffle order across Python versions.)
    # We chunk the digest as needed; if more participants than bytes, re-hash.
    pids = list(participants.keys())
    tiebreak_map: Dict[str, int] = {}
    digest = rnd
    di = 0
    for pid in pids:
        if di + 4 > len(digest):
            digest = hashlib.sha256(digest).digest()
            di = 0
        val = int.from_bytes(digest[di:di+4], "big")
        tiebreak_map[pid] = val
        di += 4

    for pid, p in participants.items():
        p.assigned = []
        p.tie_break = tiebreak_map[pid]

    # Metrics
    per_priority_fulfilled: Dict[int, int] = {}
    total_capacity = sum(w.capacity_remaining for w in workshops.values())

    # Rounds: at most cfg.num_assign, but cannot exceed the max wishes anyone made
    max_possible_rounds = min(cfg.num_assign, cfg.num_wishes)
    for r in range(max_possible_rounds):  # r = 0 -> priority 1
        if total_capacity <= 0:
            break

        # Order: fewest assigned first, then tie-break
        order = sorted(participants.values(), key=lambda p: (len(p.assigned), p.tie_break))

        round_assignments = 0
        for p in order:
            if len(p.assigned) >= cfg.num_assign:
                continue
            if r >= len(p.wishes):
                continue
            desired_wid = p.wishes[r]
            w = workshops.get(desired_wid)
            if not w:
                # wish references non-existing/filtered workshop
                continue
            if w.capacity_remaining <= 0:
                continue
            if desired_wid in p.assigned:
                continue

            # Assign
            p.assigned.append(desired_wid)
            w.capacity_remaining -= 1
            total_capacity -= 1
            round_assignments += 1
            per_priority_fulfilled[r + 1] = per_priority_fulfilled.get(r + 1, 0) + 1

        log.info(f"Round {r+1}: assigned {round_assignments} slots. Remaining capacity: {total_capacity}")

        # Early exit if nobody got anything in this round
        if round_assignments == 0:
            continue

    # Build metrics
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

    summary = {
        "participants_total": len(participants),
        "assignments_total": sum(len(p.assigned) for p in participants.values()),
        "participants_no_wishes": sum(1 for p in participants.values() if len(p.wishes) == 0),
        "per_priority_fulfilled": {str(k): v for k, v in sorted(per_priority_fulfilled.items())},
        "assignment_distribution": dist,  # how many participants have 0/1/.. assignments
        "seed": str(seed),
    }

    return {
        "summary": summary,
        "workshops": by_workshop,
        "by_participant": by_participant,
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

    # Log basic counts
    log.info(f"Loaded {len(workshops)} workshops, {len(participants)} participants, cfg(num_wishes={cfg.num_wishes}, num_assign={cfg.num_assign})")

    # Remove workshops with zero capacity from consideration
    zero_caps = [w.id for w in workshops.values() if w.capacity_total <= 0]
    if zero_caps:
        log.info(f"Skipping {len(zero_caps)} workshops with capacity 0.")
    # (We still keep them in the dict to report metrics, the algorithm won't assign to them anyway.)

    result = run_matching(workshops, participants, cfg)

    # Log summary
    s = result["summary"]
    log.info(f"Summary: participants={s['participants_total']} assignments={s['assignments_total']} no_wishes={s['participants_no_wishes']}")
    log.info(f"Per-priority fulfilled: {s['per_priority_fulfilled']}")
    # Log remaining capacity per workshop (only those with remaining > 0 for brevity)
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

        # Only PATCH when assignments actually change
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
            payload["patch_errors"] = errors[:50]  # do not explode the payload
        return jsonify(payload)
    except Exception as e:
        log.exception("run failed")
        return jsonify({"status": "error", "message": str(e)}), 500


if __name__ == "__main__":
    # For local dev only; use Gunicorn in production.
    app.run(host="0.0.0.0", port=5001, debug=False)
