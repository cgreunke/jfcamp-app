<script setup>
import { ref, computed, onMounted } from 'vue';

const DRUPAL = import.meta.env.VITE_DRUPAL_BASE_URL?.replace(/\/$/, '') || '';
const MODE = (import.meta.env.VITE_WISH_SUBMIT_MODE || 'direct').toLowerCase();
const PROXY = (import.meta.env.VITE_PROXY_BASE_URL || '').replace(/\/$/, '');

const MATCHING_TYPE = (import.meta.env.VITE_MATCHING_TYPE || 'matching_config').trim();
const WISH_COUNT_FIELD = (import.meta.env.VITE_WISH_COUNT_FIELD || 'field_num_wuensche').trim();
const MATCHING_NODE_UUID = (import.meta.env.VITE_MATCHING_NODE_UUID || '').trim();

const loading = ref(false);
const success = ref(false);
const errorMsg = ref('');

const participantCode = ref('');
const wishCount = ref(3);
const wishes = ref([]); // Länge = wishCount, Werte = Workshop-IDs

const workshops = ref([]); // [{id, title}]

const canSubmit = computed(() => {
  const vals = wishes.value.filter(Boolean);
  const distinct = new Set(vals).size === vals.length;
  return !!participantCode.value.trim() && vals.length === wishCount.value && distinct;
});

function ensureLength() {
  wishes.value = Array.from({ length: wishCount.value }, (_, i) => wishes.value[i] ?? null);
}

function isDuplicate(idx) {
  const val = wishes.value[idx];
  if (!val) return false;
  for (let j = 0; j < idx; j++) if (wishes.value[j] === val) return true;
  return false;
}

async function fetchWishCountFromJsonApi() {
  try {
    let url;
    const fieldsParam = `fields[node--${MATCHING_TYPE}]=${encodeURIComponent(WISH_COUNT_FIELD)}`;

    if (MATCHING_NODE_UUID) {
      // Feste Konfig per UUID
      url = `${DRUPAL}/jsonapi/node/${MATCHING_TYPE}/${MATCHING_NODE_UUID}?${fieldsParam}`;
    } else {
      // Neueste veröffentlichte Instanz
      url = `${DRUPAL}/jsonapi/node/${MATCHING_TYPE}?filter[status]=1&sort=-changed&page[limit]=1&${fieldsParam}`;
    }

    const res = await fetch(url, { credentials: 'include' });
    if (!res.ok) throw new Error(`Wish count laden fehlgeschlagen (${res.status})`);
    const data = await res.json();

    const attrs = MATCHING_NODE_UUID ? data?.data?.attributes : data?.data?.[0]?.attributes;
    let n = attrs?.[WISH_COUNT_FIELD];

    // Falls Feld als String kommt → in int wandeln
    n = Number.parseInt(n, 10);
    if (!Number.isInteger(n) || n < 1 || n > 10) throw new Error('Ungültige Wunschanzahl');

    wishCount.value = n;
  } catch (e) {
    console.warn('Wish count Fallback auf 3:', e);
    wishCount.value = 3;
  } finally {
    ensureLength();
  }
}

async function fetchAllWorkshops() {
  workshops.value = [];
  let url = `${DRUPAL}/jsonapi/node/workshop?fields[node--workshop]=title&sort=title&page[limit]=100`;
  const seen = new Set();
  while (url) {
    const res = await fetch(url);
    if (!res.ok) throw new Error(`Workshops laden fehlgeschlagen (${res.status})`);
    const data = await res.json();
    for (const item of data.data || []) {
      if (item.id && !seen.has(item.id)) {
        seen.add(item.id);
        workshops.value.push({ id: item.id, title: item.attributes?.title || 'Ohne Titel' });
      }
    }
    url = data.links?.next?.href || '';
  }
}

async function resolveParticipantByCode(code) {
  const url = `${DRUPAL}/jsonapi/node/teilnehmer?filter[field_code][value]=${encodeURIComponent(code)}&fields[node--teilnehmer]=title`;
  const res = await fetch(url);
  if (!res.ok) throw new Error(`Teilnehmer-Suche fehlgeschlagen (${res.status})`);
  const data = await res.json();
  const item = data?.data?.[0];
  if (!item?.id) throw new Error('Kein Teilnehmer mit diesem Code gefunden.');
  return item.id;
}

