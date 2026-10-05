import pytest

from matching.matching_server import Participant, Workshop, run_matching_fair


@pytest.mark.xfail(
    strict=True,
    reason=(
        "Known slot-wise dead end: a complete wish-only solution exists, "
        "but the current fair heuristic cannot globally rearrange earlier assignments."
    ),
)
def test_fair_repairs_slot_dead_end_when_full_wish_solution_exists():
    workshops = {
        "w0": Workshop("w0", "Workshop 0", 1),
        "w1": Workshop("w1", "Workshop 1", 2),
        "w2": Workshop("w2", "Workshop 2", 2),
        "w3": Workshop("w3", "Workshop 3", 1),
    }

    participants = {
        "p0": Participant("p0", "P0", ["w3", "w1", "w2"]),
        "p1": Participant("p1", "P1", ["w2", "w3", "w1"]),
        "p2": Participant("p2", "P2", ["w3", "w0", "w2", "w1"]),
        "p3": Participant("p3", "P3", ["w2", "w3", "w0", "w1"]),
        "p4": Participant("p4", "P4", ["w3", "w1", "w2", "w0"]),
    }

    cfg = {
        "num_assign": 3,
        "num_wishes": 4,
        "seed": "trap",
        "seeds": 1,
        "objective": "fair_maxmin",
        "topk_equals_slots": True,
        "round_cap_pct": 50,
        "alpha_fairness": 0.35,
        "weights": {
            1: 1.0,
            2: 0.8,
            3: 0.6,
            4: 0.4,
        },
    }

    assignments, result = run_matching_fair(
        participants,
        workshops,
        cfg,
    )

    summary = result["summary"]

    # 5 Teilnehmer × 3 Slots = 15 Wunschzuteilungen sind möglich.
    assert summary["target_assignments_total"] == 15

    # Fachliches Soll für die spätere neue Matchinglogik:
    assert summary["assignments_total"] == 15
    assert summary["filler_assignments"] == 0

    # Alle 15 Zuteilungen müssen aus den angegebenen Wünschen stammen.
    wish_assignments = sum(
        1
        for pid, slots in assignments.items()
        for workshop_id in slots.values()
        if workshop_id in participants[pid].wishes[:cfg["num_wishes"]]
    )

    assert wish_assignments == 15


@pytest.mark.xfail(
    strict=True,
    reason=(
        "Known filler bug: the current fair strategy may assign "
        "a workshop that is not in the participant's wishes."
    ),
)
def test_fair_matching_assigns_only_requested_workshops():
    participants = {
        "p1": Participant(
            "p1",
            "P1",
            ["w1"],
        ),
    }

    workshops = {
        "w1": Workshop("w1", "Gewünschter Workshop", 1),
        "w2": Workshop("w2", "Nicht gewünschter Workshop", 1),
    }

    cfg = {
        "num_assign": 2,
        "num_wishes": 1,
        "seed": "PHASE1-FILLER",
        "seeds": 1,
        "objective": "fair_maxmin",
        "topk_equals_slots": True,
        "round_cap_pct": 100,
        "alpha_fairness": 0.35,
        "weights": {
            1: 1.0,
        },
    }

    assignments, result = run_matching_fair(
        participants,
        workshops,
        cfg,
    )

    assigned_workshops = set(assignments["p1"].values())

    # Fachliches Soll:
    # Es darf ausschließlich aus den angegebenen Wünschen zugeteilt werden.
    assert assigned_workshops <= set(participants["p1"].wishes)

    # Wenn nur ein Wunsch angegeben wurde, muss der zweite Slot
    # lieber leer bleiben als mit einem Nicht-Wunsch gefüllt werden.
    assert result["summary"]["filler_assignments"] == 0
    assert result["summary"]["assignments_total"] == 1
