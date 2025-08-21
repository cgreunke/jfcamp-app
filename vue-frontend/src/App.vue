<script setup>
import AppFooter from '@/components/AppFooter.vue';

// SVG/PNG als URL importieren (Vite kümmert sich um das Asset)
import logoUrl from '@/assets/jf-logo.png?url'; // falls PNG: '@/assets/jf-logo.png?url'
</script>

<template>
  <v-app>
    <!-- dünne Akzentlinie -->
    <div class="brand-topbar"></div>

    <v-app-bar>
      <v-container class="d-flex align-center justify-space-between slim-vert">
        <!-- Brand: Logo + Name (Link zur Startseite) -->
        <RouterLink to="/" class="d-flex align-center text-decoration-none">
          <img :src="logoUrl" alt="JugendFEIER Nordbrandenburg" class="app-logo me-3" />
          <span class="text-h6 text-high-emphasis">JF Startercamp</span>
        </RouterLink>

        <div class="text-medium-emphasis">Wunschabgabe</div>
      </v-container>
    </v-app-bar>

    <v-main>
      <v-container>
        <router-view />
      </v-container>
    </v-main>

    <v-footer app>
      <v-container>
        <AppFooter />
      </v-container>
    </v-footer>
  </v-app>
</template>

<style>
/* Logo-Größe dezent, responsive */
.app-logo { height: 28px; width: auto; display: block; }
@media (min-width: 960px) { .app-logo { height: 32px; } }

/* RouterLink ohne Unterstreichung */
.text-decoration-none { text-decoration: none; }
</style>
