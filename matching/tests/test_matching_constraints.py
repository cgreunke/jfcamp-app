from collections import Counter

from matching.matching_server import Participant, Workshop, run_matching_fair


def test_fair_matching_respects_basic_constraints():
    participants = {
        "p1": Participant(
            id="p1",
            code="P1",
            wishes=["w1", "w2", "w3"],
        ),
        "p2": Participant(
            id="p2",
            code="P2",
            wishes=["w2", "w3", "w1"],
        ),
        "p3": Participant(
            id="p3",
            code="P3",
            wishes=["w3", "w1", "w2"],
        ),
    }

    workshops = {
        "w1": Workshop(id="w1", title="Workshop 1", capacity=2),
        "w2": Workshop(id="w2", title="Workshop 2", capacity=2),
        "w3": Workshop(id="w3", title="Workshop 3", capacity=2),
    }

    cfg = {
        "num_assign": 2,
        "num_wishes": 3,
        "seed": "PHASE1-CONSTRAINTS",
        "seeds": 1,
        "objective": "fair_maxmin",
        "topk_equals_slots": True,
    }

    assignments, result = run_matching_fair(
        participants,
        workshops,
        cfg,
    )

    # Jeder Teilnehmer bekommt höchstens num_assign Zuteilungen.
    for pid, slots in assignments.items():
        assert len(slots) <= cfg["num_assign"], pid

    # Pro Teilnehmer darf jeder Slot höchstens einmal vorkommen.
    # Das Dict-Modell erzwingt das grundsätzlich, wird hier aber
    # als fachliche Regressionserwartung explizit dokumentiert.
    for pid, slots in assignments.items():
        assert len(slots.keys()) == len(set(slots.keys())), pid

    # Derselbe Workshop darf demselben Teilnehmer nicht mehrfach
    # zugewiesen werden.
    for pid, slots in assignments.items():
        assigned_workshops = list(slots.values())
        assert len(assigned_workshops) == len(set(assigned_workshops)), pid

    # Kapazität jedes Workshops pro Slot darf nicht überschritten werden.
    for slot in range(1, cfg["num_assign"] + 1):
        usage = Counter(
            slots[slot]
            for slots in assignments.values()
            if slot in slots
        )

        for workshop_id, count in usage.items():
            assert count <= workshops[workshop_id].capacity, (
                slot,
                workshop_id,
                count,
            )

    # Der kleine Basistest soll vollständig lösbar sein.
    summary = result["summary"]

    assert summary["assignments_total"] == (
        len(participants) * cfg["num_assign"]
    )
    assert summary["all_filled_to_slots"] is True
