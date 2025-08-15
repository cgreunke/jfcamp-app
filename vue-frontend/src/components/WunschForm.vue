<template>
  <v-card elevation="2">
    <v-card-title class="text-h6">Workshop-Wunschformular</v-card-title>
    <v-card-text>
      <v-form @submit.prevent="absenden" ref="form">
        <v-text-field
          v-model="code"
          label="Teilnehmercode"
          required
        />
        <v-select
          v-for="(n, idx) in anzahlWuensche"
          :key="idx"
          :label="`Wunsch ${idx + 1}`"
          :items="workshopOptions"
          item-title="attributes.title"
          item-value="id"
          v-model="wuensche[idx]"
          return-object
          clearable
          required
          class="mb-3"
        />

        <v-btn type="submit" color="primary" block class="mt-2">Wünsche absenden</v-btn>

        <v-alert v-if="fehler" type="error" class="mt-4" density="compact">{{ fehler }}</v-alert>
        <v-alert v-if="erfolg" type="success" class="mt-4" density="compact">
          Vielen Dank! Deine Wünsche wurden gespeichert.
        </v-alert>
      </v-form>
    </v-card-text>
  </v-card>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { fetchMatchingConfig } from '@/api/matchingConfig'
import { fetchWorkshops } from '@/api/workshops'
import { fetchTeilnehmerIdByCode } from '@/api/teilnehmer'
import { absendenWunsch } from '@/api/wunsch'

const code = ref('')
const wuensche = ref([])
const workshopOptions = ref([])
const fehler = ref('')
const erfolg = ref(false)
const anzahlWuensche = ref(0)

onMounted(async () => {
  try {
    const cfg = await fetchMatchingConfig()
    anzahlWuensche.value = Number(cfg?.field_num_wuensche || 5)
    wuensche.value = Array.from({ length: anzahlWuensche.value }, () => null)

    workshopOptions.value = await fetchWorkshops()
  } catch (e) {
    fehler.value = 'Fehler beim Laden der Daten: ' + e.message
  }
})

async function absenden() {
  fehler.value = ''
  erfolg.value = false
  try {
    const tnId = await fetchTeilnehmerIdByCode(code.value.trim())
    if (!tnId) {
      fehler.value = 'Teilnehmercode ungültig.'
      return
    }
    await absendenWunsch(code.value.trim(), tnId, wuensche.value)
    erfolg.value = true
  } catch (e) {
    fehler.value = 'Fehler beim Absenden: ' + e.message
  }
}
</script>
