<script setup>
import { ref, computed, onMounted } from 'vue';

const DRUPAL = import.meta.env.VITE_DRUPAL_BASE_URL?.replace(/\/$/, '') || '';
const MODE = (import.meta.env.VITE_WISH_SUBMIT_MODE || 'direct').toLowerCase();
const PROXY = (import.meta.env.VITE_PROXY_BASE_URL || '').replace(/\/$/, '');

const loading = ref(false);
const success = ref(false);
const errorMsg = ref('');

const participantCode = ref('');
const wishes = ref({ w1: null, w2: null, w3: null });

const workshops = ref([]); // [{id, title}]

const canSubmit = computed(() => {
  if (!participantCode.value.trim()) return false;
  const vals = [wishes.value.w1, wishes.value.w2, wishes.value.w3].filter(Boolean);
  if (vals.length < 3) return false;
  return new Set(vals).size === vals.length;
});

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
    errorMsg.value = 'Bitte Code eingeben und drei unterschiedliche Wünsche auswählen.';
    return;
  }

  loading.value = true;
  try {
    const order = [wishes.value.w1, wishes.value.w2, wishes.value.w3];
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

onMounted(() => {
  fetchAllWorkshops().catch(err => { errorMsg.value = err?.message || String(err); });
});
</script>

<template>
  <v-card class="pa-4 brand-card">
    <div class="mb-2">
      <h2 class="text-h6 mb-1">Wünsche abgeben</h2>
      <p class="text-body-2 text-medium-emphasis">Reihenfolge = Priorität (oben = höchste).</p>
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
      <p class="text-body-3 text-medium-emphasis mb-4">Den Code erhälst du von deinen Teamer*inen.</p>

      <v-divider class="my-2" />

      <!-- Wunsch 1 -->
      <v-select
        v-model="wishes.w1"
        :items="workshops"
        item-title="title"
        item-value="id"
        label="1. Wunsch (höchste Priorität)"
        hint="Bitte einen Workshop wählen"
        persistent-hint
        required
        class="mb-2"
      />

      <!-- Wunsch 2 -->
      <v-select
        v-model="wishes.w2"
        :items="workshops"
        item-title="title"
        item-value="id"
        label="2. Wunsch"
        hint="Alternative, falls 1. Wunsch nicht möglich"
        persistent-hint
        required
        class="mb-2"
      />

      <!-- Wunsch 3 -->
      <v-select
        v-model="wishes.w3"
        :items="workshops"
        item-title="title"
        item-value="id"
        label="3. Wunsch"
        hint="Zusätzliche Alternative"
        persistent-hint
        required
      />

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
          @click="participantCode=''; wishes={w1:null,w2:null,w3:null}; errorMsg=''; success=false;"
        >
          Zurücksetzen
        </v-btn>
      </div>
    </v-form>
  </v-card>
</template>
