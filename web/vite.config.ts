import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { fileURLToPath, URL } from 'node:url'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  resolve: {
    // DS2 single import root — '@/ds2'. Mirrors tsconfig.app.json paths.
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  server: {
    // Dev convenience: API served by the compose nginx
    proxy: { '/api': 'http://localhost:8080' },
  },
})
