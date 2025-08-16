from flask import Flask, jsonify, request
import os
import random
from collections import defaultdict
import logging

import requests
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry

# -----------------------------------------------------------------------------
# App & Logging
# -----------------------------------------------------------------------------
app = Flask(__name__)
logging.basicConfig(level=logging.INFO)
log = logging.getLogger("matching")

# -----------------------------------------------------------------------------
# Konfiguration (ENV mit Defaults)
# -----------------------------------------------------------------------------
# Basis-URL für Drupal JSON:API, z. B. http://drupal/jsonapi im Compose-Netz
DRUPAL_URL = os.getenv("DRUPAL_URL", "http://drupal/jsonapi").rstrip("/")
# Optionaler Bearer-Token (wenn JSON:API Auth genutzt wird)
DRUPAL_TOKEN = os.getenv("DRUPAL_TOKEN", "").strip()

log.info("Using DRUPAL_URL=%s", DRUPAL_URL)
log.info("Using DRUPAL_TOKEN=%s", "set" if DRUPAL_TOKEN else "not set")

# -----------------------------------------------------------------------------
# Requests-Session mit Retries/Timeouts
# -----------------------------------------------------------------------------
session = requests.Session()
retries = Retry(
    total=5,
    backoff_factor=0.4,
    status_forcelist=[429, 500, 502, 503, 504],
    allowed_methods=["GET", "PATCH", "POST"]
)
session.mount("http://", HTTPAdapter(max_retries=retries))
session.mount("https://", HTTPAdapter(max_retries=retries))

BASE_HEADERS = {
    "Accept": "application/vnd.api+json",
    "Content-Type": "application/vnd.api+json",
}
if DRUPAL_TOKEN:
    BASE_HEADERS["Authorization"] = f"Bearer {DRUPAL_TOKEN}"

def _get(url, timeout=8):
    r = session.get(url, headers=BASE_HEADERS, timeout=timeout)
    if not r.ok:
        raise RuntimeError(f"GET {url} -> {r.status_code} {r.text[:200]}")
    return r.json()

def _patch(url, payload, timeout=12):
    r = session.patch(url, headers=BASE_HEADERS, json=payload, timeout=timeout)
    if not r.ok:
        raise RuntimeError(f"PATCH {url} -> {r.status_code} {r.text[:200]}")
    return r.json() if r.content else {}

# -----------------------------------------------------------------------------
# Health
# -----------------------------------------------------------------------------
@app.route("/health", methods=["GET"])
def health():
    return jsonify({"ok": True})

# -----------------------------------------------------------------------------
# Matching-Endpunkt
# -----------------------------------------------------------------------------
@app.route('/matching/run', methods=['POST', 'GET'])
def run_matching():
    """
    Führt ein einfaches Greedy-Matching aus:
      - Nimmt pro Teilnehmer die Wunschliste in Reihenfolge.
      - Füllt bis zu 'num_assign' Plätze, dann ggf. Restplätze zufällig.
      - Schreibt Zuteilungen in Teilnehmer-Felder field_workshop_1..M zurück.
    Erwartete Drupal-Nodes:
      - node--matching_config (field_num_wuensche, field_num_zuteilung)
      - node--wunsch mit relationships: field_teilnehmer, field_wunsch_1..N
      - node--workshop mit attributes: field_maximale_plaetze
      - node--teilnehmer mit relationships: field_workshop_1..M
    """
    try:
        # 1) Matching-Konfiguration laden
        cfg = _get(f"{DRUPAL_URL}/node/matching_config")
        if not cfg.get('data'):
            return jsonify({"status": "error", "message": "Keine Matching-Config angelegt."}), 400

        attrs = cfg['data'][0]['attributes']
        num_wishes = int(attrs.get('field_num_wuensche') or 0)
        num_assign = int(attrs.get('field_num_zuteilung') or 0)
        if num_wishes < 1 or num_assign < 1:
            return jsonify({"status": "error", "message": "Matching-Config unvollständig."}), 400

        # 2) Wünsche + Workshops laden
        # Feldnamen: field_wunsch_1 .. field_wunsch_N
        wish_fields = ",".join([f"field_wunsch_{i}" for i in range(1, num_wishes + 1)])

        # include holt die Relationen in einem Schwung (Teilnehmer & Workshops)
        wishes = _get(f"{DRUPAL_URL}/node/wunsch?include=field_teilnehmer,{wish_fields}")

        workshops = _get(f"{DRUPAL_URL}/node/workshop")
        capacity = {
            w['id']: int(w['attributes'].get('field_maximale_plaetze') or 0)
            for w in workshops.get('data', [])
        }

        # 3) Shuffle + Greedy Matching
        allocation = defaultdict(list)  # teilnehmerID -> [workshopIDs]
        fill = defaultdict(list)        # workshopID -> [teilnehmerIDs]
        items = wishes.get('data', [])
        random.shuffle(items)

        for entry in items:
            rel = entry.get('relationships', {})
            tn_rel = rel.get('field_teilnehmer', {}).get('data')
            if not tn_rel:
                # Falls Wunsch ohne Teilnehmerbezug existiert, überspringen
                continue
            tn_id = tn_rel['id']

            # Wunschliste extrahieren
            desired = []
            for i in range(1, num_wishes + 1):
                rid = rel.get(f'field_wunsch_{i}', {}).get('data', {})
                w_id = rid.get('id')
                if w_id:
                    desired.append(w_id)

            assigned = 0

            # Erst Wünsche in Reihenfolge
            for w_id in desired:
                if assigned >= num_assign:
                    break
                if capacity.get(w_id, 0) > 0 and w_id not in allocation[tn_id]:
                    allocation[tn_id].append(w_id)
                    fill[w_id].append(tn_id)
                    capacity[w_id] -= 1
                    assigned += 1

            # Dann Restplätze zufällig
            if assigned < num_assign:
                free_ws = [wid for wid, cap in capacity.items()
                           if cap > 0 and wid not in allocation[tn_id]]
                random.shuffle(free_ws)
                need = num_assign - assigned
                for w in free_ws[:need]:
                    allocation[tn_id].append(w)
                    fill[w].append(tn_id)
                    capacity[w] -= 1

        # 4) Ergebnisse zurückschreiben: Teilnehmer-Felder field_workshop_1..M
        errors = []
        updated = 0
        for tn_id, ws_list in allocation.items():
            rels = {}
            for i, wid in enumerate(ws_list[:num_assign], start=1):
                rels[f"field_workshop_{i}"] = {"data": {"type": "node--workshop", "id": wid}}

            patch = {"data": {"type": "node--teilnehmer", "id": tn_id, "relationships": rels}}
            url = f"{DRUPAL_URL}/node/teilnehmer/{tn_id}"
            try:
                _patch(url, patch)
                updated += 1
            except Exception as e:
                msg = f"PATCH Teilnehmer {tn_id} fehlgeschlagen: {e}"
                log.warning(msg)
                errors.append(msg)

        result = {"status": "success",
                  "message": "Matching abgeschlossen.",
                  "updated_participants": updated,
                  "errors": errors}
        return jsonify(result), (207 if errors else 200)

    except Exception as e:
        log.exception("Fehler im Matching")
        return jsonify({"status": "error", "message": str(e)}), 500

# -----------------------------------------------------------------------------
# Main
# -----------------------------------------------------------------------------
if __name__ == "__main__":
    # In Container auf 0.0.0.0:5000
    app.run(host="0.0.0.0", port=5000)
