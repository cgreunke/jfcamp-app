from dataclasses import dataclass
from typing import List, Optional

from input_fingerprint import build_input_fingerprint


@dataclass
class Participant:
    id: str
    code: str
    wishes: List[str]
    region: Optional[str] = None


@dataclass
class Workshop:
    id: str
    title: str
    capacity: int
    capacity_field_used: str = "field_maximale_plaetze"


def _fixture():
    cfg = {
        "num_wishes": 3,
        "num_assign": 2,
        "weights_mode": "linear",
        "weights": {
            1: 1.0,
            2: 0.6,
            3: 0.2,
        },
        "seed": 42,
        "_progress_run_id": 123,
    }

    participants = {
        "p2": Participant(
            id="p2",
            code="CODE2",
            region="Region B",
            wishes=["w2", "w1", "w3"],
        ),
        "p1": Participant(
            id="p1",
            code="CODE1",
            region="Region A",
            wishes=["w1", "w2", "w3"],
        ),
    }

    workshops = {
        "w2": Workshop(
            id="w2",
            title="Workshop 2",
            capacity=8,
        ),
        "w1": Workshop(
            id="w1",
            title="Workshop 1",
            capacity=10,
        ),
        "w3": Workshop(
            id="w3",
            title="Workshop 3",
            capacity=12,
        ),
    }

    return cfg, participants, workshops


def test_same_input_produces_same_fingerprint():
    cfg, participants, workshops = _fixture()

    fingerprint_1, version_1 = build_input_fingerprint(
        cfg,
        participants,
        workshops,
    )
    fingerprint_2, version_2 = build_input_fingerprint(
        cfg,
        participants,
        workshops,
    )

    assert fingerprint_1 == fingerprint_2
    assert version_1 == version_2 == 1


def test_participant_and_workshop_mapping_order_does_not_matter():
    cfg, participants, workshops = _fixture()

    fingerprint_1, _ = build_input_fingerprint(
        cfg,
        participants,
        workshops,
    )

    reordered_participants = dict(reversed(list(participants.items())))
    reordered_workshops = dict(reversed(list(workshops.items())))

    fingerprint_2, _ = build_input_fingerprint(
        cfg,
        reordered_participants,
        reordered_workshops,
    )

    assert fingerprint_1 == fingerprint_2


def test_progress_run_id_does_not_affect_fingerprint():
    cfg, participants, workshops = _fixture()

    fingerprint_1, _ = build_input_fingerprint(
        cfg,
        participants,
        workshops,
    )

    cfg["_progress_run_id"] = 999999

    fingerprint_2, _ = build_input_fingerprint(
        cfg,
        participants,
        workshops,
    )

    assert fingerprint_1 == fingerprint_2


def test_wish_order_changes_fingerprint():
    cfg, participants, workshops = _fixture()

    fingerprint_1, _ = build_input_fingerprint(
        cfg,
        participants,
        workshops,
    )

    participants["p1"].wishes = ["w2", "w1", "w3"]

    fingerprint_2, _ = build_input_fingerprint(
        cfg,
        participants,
        workshops,
    )

    assert fingerprint_1 != fingerprint_2


def test_changed_wishes_change_fingerprint():
    cfg, participants, workshops = _fixture()

    fingerprint_1, _ = build_input_fingerprint(
        cfg,
        participants,
        workshops,
    )

    participants["p1"].wishes = ["w1", "w3"]

    fingerprint_2, _ = build_input_fingerprint(
        cfg,
        participants,
        workshops,
    )

    assert fingerprint_1 != fingerprint_2


def test_changed_capacity_changes_fingerprint():
    cfg, participants, workshops = _fixture()

    fingerprint_1, _ = build_input_fingerprint(
        cfg,
        participants,
        workshops,
    )

    workshops["w1"].capacity = 11

    fingerprint_2, _ = build_input_fingerprint(
        cfg,
        participants,
        workshops,
    )

    assert fingerprint_1 != fingerprint_2


def test_changed_effective_config_changes_fingerprint():
    cfg, participants, workshops = _fixture()

    fingerprint_1, _ = build_input_fingerprint(
        cfg,
        participants,
        workshops,
    )

    cfg["num_assign"] = 3

    fingerprint_2, _ = build_input_fingerprint(
        cfg,
        participants,
        workshops,
    )

    assert fingerprint_1 != fingerprint_2