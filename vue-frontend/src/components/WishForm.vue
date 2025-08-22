<script setup>
import { ref, computed } from 'vue'
import { submitWishes } from '@/services/wunsch'

const code = ref('')
const selected = ref([]) // Strings (Namen/IDs) – passe bei Bedarf an
const loading = ref(false)
const errorMsg = ref('')
const successMsg = ref('')

const canSubmit = computed(() =>
  code.value.trim() !== '' && selected.value.length > 0 && !loading.value
)

async function onSubmit() {
  errorMsg.value = ''
  successMsg.value = ''
  loading.value = true
  try {
    await submitWishes(code.value.trim(), selected.value)
    successMsg.value = '✅ Wünsche erfolgreich gespeichert.'
  } catch (e) {
    errorMsg.value = e?.message || 'Unbekannter Fehler'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <form class="wish-form" @submit.prevent="onSubmit">
    <div class="field">
      <label for="code">Teilnehmer-Code</label>
      <input id="code" v-model="code" type="text" placeholder="z. B. CODE100" />
    </div>

    <div class="field">
      <label>Workshops (Wünsche)</label>
      <div class="chips">
        <!-- Demo-Auswahl – hier deine echte Datenquelle binden -->
        <label><input type="checkbox" value="Schauspiel" v-model="selected" /> Schauspiel</label>
        <label><input type="checkbox" value="Social Media" v-model="selected" /> Social Media</label>
        <label><input type="checkbox" value="Graffiti" v-model="selected" /> Graffiti</label>
      </div>
      <small>Ausgewählt: {{ selected.join(', ') || '–' }}</small>
    </div>

    <button type="submit" :disabled="!canSubmit">{{ loading ? 'Sende…' : 'Wünsche absenden' }}</button>

    <p v-if="errorMsg" class="msg error">{{ errorMsg }}</p>
    <p v-if="successMsg" class="msg success">{{ successMsg }}</p>
  </form>
</template>

<style scoped>
.wish-form { max-width: 640px; margin: 0 auto; display: grid; gap: 12px; }
.field { display: grid; gap: 6px; }
.chips { display: flex; gap: 12px; flex-wrap: wrap; }
button[disabled] { opacity: .6; cursor: not-allowed; }
.msg { margin-top: 8px; }
</style>