async function submitDirect(participantId, workshopIds) {
  const url = `${DRUPAL}/jsonapi/node/wunsch`;
  const payload = {
    data: {
      type: 'node--wunsch',
      attributes: { title: `Wunschabgabe ${new Date().toISOString().slice(0,10)}` },
      relationships: {
        field_teilnehmer: { data: { type: 'node--teilnehmer', id: participantId } },
        field_wuensche: { data: workshopIds.map(id => ({ type: 'node--workshop', id })) }
      }
    }
  };
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/vnd.api+json' },
    body: JSON.stringify(payload)
  });
  if (!res.ok) throw new Error(`Übermittlung fehlgeschlagen (${res.status})`);
}

async function submitProxy(participantCode, workshopIds) {
  const url = `${PROXY}/api/wishes`;
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json'},
    body: JSON.stringify({ participant_code: participantCode, workshop_ids: workshopIds })
  });
  if (!res.ok) throw new Error(`Proxy-Antwort fehlgeschlagen (${res.status})`);
}

async function onSubmit(e) {
  e.preventDefault();
  errorMsg.value = '';
  success.value = false;

  if (!canSubmit.value) {
    errorMsg.value = 'Bitte Code eingeben und alle Wünsche (ohne Duplikate) auswählen.';
    return;
  }

  loading.value = true;
  try {
    const order = wishes.value.slice(); // 1..N Reihenfolge = Priorität
    if (MODE === 'proxy') {
      await submitProxy(participantCode.value.trim(), order);
    } else {
      const pid = await resolveParticipantByCode(participantCode.value.trim());
      await submitDirect(pid, order);
    }
    success.value = true;
  } catch (err) {
    errorMsg.value = err?.message || String(err);
  } finally {
    loading.value = false;
  }
}

onMounted(async () => {
  try {
    await Promise.all([fetchWishCountFromJsonApi(), fetchAllWorkshops()]);
  } catch (e) {
    // Fehlerbehandlung erfolgt in den Funktionen
  }
});
</script>

<template>
  <v-card class="pa-4 brand-card">
    <div class="mb-2">
      <h2 class="text-h6 mb-1">Wünsche abgeben</h2>
      <p class="text-body-2 text-medium-emphasis">
        Reihenfolge = Priorität (oben = höchste). Anzahl der Wünsche: {{ wishCount }}.
      </p>
    </div>

    <v-form @submit="onSubmit" :disabled="loading">
      <!-- Teilnehmercode -->
      <v-text-field
        v-model="participantCode"
        label="Teilnehmercode"
        variant="outlined"
        autocomplete="one-time-code"
        placeholder="z. B. AB12CD"
        required
        class="mb-2"
      />
      <p class="text-body-3 text-medium-emphasis mb-4">Den Code hast du in der Einladung bekommen.</p>

      <v-divider class="my-2" />

      <!-- Dynamische Wunsch-Felder 1..N -->
      <template v-for="i in wishCount" :key="i">
        <v-select
          v-model="wishes[i-1]"
          :items="workshops"
          item-title="title"
          item-value="id"
          :label="`${i}. Wunsch${i===1 ? ' (höchste Priorität)' : ''}`"
          :hint="i===1 ? 'Bitte zuerst den wichtigsten Workshop wählen' : 'Alternative Auswahl'"
          persistent-hint
          :error="isDuplicate(i-1)"
          :error-messages="isDuplicate(i-1) ? ['Dieser Workshop wurde bereits gewählt. Bitte einen anderen wählen.'] : []"
          required
          class="mb-2"
        />
      </template>

      <v-alert v-if="errorMsg" type="error" class="mt-3">{{ errorMsg }}</v-alert>
      <v-alert v-if="success" type="success" class="mt-3">Danke! Deine Wünsche wurden gespeichert.</v-alert>

      <div class="d-flex align-center ga-2 mt-4" style="gap:.5rem;">
        <v-btn type="submit" :loading="loading" :disabled="!canSubmit || loading">
          Wünsche senden
        </v-btn>
        <v-btn
          type="reset"
          variant="outlined"
          :disabled="loading"
          @click="participantCode=''; wishes=Array.from({length: wishCount.value}, () => null); errorMsg=''; success=false;"
        >
          Zurücksetzen
        </v-btn>
      </div>
    </v-form>
  </v-card>
</template>
