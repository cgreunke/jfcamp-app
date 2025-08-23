<template>
  <v-form ref="formRef" @submit.prevent="onSubmit">
    <v-text-field
      v-model="code"
      label="Teilnehmer-Code"
      variant="outlined"
      :rules="[v => !!v || 'Bitte Code eingeben']"
      class="mb-4"
      autocomplete="one-time-code"
    />

    <div v-if="loadingConfig" class="my-6">
      <v-progress-linear indeterminate />
    </div>

    <div v-else>
      <v-row dense>
        <v-col
          v-for="(w, idx) in wuensche"
          :key="idx"
          cols="12"
        >
          <v-autocomplete
            v-model="wuensche[idx]"
            :items="itemsFor(idx)"
            item-title="label"
            item-value="id"
            :rules="[v => !!v || 'Bitte auswählen', noDuplicateRule(idx)]"
            :label="`Wunsch #${idx + 1}`"
            variant="outlined"
            clearable
            hide-details="auto"
          />
        </v-col>
      </v-row>
    </div>

    <v-alert v-if="error" type="error" variant="tonal" class="mt-4">{{ error }}</v-alert>
    <v-alert v-if="success" type="success" variant="tonal" class="mt-4">Wünsche gespeichert. Danke!</v-alert>

    <div class="mt-6 d-flex gap-3">
      <v-btn :loading="submitting" color="primary" type="submit">Wünsche senden</v-btn>
      <v-btn variant="text" @click="resetForm">Zurücksetzen</v-btn>
    </div>
  </v-form>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { api } from '@/api/client'

const emit = defineEmits(['submitted'])

const formRef = ref(null)
const code = ref(localStorage.getItem('jfcamp_code') || '')
// [{ id, ext_id, title, label, _extNum }]
const workshops = ref([])
const wuensche = ref([])  // [id|null, id|null, ...]
const loadingConfig = ref(false)
const submitting = ref(false)
const error = ref('')
const success = ref(false)

// Zahl aus ext_id extrahieren (z.B. "W01" -> 1, "003" -> 3). Ungültig -> null
function extIdToNumber(extId) {
  if (!extId) return null
  const digits = String(extId).match(/\d+/g)?.join('') ?? ''
  if (!digits) return null
  const num = parseInt(digits, 10)
  return Number.isNaN(num) ? null : num
}

// --- dynamische Items pro Feld ----------------------------------------------
function selectedSet(excludeIdx = null) {
  const s = new Set()
  wuensche.value.forEach((val, i) => {
    if (val && i !== excludeIdx) s.add(val)
  })
  return s
}
function itemsFor(i) {
  const taken = selectedSet(i)
  // Reihenfolge bleibt wie in workshops.value (bereits sortiert)
  return workshops.value.filter(w => w.id === wuensche.value[i] || !taken.has(w.id))
}
// -----------------------------------------------------------------------------

function noDuplicateRule(index) {
  return (v) => {
    if (!v) return true
    const otherIdx = wuensche.value.findIndex((val, i) => i !== index && val === v)
    return otherIdx === -1 || 'Doppelter Workshop ist nicht erlaubt'
  }
}

function resetForm() {
  error.value = ''
  success.value = false
  wuensche.value = wuensche.value.map(() => null)
}

async function loadConfig() {
  loadingConfig.value = true
  try {
    const cfg = await api.getConfig()
    const count = Number(cfg.max_wishes ?? cfg.field_num_wuensche ?? 3)

    // Workshops mappen und sortieren
    const mapped = (cfg.workshops || []).map(w => {
      const ext = w.ext_id ?? w.field_ext_id ?? null
      const title = w.title ?? w.name ?? ''
      return {
        ...w,
        id: w.id ?? w.uuid ?? w.nid ?? w.drupal_internal__nid ?? w.drupal_internal__id ?? w?.['drupal_internal:nid'],
        ext_id: ext,
        title,
        label: (ext ? `${ext} · ` : '') + title,
        _extNum: extIdToNumber(ext),
      }
    })

    // Sortierlogik:
    // 1) beide haben Zahl -> nach Zahl
    // 2) nur einer hat Zahl -> der mit Zahl zuerst
    // 3) keine Zahl -> nach Titel
    mapped.sort((a, b) => {
      const aValid = a._extNum !== null
      const bValid = b._extNum !== null
      if (aValid && bValid) {
        if (a._extNum !== b._extNum) return a._extNum - b._extNum
        return a.title.localeCompare(b.title)
      } else if (aValid && !bValid) {
        return -1
      } else if (!aValid && bValid) {
        return 1
      } else {
        return a.title.localeCompare(b.title)
      }
    })

    workshops.value = mapped

    const current = (wuensche.value || []).filter(Boolean)
    wuensche.value = [
      ...current.slice(0, count),
      ...Array(Math.max(0, count - current.length)).fill(null),
    ]
  } catch (e) {
    error.value = `Konfiguration konnte nicht geladen werden: ${e.message}`
  } finally {
    loadingConfig.value = false
  }
}

async function onSubmit() {
  error.value = ''
  success.value = false
  const { valid } = await formRef.value.validate()
  if (!valid) return

  const chosen = wuensche.value.filter(Boolean)
  if (new Set(chosen).size !== chosen.length) {
    error.value = 'Bitte keine doppelten Wünsche auswählen.'
    return
  }

  submitting.value = true
  try {
    const res = await api.postWunsch({ code: code.value.trim(), wuensche: chosen })
    if (res.ok) {
      localStorage.setItem('jfcamp_code', code.value.trim())
      success.value = true
      emit('submitted', { code: code.value, wuensche: chosen })
    } else {
      throw new Error(res.error || 'Fehler beim Speichern')
    }
  } catch (e) {
    error.value = `Senden fehlgeschlagen: ${e.message}`
  } finally {
    submitting.value = false
  }
}

onMounted(loadConfig)
</script>

<style scoped>
.d-flex { display: flex; }
.gap-3 { gap: 12px; }
</style>
