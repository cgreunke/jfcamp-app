<template>
  <v-container class="py-8" max-width="900">
    <h1 class="text-h4 mb-6">Meine Workshops</h1>

    <v-form @submit.prevent="loadAll">
      <v-text-field
        v-model="code"
        label="Teilnehmer-Code"
        variant="outlined"
        autocomplete="one-time-code"
        :rules="[v => !!v || 'Bitte Code eingeben']"
        class="mb-3"
      />
      <div class="d-flex gap-3 mb-4">
        <v-btn :loading="loading" color="primary" type="submit">Workshops laden</v-btn>
        <v-btn variant="text" @click="clear">Zurücksetzen</v-btn>
      </div>
    </v-form>

    <v-alert v-if="error" type="error" variant="tonal" class="mb-4">{{ error }}</v-alert>

    <div v-if="loading">
      <v-progress-linear indeterminate />
    </div>

    <div v-else-if="assignments.length === 0 && triedOnce">
      <v-alert type="info" variant="tonal">
        Keine Workshops gefunden. Bitte prüfe deinen Code oder versuche es später erneut.
      </v-alert>
    </div>

    <div v-else>
      <v-row dense>
        <v-col
          v-for="group in groupedBySlot"
          :key="group.slot_index"
          cols="12"
          md="6"
        >
          <v-card>
            <v-card-title class="py-3">
              <div class="text-subtitle-1">
                Slot {{ group.slot_index + 1 }}
              </div>
              <div class="text-body-2 opacity-70">
                {{ slotLabel(group.slot_index) }}
              </div>
            </v-card-title>
            <v-divider />
            <v-list density="comfortable">
              <v-list-item
                v-for="(a, i) in group.items"
                :key="i"
                :title="displayTitle(a.workshop)"
                :subtitle="roomText(a.workshop?.room)"
              />
            </v-list>
          </v-card>
        </v-col>
      </v-row>
    </div>
  </v-container>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { api } from '@/api/client' // getSlots(), getZuweisungen(code)

const code = ref(localStorage.getItem('jfcamp_code') || '')
const loading = ref(false)
const triedOnce = ref(false)
const error = ref('')
const slots = ref([])         // [{ index, start, end }]
const assignments = ref([])   // [{ slot_index, workshop: { ext_id, title, room } }]

function slotLabel(idx) {
  const s = slots.value.find(s => Number(s.index) === Number(idx))
  if (!s) return 'Zeit folgt'
  const start = (s.start || '').slice(0, 5)
  const end = (s.end || '').slice(0, 5)
  return (start && end) ? `${start} – ${end}` : 'Zeit folgt'
}
function roomText(room) {
  return room ? `Raum: ${room}` : 'Raum wird vor Ort angezeigt'
}
function displayTitle(w) {
  if (!w) return 'Workshop'
  return `${w.ext_id ? w.ext_id + ' · ' : ''}${w.title ?? 'Workshop'}`
}

const groupedBySlot = computed(() => {
  const map = new Map()
  for (const a of assignments.value) {
    const idx = Number(a.slot_index ?? -1)
    if (!map.has(idx)) map.set(idx, [])
    map.get(idx).push(a)
  }
  return [...map.entries()]
    .sort((a, b) => a[0] - b[0])
    .map(([slot_index, items]) => ({ slot_index, items }))
})

async function loadAll() {
  error.value = ''
  triedOnce.value = true
  const theCode = code.value?.trim()
  if (!theCode) {
    error.value = 'Bitte Code eingeben'
    return
  }
  loading.value = true
  try {
    // Slots laden – wenn der Endpoint fehlt, einfach ohne Zeiten weiter
    try {
      const slotRes = await api.getSlots()
      slots.value = Array.isArray(slotRes?.slots) ? slotRes.slots
                   : Array.isArray(slotRes) ? slotRes
                   : []
    } catch {
      slots.value = []
    }

    const asgRes = await api.getZuweisungen(theCode) // <-- String erwartet
    assignments.value = Array.isArray(asgRes?.zuweisungen) ? asgRes.zuweisungen
                        : Array.isArray(asgRes) ? asgRes
                        : []

    localStorage.setItem('jfcamp_code', theCode)
  } catch (e) {
    error.value = e?.message || 'Laden fehlgeschlagen'
  } finally {
    loading.value = false
  }
}

function clear() {
  assignments.value = []
  error.value = ''
  triedOnce.value = false
}

onMounted(() => {
  if (code.value) loadAll()
})
</script>

<style scoped>
.d-flex { display: flex; }
.gap-3 { gap: 12px; }
.opacity-70 { opacity: 0.7; }
</style>
