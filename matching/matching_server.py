from flask import Flask, jsonify, request
import requests
import random
from collections import defaultdict
import logging

app = Flask(__name__)
logging.basicConfig(level=logging.INFO)
log = logging.getLogger("matching")

# Innerhalb des Compose-Netzwerks spricht Python "drupal" an
DRUPAL_URL = 'http://jfcamp-drupal/jsonapi'

def get_json(url):
    r = requests.get(url)
    if not r.ok:
        raise RuntimeError(f"GET {url} -> {r.status_code}")
    return r.json()

@app.route("/health", methods=["GET"])
def health():
    return jsonify({"ok": True})

@app.route('/matching/run', methods=['POST', 'GET'])
def run_matching():
    try:
        # 1) Matching-Konfiguration laden
        cfg = get_json(f"{DRUPAL_URL}/node/matching_config")
        if not cfg.get('data'):
            return jsonify({"status": "error", "message": "Keine Matching-Config angelegt."}), 400
        attrs = cfg['data'][0]['attributes']
        num_wishes = int(attrs.get('field_num_wuensche') or 0)
        num_assign = int(attrs.get('field_num_zuteilung') or 0)
        if num_wishes < 1 or num_assign < 1:
            return jsonify({"status":"error","message":"Matching-Config unvollständig."}), 400

        # 2) Wünsche + Workshops laden
        # Feldnamen: field_wunsch_1 .. field_wunsch_N
        wish_fields = ",".join([f"field_wunsch_{i}" for i in range(1, num_wishes+1)])
        wishes = get_json(f"{DRUPAL_URL}/node/wunsch?include=field_teilnehmer,{wish_fields}")

        workshops = get_json(f"{DRUPAL_URL}/node/workshop")
        capacity = {
            w['id']: int(w['attributes'].get('field_maximale_plaetze') or 0)
            for w in workshops.get('data', [])
        }

        # 3) Shuffle + Greedy Matching
        allocation = defaultdict(list)  # teilnehmer -> [workshopIDs]
        fill = defaultdict(list)        # workshopID -> [teilnehmerIDs]
        items = wishes.get('data', [])
        random.shuffle(items)

        for entry in items:
            rel = entry['relationships']
            tn_id = rel['field_teilnehmer']['data']['id']
            desired = []
            for i in range(1, num_wishes+1):
                rid = rel.get(f'field_wunsch_{i}', {}).get('data', {})
                w_id = rid.get('id')
                if w_id:
                    desired.append(w_id)

            assigned = 0
            # Erst Wünsche
            for w_id in desired:
                if assigned >= num_assign:
                    break
                if capacity.get(w_id, 0) > 0 and w_id not in allocation[tn_id]:
                    allocation[tn_id].append(w_id)
                    fill[w_id].append(tn_id)
                    capacity[w_id] -= 1
                    assigned += 1

            # Dann Restplätze
            if assigned < num_assign:
                free_ws = [wid for wid, cap in capacity.items() if cap > 0 and wid not in allocation[tn_id]]
                random.shuffle(free_ws)
                for w in free_ws[:(num_assign - assigned)]:
                    allocation[tn_id].append(w)
                    fill[w].append(tn_id)
                    capacity[w] -= 1

        # 4) Ergebnisse zurückschreiben: Teilnehmer-Felder field_workshop_1..M
        for tn_id, ws_list in allocation.items():
            rels = {}
            for i, wid in enumerate(ws_list[:num_assign], start=1):
                rels[f"field_workshop_{i}"] = {"data": {"type": "node--workshop", "id": wid}}

            patch = {"data": {"type": "node--teilnehmer", "id": tn_id, "relationships": rels}}
            r = requests.patch(
                f"{DRUPAL_URL}/node/teilnehmer/{tn_id}",
                headers={"Content-Type": "application/vnd.api+json"},
                json=patch
            )
            if not r.ok:
                log.warning("PATCH Teilnehmer %s -> %s %s", tn_id, r.status_code, r.text)

        return jsonify({"status":"success","message":"Matching abgeschlossen."})

    except Exception as e:
        log.exception("Fehler im Matching")
        return jsonify({"status":"error","message":str(e)}), 500

if __name__ == "__main__":
    # In Container auf 0.0.0.0:5000
    app.run(host="0.0.0.0", port=5000)
