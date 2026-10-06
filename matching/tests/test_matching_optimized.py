from matching_server import Participant, Workshop, run_matching_optimized, validate_matching_assignments
import pytest

def test_optimized_rejects_unknown_workshop_references():
    participants = {
        "p1": Participant(
            "p1",
            "P1",
            ["w1", "missing-workshop"],
        ),
    }

    workshops = {
        "w1": Workshop("w1", "Workshop 1", 1),
    }

    cfg = {
        "num_assign": 1,
        "num_wishes": 2,
        "weights": {
            1: 1.0,
            2: 0.8,
        },
    }

    with pytest.raises(ValueError, match="missing-workshop"):
        run_matching_optimized(
            participants,
            workshops,
            cfg,
        )


def test_optimized_uses_filler_only_for_remaining_open_slot():
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
        "weights": {
            1: 1.0,
        },
    }

    assignments, result = run_matching_optimized(
        participants,
        workshops,
        cfg,
    )

    assigned_workshops = set(assignments["p1"].values())
    summary = result["summary"]

    assert assigned_workshops == {"w1", "w2"}
    assert "w1" in assigned_workshops
    assert summary["wish_assignments"] == 1
    assert summary["filler_assignments"] == 1
    assert summary["assignments_total"] == 2
    assert summary["all_filled_to_slots"] is True


def test_optimized_does_not_use_filler_when_wishes_can_fill_all_slots():
    participants = {
        "p1": Participant("p1", "P1", ["w1", "w2"]),
    }

    workshops = {
        "w1": Workshop("w1", "Workshop 1", 1),
        "w2": Workshop("w2", "Workshop 2", 1),
        "w3": Workshop("w3", "Workshop 3", 1),
    }

    cfg = {
        "num_assign": 2,
        "num_wishes": 2,
        "weights": {
            1: 1.0,
            2: 0.8,
        },
    }

    assignments, result = run_matching_optimized(
        participants,
        workshops,
        cfg,
    )

    assigned_workshops = set(assignments["p1"].values())
    summary = result["summary"]

    assert assigned_workshops == {"w1", "w2"}
    assert summary["wish_assignments"] == 2
    assert summary["filler_assignments"] == 0
    assert summary["assignments_total"] == 2


def test_optimized_solves_slot_dead_end_with_full_wish_solution():
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
        "weights": {
            1: 1.0,
            2: 0.8,
            3: 0.6,
            4: 0.4,
        },
    }

    assignments, result = run_matching_optimized(
        participants,
        workshops,
        cfg,
    )

    summary = result["summary"]

    assert summary["target_assignments_total"] == 15
    assert summary["assignments_total"] == 15
    assert summary["filler_assignments"] == 0
    assert summary["all_filled_to_slots"] is True

    for pid, slots in assignments.items():
        assert len(slots) == 3

        for workshop_id in slots.values():
            assert workshop_id in participants[pid].wishes[:cfg["num_wishes"]]


def test_optimized_maximizes_fully_assigned_participants_after_total():
    participants = {
        "p1": Participant("p1", "P1", ["w1", "w2"]),
        "p2": Participant("p2", "P2", ["w1", "w2"]),
        "p3": Participant("p3", "P3", ["w1", "w2"]),
    }

    workshops = {
        "w1": Workshop("w1", "Workshop 1", 1),
        "w2": Workshop("w2", "Workshop 2", 1),
    }

    cfg = {
        "num_assign": 2,
        "num_wishes": 2,
        "weights": {
            1: 1.0,
            2: 0.8,
        },
    }

    assignments, result = run_matching_optimized(
        participants,
        workshops,
        cfg,
    )

    summary = result["summary"]

    # Zwei Workshops × zwei Slots × Kapazität 1.
    assert summary["assignments_total"] == 4
    assert summary["objective_assignments_total"] == 4

    # Bei gleicher Gesamtzahl ist 2+2+0 besser als 2+1+1:
    # zwei Jugendliche können vollständig versorgt werden.
    assert summary["participants_fully_assigned"] == 2

    assignment_counts = sorted(
        (len(slots) for slots in assignments.values()),
        reverse=True,
    )

    assert assignment_counts == [2, 2, 0]

