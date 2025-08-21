<script setup>
const org = import.meta.env.VITE_CONTACT_ORG || 'JugendFEIER Nordbrandenburg';
const addr = import.meta.env.VITE_CONTACT_ADDR || 'Adresse folgt';
const mail = import.meta.env.VITE_CONTACT_MAIL || 'info@example.org';
const phone = import.meta.env.VITE_CONTACT_PHONE || '';
</script>

<template>
  <footer class="card" style="margin-top:1rem;">
    <div style="display:flex; justify-content:space-between; gap:1rem; flex-wrap:wrap;">
      <div>
        <strong>{{ org }}</strong><br>
        <span>{{ addr }}</span><br>
        <a :href="`mailto:${mail}`">{{ mail }}</a>
        <template v-if="phone"> · <a :href="`tel:${phone}`">{{ phone }}</a></template>
      </div>
      <nav style="display:flex; gap:1rem;">
        <router-link to="/impressum">Impressum</router-link>
        <router-link to="/datenschutz">Datenschutz</router-link>
      </nav>
    </div>
  </footer>
</template>

<style scoped>
footer { font-size: .95rem; }
</style>
