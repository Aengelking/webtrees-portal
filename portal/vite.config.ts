import { defineConfig } from 'vitest/config'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// In development the portal runs on localhost while the API runs on a
// webtrees host. Vite proxies /api onto it so that the browser still sees a
// single origin — the same arrangement the Cloudflare Pages Function creates
// in production, and the reason no CORS handling exists in the PHP module.
const apiTarget = process.env.VITE_API_TARGET ?? 'http://localhost:8080'

export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: apiTarget,
        changeOrigin: true,
        // webtrees sets its session cookie for its own host; rewrite the
        // domain so the browser keeps it for localhost.
        cookieDomainRewrite: '',
      },
    },
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
    include: ['src/**/*.test.{ts,tsx}'],
    globals: true,
  },
})
