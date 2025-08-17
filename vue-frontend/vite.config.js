// vite.config.js
import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import vuetify from 'vite-plugin-vuetify'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  // Setze in .env.development z.B. VITE_PROXY_TARGET=http://drupal (wenn Vite im Container läuft)
  // oder VITE_PROXY_TARGET=http://localhost:8080 (wenn Vite auf dem Host läuft)
  const target = env.VITE_PROXY_TARGET || 'http://localhost:8080'

  return {
    plugins: [vue(), vuetify({ autoImport: true })],
    resolve: {
      alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
    },
    server: {
      host: '0.0.0.0',
      port: 5173,
      proxy: {
        // JSON:API
        '/jsonapi': {
          target,
          changeOrigin: true,
          secure: false,
        },
        // Deine Custom-API
        '/jfcamp': {
          target,
          changeOrigin: true,
          secure: false,
        },
        // CSRF
        '/session': {
          target,
          changeOrigin: true,
          secure: false,
        },
      },
    },
  }
})
