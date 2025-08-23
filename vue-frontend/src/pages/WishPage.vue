<template>
  <v-container class="py-8" max-width="900">
    <h1 class="text-h4 mb-6">Workshop-Wünsche abgeben</h1>
    <wish-form />
  </v-container>
</template>

<script setup>
import { ref } from 'vue'
import WishForm from '@/components/WishForm.vue'
import { api } from '@/api/client'

const codeLookup = ref('')
const loadingZ = ref(false)
const errZ = ref('')
const zuweisungen = ref([])
const loadedZ = ref(false)

function afterSubmit() {
  // optional: Auto-Focus auf Lookup
}

async function loadZuweisungen() {
  loadedZ.value = false
  errZ.value = ''
  zuweisungen.value = []
  if (!codeLookup.value) {
    errZ.value = 'Bitte Code eingeben.'
    return
  }
  loadingZ.value = true
  try {
    const res = await api.getZuweisungen(codeLookup.value.trim())
    if (res && res.ok) {
      zuweisungen.value = Array.isArray(res.zuweisungen) ? res.zuweisungen : []
      loadedZ.value = true
    } else {
      throw new Error('Unerwartete Antwort vom Server')
    }
  } catch (e) {
    errZ.value = `Abruf fehlgeschlagen: ${e.message}`
  } finally {
    loadingZ.value = false
  }
}
</script>
