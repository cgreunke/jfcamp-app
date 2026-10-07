"""Deterministic fingerprint of the effective JF Camp matching input."""

from __future__ import annotations

import hashlib
import json
from typing import Any, Dict, Mapping, Tuple

INPUT_FINGERPRINT_VERSION = 1


def _normalize(value: Any) -> Any:
    """Return a JSON-stable representation while preserving list order."""
    if isinstance(value, Mapping):
        return {
            str(key): _normalize(item)
            for key, item in sorted(value.items(), key=lambda pair: str(pair[0]))
        }
    if isinstance(value, (list, tuple)):
        return [_normalize(item) for item in value]
    if isinstance(value, set):
        return sorted((_normalize(item) for item in value), key=lambda item: repr(item))
    if value is None or isinstance(value, (str, int, float, bool)):
        return value
    raise TypeError(
        f"Unsupported value in matching input fingerprint: {type(value).__name__}"
    )


def build_effective_config_snapshot(cfg: Mapping[str, Any]) -> Dict[str, Any]:
    """Return the deterministic solver config persisted as run provenance.

    Keys prefixed with ``_`` are technical runtime metadata (for example the
    Drupal progress run id) and are deliberately not part of solver provenance.
    """
    effective_config = {
        str(key): value
        for key, value in cfg.items()
        if not str(key).startswith("_")
    }
    return _normalize(effective_config)


def canonical_matching_input(
    cfg: Mapping[str, Any],
    participants: Mapping[str, Any],
    workshops: Mapping[str, Any],
) -> Dict[str, Any]:
    """Build the versioned canonical structure that is hashed for stale detection."""
    participant_rows = [
        {
            "id": str(pid),
            # Wish order is semantically significant and must be preserved.
            "wishes": [str(wid) for wid in participants[pid].wishes],
        }
        for pid in sorted(participants.keys(), key=str)
    ]

    workshop_rows = [
        {
            "id": str(wid),
            "capacity": int(workshops[wid].capacity),
        }
        for wid in sorted(workshops.keys(), key=str)
    ]

    return _normalize(
        {
            "version": INPUT_FINGERPRINT_VERSION,
            "config": build_effective_config_snapshot(cfg),
            "participants": participant_rows,
            "workshops": workshop_rows,
        }
    )


def build_input_fingerprint(
    cfg: Mapping[str, Any],
    participants: Mapping[str, Any],
    workshops: Mapping[str, Any],
) -> Tuple[str, int]:
    """Return SHA-256 and format version for the effective matching input."""
    canonical = canonical_matching_input(cfg, participants, workshops)
    payload = json.dumps(
        canonical,
        ensure_ascii=False,
        sort_keys=True,
        separators=(",", ":"),
        allow_nan=False,
    ).encode("utf-8")
    return hashlib.sha256(payload).hexdigest(), INPUT_FINGERPRINT_VERSION
