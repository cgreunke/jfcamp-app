<template>
  <v-card elevation="2">
    <v-card-title class="text-h6">Workshop-Wunschformular</v-card-title>

    <v-card-text>
      <!-- Ladezustand -->
      <v-skeleton-loader
        v-if="laden"
        type="list-item-two-line, list-item-two-line, list-item-two-line"
        class="mb-4"
      />

      <v-form v-else @submit.prevent="absenden" ref="form">
        <v-text-field
          v-model="code"
          label="Teilnehmercode"
          required
          autocomplete="off"
          :rules="[v => !!(v && v.trim()) || 'Code ist erforderlich']"
          class="mb-3"
        />

       <v-select
          v-for="(n, idx) in anzahlWuensche"
          :key="idx"
          :label="`Wunsch ${idx + 1}`"
          :items="optionen(idx)"
          item-title="title"
          item-value="id"
          v-model="wuensche[idx]"
          clearable
          class="mb-3"
          @update:modelValue="onPick(idx)"
        ></v-select>

        <v-btn
          :loading="sending"
          :disabled="sending || istUngueltig"
          type="submit"
          color="primary"
          block
          class="mt-2"
        >
          Wünsche absenden
        </v-btn>

        <v-alert
          v-if="fehler"
          type="error"
          class="mt-4"
          density="compact"
        >
          {{ fehler }}
        </v-alert>

        <v-alert
          v-if="erfolg"
          type="success"
          class="mt-4"
          density="compact"
        >
          Vielen Dank! Deine Wünsche wurden gespeichert.
        </v-alert>
      </v-form>
    </v-card-text>
  </v-card>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import { loadMatchingConfig } from '@/api/matchingConfig'
import { fetchWorkshops } from '@/api/workshops'
import { fetchTeilnehmerIdByCode } from '@/api/teilnehmer'
import { absendenWunsch } from '@/api/wunsch'

const code = ref('')
const wuensche = ref([])                // enthält NUR Workshop-IDs (Strings oder null)
const workshopOptions = ref([])         // [{ id, title, extId }]
const fehler = ref('')
const erfolg = ref(false)
const sending = ref(false)
const laden = ref(true)
const anzahlWuensche = ref(3)

/** gefilterte Items je Select: verbietet Doppelbelegungen */
const optionen = (idx) => {
  const verbotene = new Set(wuensche.value.filter((v, i) => v && i !== idx))
  return workshopOptions.value.filter(o => !verbotene.has(o.id))
}

/** Minimal-Validierung: Code + mind. 1 Wunsch */
const istUngueltig = computed(() => {
  const hasCode = !!(code.value && code.value.trim())
  const hasMind1 = wuensche.value.some(Boolean)
  return !hasCode || !hasMind1
})

/** Wird bei jeder Auswahl aufgerufen: bereinigt Duplikate */
function onPick(changedIdx) {
  const seen = new Set()
  for (let i = 0; i < wuensche.value.length; i++) {
    const val = wuensche.value[i]
    if (!val) continue
    if (seen.has(val)) {
      // Duplikat gefunden -> diesen Eintrag leeren
      wuensche.value[i] = null
    } else {
      seen.add(val)
    }
  }
}

onMounted(async () => {
  try {
    fehler.value = ''
    laden.value = true

    const cfg = await loadMatchingConfig()
    anzahlWuensche.value = Number(cfg?.numWuensche ?? 3)
    wuensche.value = Array.from({ length: anzahlWuensche.value }, () => null)

    workshopOptions.value = await fetchWorkshops()
  } catch (e) {
    console.error(e)
    fehler.value = 'Fehler beim Laden der Daten: ' + (e?.message || 'Unbekannt')
  } finally {
    laden.value = false
  }
})

async function absenden() {
  fehler.value = ''
  erfolg.value = false
  sending.value = true
  try {
    const cleaned = code.value.trim()
    if (!cleaned) {
      fehler.value = 'Bitte Teilnehmercode eingeben.'
      return
    }

    const tnId = await fetchTeilnehmerIdByCode(cleaned)
    if (!tnId) {
      fehler.value = 'Teilnehmercode ungültig.'
      return
    }

    const workshopIds = wuensche.value.filter(Boolean)
    if (workshopIds.length === 0) {
      fehler.value = 'Bitte mindestens einen Wunsch auswählen.'
      return
    }

    await absendenWunsch({ code: cleaned, teilnehmerId: tnId, workshopIds })
    erfolg.value = true
    // optional: Formular zurücksetzen
    wuensche.value = Array.from({ length: anzahlWuensche.value }, () => null)
    // code.value = ''
  } catch (e) {
    console.error(e)
    fehler.value = 'Fehler beim Absenden: ' + (e?.message || 'Unbekannt')
  } finally {
    sending.value = false
  }
}
</script>
