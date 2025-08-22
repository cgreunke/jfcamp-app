// vite.config.js
import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import vuetify from 'vite-plugin-vuetify'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  // Wenn Vite im Container läuft: VITE_PROXY_TARGET=http://drupal
  // Lokal ohne Docker: VITE_PROXY_TARGET=http://localhost:8080
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
        // Public API
        '/api': {
          target,
          changeOrigin: true,
          secure: false,
        },
        // (Optional) JSON:API, falls gebraucht
        '/jsonapi': {
          target,
          changeOrigin: true,
          secure: false,
        },
      },
    },
    // Für PROD ist kein spezielles Setting nötig – build erzeugt /dist
    build: {
      sourcemap: mode === 'development',
    },
  }
})
