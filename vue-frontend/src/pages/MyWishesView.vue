<template>
  <v-container class="py-8" max-width="900">
    <h1 class="text-h4 mb-6">Meine Wünsche</h1>

    <v-form @submit.prevent="loadWishes">
      <v-text-field
        v-model="code"
        label="Teilnehmer-Code"
        variant="outlined"
        autocomplete="one-time-code"
        :rules="[v => !!v || 'Bitte Code eingeben']"
        class="mb-3"
      />
      <div class="d-flex gap-3 mb-4">
        <v-btn :loading="loading" color="primary" type="submit">Wünsche laden</v-btn>
        <v-btn variant="text" @click="clear">Zurücksetzen</v-btn>
      </div>
    </v-form>

    <v-alert v-if="error" type="error" variant="tonal" class="mb-4">{{ error }}</v-alert>

    <div v-if="loading">
      <v-progress-linear indeterminate />
    </div>

    <div v-else-if="wishes.length === 0 && triedOnce">
      <v-alert type="info" variant="tonal">
        Keine Wünsche gefunden. Entweder wurden noch keine gespeichert
        oder der Code ist nicht korrekt.
      </v-alert>
    </div>

    <v-list v-else density="comfortable">
      <v-list-item
        v-for="(w, i) in wishes"
        :key="w.id || i"
        :title="`${i+1}. ${w.title || 'Workshop'}`"
      />
    </v-list>
  </v-container>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { api } from '@/api/client'

const code = ref(localStorage.getItem('jfcamp_code') || '')
const loading = ref(false)
const triedOnce = ref(false)
const error = ref('')
const wishes = ref([]) // [{id,title}]

async function loadWishes() {
  error.value = ''
  triedOnce.value = true
  const theCode = code.value?.trim()
  if (!theCode) {
    error.value = 'Bitte Code eingeben'
    return
  }
  loading.value = true
  try {
    // GET /api/wunsch?code=... (siehe Backend unten)
    const res = await api.getMyWishes(theCode)
    // Erwartet: { ok: true, wuensche: [{id,title}] }
    const list = Array.isArray(res?.wuensche) ? res.wuensche : (Array.isArray(res) ? res : [])
    wishes.value = list
    localStorage.setItem('jfcamp_code', theCode)
  } catch (e) {
    error.value = e?.message || 'Laden fehlgeschlagen'
  } finally {
    loading.value = false
  }
}

function clear() {
  wishes.value = []
  error.value = ''
  triedOnce.value = false
}

onMounted(() => {
  if (code.value) loadWishes()
})
</script>

<style scoped>
.d-flex { display: flex; }
.gap-3 { gap: 12px; }
</style>
