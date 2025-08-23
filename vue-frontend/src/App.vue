<script setup>
import { ref } from 'vue'
import { useRoute } from 'vue-router'
import { useDisplay } from 'vuetify'
import AppFooter from '@/components/AppFooter.vue'
import logoUrl from '@/assets/jf-logo.png?url'

// Routen + Labels zentral definieren
const navItems = [
  { label: 'Wünsche abgeben', to: '/' },
  { label: 'Meine Wünsche', to: '/meine-wuensche' },
  { label: 'Meine Workshops', to: '/workshops' },
]

const route = useRoute()
const { smAndDown } = useDisplay()
const drawer = ref(false)

function isActive(path) {
  return route.path === path || route.path.startsWith(path + '/')
}
</script>

<template>
  <v-app>
    <!-- dünne Akzentlinie -->
    <div class="brand-topbar"></div>

    <v-app-bar density="comfortable" flat border="b thin">
      <v-container class="d-flex align-center justify-space-between slim-vert">
        <!-- Brand: Logo + Name (Link zur Startseite) -->
        <RouterLink to="/" class="d-flex align-center text-decoration-none">
          <img :src="logoUrl" alt="JugendFEIER Nordbrandenburg" class="app-logo me-3" />
          <span class="text-h6 text-high-emphasis">JF Startercamp</span>
        </RouterLink>

        <!-- Desktop: dezente Text-Links -->
        <nav v-if="!smAndDown" class="nav-links">
          <RouterLink
            v-for="item in navItems"
            :key="item.to"
            :to="item.to"
            class="nav-link"
            :class="{ active: isActive(item.to) }"
          >
            {{ item.label }}
          </RouterLink>
        </nav>

        <!-- Mobile: Burger öffnet Drawer -->
        <v-app-bar-nav-icon v-else @click.stop="drawer = true" />
      </v-container>
    </v-app-bar>

    <!-- Mobile Drawer -->
    <v-navigation-drawer
      v-model="drawer"
      location="right"
      temporary
      width="260"
    >
      <v-list nav density="comfortable">
        <v-list-item
          v-for="item in navItems"
          :key="item.to"
          :to="item.to"
          :active="isActive(item.to)"
          :title="item.label"
          @click="drawer = false"
        />
      </v-list>
    </v-navigation-drawer>

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
/* Brand-Akzent oben (JugendFEIER-Orange) */
.brand-topbar { height: 3px; background: #EE7100; }

/* Logo-Größe dezent, responsive */
.app-logo { height: 28px; width: auto; display: block; }
@media (min-width: 960px) { .app-logo { height: 32px; } }

/* Nav: dezente Text-Links */
.nav-links { display: flex; gap: 12px; align-items: center; }
.nav-link {
  font-size: 0.95rem;
  color: rgba(0,0,0,0.72);
  text-decoration: none;
  padding: 6px 8px;
  border-radius: 8px;
  line-height: 1;
}
.nav-link:hover { background: rgba(0,0,0,0.05); }
.nav-link.active { color: #EE7100; }
.nav-link.active::after {
  content: '';
  display: block;
  height: 2px;
  background: #EE7100;
  border-radius: 2px;
  margin-top: 4px;
}

/* kleine Utility-Klassen */
.text-decoration-none { text-decoration: none; color: inherit; }
.d-flex { display: flex; }
.justify-space-between { justify-content: space-between; }
.align-center { align-items: center; }
.me-3 { margin-inline-end: 12px; }
.slim-vert { padding-top: 6px; padding-bottom: 6px; }
</style>