def test_optimized_maximizes_topk_after_total_and_full_assignments():
    participants = {
        "p1": Participant(
            "p1",
            "P1",
            ["w1", "w2", "w3"],
        ),
        "p2": Participant(
            "p2",
            "P2",
            ["w1", "w2", "w3"],
        ),
    }

    workshops = {
        "w1": Workshop("w1", "Workshop 1", 1),
        "w2": Workshop("w2", "Workshop 2", 1),
        "w3": Workshop("w3", "Workshop 3", 1),
    }

    cfg = {
        "num_assign": 2,
        "num_wishes": 3,
        "weights": {
            1: 1.0,
            2: 0.8,
            3: 0.6,
        },
    }

    assignments, result = run_matching_optimized(
        participants,
        workshops,
        cfg,
    )

    summary = result["summary"]

    # Priorität 1:
    # Beide Teilnehmer können je zwei Workshops erhalten.
    assert summary["assignments_total"] == 4
    assert summary["objective_assignments_total"] == 4

    # Priorität 2:
    # Beide können vollständig versorgt werden.
    assert summary["participants_fully_assigned"] == 2

    # Priorität 3:
    # Da num_assign == 2, sind Wunsch 1 und 2 Top-k.
    # Eine Lösung mit vier Top-k-Treffern ist möglich.
    assert summary["objective_topk_hits"] == 4

    for pid, slots in assignments.items():
        assigned = set(slots.values())
        topk_wishes = set(participants[pid].wishes[:2])

        assert assigned == topk_wishes

def test_optimized_prefers_higher_ranked_wishes_after_topk():
    participants = {
        "p1": Participant("p1", "P1", ["w1", "w2", "w3"]),
        "p2": Participant("p2", "P2", ["w2", "w1", "w3"]),
    }

    workshops = {
        "w1": Workshop("w1", "Workshop 1", 1),
        "w2": Workshop("w2", "Workshop 2", 1),
        "w3": Workshop("w3", "Workshop 3", 1),
    }

    cfg = {
        "num_assign": 1,
        "num_wishes": 3,
        "weights": {
            1: 1.0,
            2: 0.8,
            3: 0.6,
        },
    }

    assignments, result = run_matching_optimized(
        participants,
        workshops,
        cfg,
    )

    summary = result["summary"]

    assert summary["assignments_total"] == 2
    assert summary["participants_fully_assigned"] == 2
    assert summary["objective_topk_hits"] == 2

    # Beide können ihren Erstwunsch bekommen:
    # p1 -> w1
    # p2 -> w2
    assert set(assignments["p1"].values()) == {"w1"}
    assert set(assignments["p2"].values()) == {"w2"}

    assert summary["objective_weighted_quality"] == 2000

def test_optimized_improves_worst_participant_after_quality_is_fixed():
    participants = {
        "p1": Participant(
            "p1",
            "P1",
            ["w3", "w4", "w1", "w2"],
        ),
        "p2": Participant(
            "p2",
            "P2",
            ["w4", "w2", "w3", "w1"],
        ),
        "p3": Participant(
            "p3",
            "P3",
            ["w3", "w4", "w1", "w2"],
        ),
        "p4": Participant(
            "p4",
            "P4",
            ["w3", "w1", "w2", "w4"],
        ),
    }

    workshops = {
        "w1": Workshop("w1", "Workshop 1", 1),
        "w2": Workshop("w2", "Workshop 2", 1),
        "w3": Workshop("w3", "Workshop 3", 1),
        "w4": Workshop("w4", "Workshop 4", 1),
    }

    cfg = {
        "num_assign": 1,
        "num_wishes": 4,
        "weights": {
            1: 1.0,
            2: 0.8,
            3: 0.6,
            4: 0.4,
        },
    }

    assignments, result = run_matching_optimized(
        participants,
        workshops,
        cfg,
    )

    summary = result["summary"]

    assert summary["assignments_total"] == 4
    assert summary["participants_fully_assigned"] == 4

    # Bei einem Slot ist nur Wunsch 1 ein Top-k-Treffer.
    # Maximal zwei Erstwünsche sind gleichzeitig möglich.
    assert summary["objective_topk_hits"] == 2

    # Unter allen Lösungen mit zwei Top-1-Treffern ist die
    # maximale gewichtete Gesamtqualität 3200.
    assert summary["objective_weighted_quality"] == 3200

    # Es existieren bei gleicher Gesamtqualität Lösungen mit
    # schlechtestem Einzelwert 400 und solche mit 600.
    # Die Fairness-Stufe muss mindestens 600 erreichen.
    assert summary["objective_min_participant_quality"] == 600

    assigned_weights = []

    for pid, slots in assignments.items():
        assigned_workshop = next(iter(slots.values()))
        rank = participants[pid].wishes.index(assigned_workshop) + 1
        assigned_weights.append(cfg["weights"][rank])

    assert min(assigned_weights) == 0.6

