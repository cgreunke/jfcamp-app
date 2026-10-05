from matching.matching_server import Participant, Workshop, run_matching_fair


def build_fixture():
    participants = {
        "p1": Participant("p1", "P1", ["w1", "w2", "w3"]),
        "p2": Participant("p2", "P2", ["w2", "w3", "w1"]),
        "p3": Participant("p3", "P3", ["w3", "w1", "w2"]),
        "p4": Participant("p4", "P4", ["w1", "w3", "w2"]),
    }

    workshops = {
        "w1": Workshop("w1", "Workshop 1", 2),
        "w2": Workshop("w2", "Workshop 2", 2),
        "w3": Workshop("w3", "Workshop 3", 2),
    }

    cfg = {
        "num_assign": 2,
        "num_wishes": 3,
        "seed": "PHASE1-DETERMINISM",
        "seeds": 3,
        "objective": "fair_maxmin",
        "topk_equals_slots": True,
    }

    return participants, workshops, cfg


def test_fair_matching_is_deterministic_for_identical_seed():
    participants, workshops, cfg = build_fixture()

    assignments_1, result_1 = run_matching_fair(
        participants,
        workshops,
        cfg,
    )

    assignments_2, result_2 = run_matching_fair(
        participants,
        workshops,
        cfg,
    )

    assert assignments_1 == assignments_2
    assert result_1["summary"] == result_2["summary"]
