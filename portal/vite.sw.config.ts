import { defineConfig } from 'vite'

/**
 * A second, tiny build, for the service worker alone.
 *
 * It cannot ride along in the main build for two reasons. A service worker
 * has to land on a fixed, unhashed URL at the root — `/sw.js`, so that its
 * scope is the whole portal — and Vite hashes every entry it emits. And it has
 * to be a *classic* script: module service workers are still not supported
 * everywhere, and the browsers that lag are the ones this portal's members
 * use. `format: 'iife'` produces one self-contained file with no imports in
 * it, which every browser that has service workers at all can run.
 *
 * `emptyOutDir: false` because the main build has already written dist/ by the
 * time this runs; see the `build` script in package.json.
 */
const buildId = process.env.SW_BUILD ?? new Date().toISOString().replace(/\.\d+Z$/, 'Z')

export default defineConfig({
  define: {
    // Names the cache, so that a deployment gets a fresh one and the previous
    // one is deleted on activation. It also guarantees the bytes of sw.js
    // differ from the last deployment's, which is what makes a browser notice
    // there is a new service worker at all.
    __SW_BUILD__: JSON.stringify(buildId),
  },
  build: {
    outDir: 'dist',
    emptyOutDir: false,
    target: 'es2020',
    // A service worker is small and is fetched before anything can use it.
    // Readable output costs nothing here and makes "what is actually deployed"
    // a question anyone can answer from the browser.
    minify: false,
    rollupOptions: {
      input: 'sw/service-worker.ts',
      output: {
        entryFileNames: 'sw.js',
        format: 'iife',
      },
    },
  },
})