def test_validator_rejects_non_wish_assignment():
    participants = {
        "p1": Participant("p1", "P1", ["w1"]),
    }

    workshops = {
        "w1": Workshop("w1", "Workshop 1", 1),
        "w2": Workshop("w2", "Workshop 2", 1),
    }

    cfg = {
        "num_assign": 1,
        "num_wishes": 1,
    }

    assignments = {
        "p1": {1: "w2"},
    }

    with pytest.raises(ValueError, match="nicht unter"):
        validate_matching_assignments(
            assignments,
            participants,
            workshops,
            cfg,
        )


def test_validator_rejects_duplicate_workshop_for_participant():
    participants = {
        "p1": Participant("p1", "P1", ["w1"]),
    }

    workshops = {
        "w1": Workshop("w1", "Workshop 1", 2),
    }

    cfg = {
        "num_assign": 2,
        "num_wishes": 1,
    }

    assignments = {
        "p1": {
            1: "w1",
            2: "w1",
        },
    }

    with pytest.raises(ValueError, match="mehrfach"):
        validate_matching_assignments(
            assignments,
            participants,
            workshops,
            cfg,
        )


def test_validator_rejects_capacity_overflow():
    participants = {
        "p1": Participant("p1", "P1", ["w1"]),
        "p2": Participant("p2", "P2", ["w1"]),
    }

    workshops = {
        "w1": Workshop("w1", "Workshop 1", 1),
    }

    cfg = {
        "num_assign": 1,
        "num_wishes": 1,
    }

    assignments = {
        "p1": {1: "w1"},
        "p2": {1: "w1"},
    }

    with pytest.raises(ValueError, match="Kapazität"):
        validate_matching_assignments(
            assignments,
            participants,
            workshops,
            cfg,
        )

def test_optimized_is_deterministic_for_same_input():
    participants = {
        "p1": Participant("p1", "P1", ["w1", "w2", "w3", "w4"]),
        "p2": Participant("p2", "P2", ["w2", "w1", "w4", "w3"]),
        "p3": Participant("p3", "P3", ["w3", "w4", "w1", "w2"]),
        "p4": Participant("p4", "P4", ["w4", "w3", "w2", "w1"]),
        "p5": Participant("p5", "P5", ["w1", "w3", "w2", "w4"]),
        "p6": Participant("p6", "P6", ["w2", "w4", "w3", "w1"]),
    }

    workshops = {
        "w1": Workshop("w1", "Workshop 1", 2),
        "w2": Workshop("w2", "Workshop 2", 2),
        "w3": Workshop("w3", "Workshop 3", 2),
        "w4": Workshop("w4", "Workshop 4", 2),
    }

    cfg = {
        "num_assign": 3,
        "num_wishes": 4,
        "weights": {
            1: 1.0,
            2: 0.8,
            3: 0.6,
            4: 0.4,
        },
    }

    assignments_1, result_1 = run_matching_optimized(
        participants,
        workshops,
        cfg,
    )

    assignments_2, result_2 = run_matching_optimized(
        participants,
        workshops,
        cfg,
    )

    assert assignments_1 == assignments_2

    timing_keys = {
        "priority_1_seconds",
        "priority_2_seconds",
        "priority_3_seconds",
        "priority_4_seconds",
        "priority_5_seconds",
        "completion_solver_seconds",
        "filler_solver_seconds",
        "slot_solver_seconds",
        "total_solver_seconds",
    }

    summary_1 = {
        key: value
        for key, value in result_1["summary"].items()
        if key not in timing_keys
    }

    summary_2 = {
        key: value
        for key, value in result_2["summary"].items()
        if key not in timing_keys
    }

    assert summary_1 == summary_2