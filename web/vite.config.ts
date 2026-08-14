import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { execSync } from 'node:child_process'
import { fileURLToPath, URL } from 'node:url'

// P0-2b: build-time app version = git short-sha (for the sider footer). Build-time only, NO backend and NO
// runtime git dependency; falls back to 'dev' when git is unavailable (e.g. a shallow/exported checkout).
function appVersion(): string {
  try {
    return execSync('git rev-parse --short HEAD', { stdio: ['ignore', 'pipe', 'ignore'] }).toString().trim() || 'dev'
  } catch {
    return 'dev'
  }
}

// https://vite.dev/config/
export default defineConfig({
  define: { __APP_VERSION__: JSON.stringify(appVersion()) },
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
