from matching.matching_server import Participant, Workshop, run_matching_fair


def test_fair_matching_reaches_1000_assignments_when_fully_feasible():
    participant_count = 250
    num_assign = 4
    num_wishes = 5

    workshops = {
        f"w{i}": Workshop(
            id=f"w{i}",
            title=f"Workshop {i}",
            capacity=100,
        )
        for i in range(1, 6)
    }

    participants = {}

    wish_patterns = [
        ["w1", "w2", "w3", "w4", "w5"],
        ["w2", "w3", "w4", "w5", "w1"],
        ["w3", "w4", "w5", "w1", "w2"],
        ["w4", "w5", "w1", "w2", "w3"],
        ["w5", "w1", "w2", "w3", "w4"],
    ]

    for i in range(participant_count):
        pid = f"p{i + 1:03d}"

        participants[pid] = Participant(
            id=pid,
            code=f"P{i + 1:03d}",
            wishes=wish_patterns[i % len(wish_patterns)],
        )

    cfg = {
        "num_assign": num_assign,
        "num_wishes": num_wishes,
        "seed": "PHASE1-250",
        "seeds": 3,
        "objective": "fair_maxmin",
        "topk_equals_slots": True,
    }

    assignments, result = run_matching_fair(
        participants,
        workshops,
        cfg,
    )

    summary = result["summary"]

    expected_total = participant_count * num_assign

    assert expected_total == 1000
    assert summary["target_assignments_total"] == expected_total
    assert summary["assignments_total"] == expected_total
    assert summary["all_filled_to_slots"] is True

    assert all(
        len(slots) == num_assign
        for slots in assignments.values()
    )
