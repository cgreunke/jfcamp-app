// vite.config.js
import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import vuetify from 'vite-plugin-vuetify'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')

  // DEV-Flexibilität:
  // - Wenn Vite AUF DEM HOST läuft: setze VITE_PROXY_TARGET=http://localhost:8080
  // - Wenn Vite IM CONTAINER läuft: setze VITE_PROXY_TARGET=http://drupal:80
  // - Default: localhost
  const target = env.VITE_PROXY_TARGET || 'http://localhost:8080'

  return {
    plugins: [vue(), vuetify({ autoImport: true })],
    resolve: {
      alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
    },
    server: {
      host: '0.0.0.0',
      port: 5173,
      strictPort: true,
      proxy: {
        // Neue Public-API in Drupal (ersetzt /jfcamp/*)
        '/api': {
          target,
          changeOrigin: true,
          secure: false,
        },
        // Falls du JSON:API etc. im FE brauchst, weiter erreichbar:
        '/jsonapi': {
          target,
          changeOrigin: true,
          secure: false,
        },
        // Session/CSRF (falls du das später einsetzt)
        '/session': {
          target,
          changeOrigin: true,
          secure: false,
        },
      },
    },
    // PROD unter Root-Pfad ausliefern (Nginx bedient /)
    base: env.VITE_BASE || '/',
    build: {
      outDir: 'dist',
      emptyOutDir: true,
      sourcemap: mode !== 'production',
      rollupOptions: {
        output: {
          manualChunks: {
            vue: ['vue'],
          },
        },
      },
    },
    // HMR stabil, auch durch Reverse-Proxies
    hmr: {
      clientPort: 5173,
    },
    define: {
      __APP_VERSION__: JSON.stringify(env.npm_package_version || 'dev'),
    },
  }
})
