<template>
  <v-container class="py-8" style="max-width: 900px">
    <h1 class="text-h4 mb-2">Alle Workshops</h1>

    <p class="text-body-1 text-medium-emphasis mb-6">
      Hier findest du eine Übersicht über die verfügbaren Workshops.
    </p>

    <!-- Suche -->
    <v-text-field
      v-model="search"
      label="Workshop suchen"
      prepend-inner-icon="mdi-magnify"
      variant="outlined"
      clearable
      hide-details
      class="mb-6"
    />

    <!-- Ladezustand -->
    <div v-if="loading" class="py-8">
      <v-progress-linear indeterminate color="primary" />
    </div>

    <!-- Fehler -->
    <v-alert
      v-else-if="error"
      type="error"
      variant="tonal"
      class="mb-6"
    >
      {{ error }}
    </v-alert>

    <!-- Keine Workshops -->
    <v-alert
      v-else-if="filteredWorkshops.length === 0"
      type="info"
      variant="tonal"
    >
      {{
        search
          ? 'Keine Workshops passen zu deiner Suche.'
          : 'Derzeit sind keine Workshops verfügbar.'
      }}
    </v-alert>

    <!-- Workshopliste -->
    <div v-else>
      <v-card
        v-for="workshop in filteredWorkshops"
        :key="workshop.id"
        variant="outlined"
        class="mb-3"
      >
        <v-card-item
          class="workshop-header"
          @click="toggleExpand(workshop.id)"
        >
          <template #prepend>
            <div
              v-if="workshop.extId"
              class="text-caption font-weight-bold mr-3"
            >
              {{ workshop.extId }}
            </div>
          </template>

          <v-card-title class="text-subtitle-1 font-weight-bold">
            {{ workshop.title }}
          </v-card-title>

          <template #append>
            <v-icon>
              {{
                expandedId === workshop.id
                  ? 'mdi-chevron-up'
                  : 'mdi-chevron-down'
              }}
            </v-icon>
          </template>
        </v-card-item>

        <v-expand-transition>
          <div v-show="expandedId === workshop.id">
            <v-divider />

            <v-card-text>
              <p v-if="workshop.description" class="mb-0">
                {{ workshop.description }}
              </p>

              <p v-else class="text-medium-emphasis mb-0">
                Für diesen Workshop ist derzeit keine weitere Beschreibung hinterlegt.
              </p>
            </v-card-text>
          </div>
        </v-expand-transition>
      </v-card>
    </div>
  </v-container>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { fetchWorkshops } from '@/api/workshops'

const search = ref('')
const workshops = ref([])
const loading = ref(false)
const error = ref('')
const expandedId = ref(null)

const filteredWorkshops = computed(() => {
  const query = search.value?.trim().toLowerCase()

  if (!query) {
    return workshops.value
  }

  return workshops.value.filter(workshop => {
    return (
      workshop.title?.toLowerCase().includes(query) ||
      workshop.extId?.toLowerCase().includes(query) ||
      workshop.description?.toLowerCase().includes(query)
    )
  })
})

function toggleExpand(id) {
  expandedId.value = expandedId.value === id ? null : id
}

async function loadWorkshops() {
  loading.value = true
  error.value = ''

  try {
    workshops.value = await fetchWorkshops()
  } catch (e) {
    error.value = `Workshops konnten nicht geladen werden: ${e.message}`
  } finally {
    loading.value = false
  }
}

onMounted(loadWorkshops)
</script>

<style scoped>
.workshop-header {
  cursor: pointer;
}

.workshop-header:hover {
  background: rgba(var(--v-theme-on-surface), 0.03);
}
</style>