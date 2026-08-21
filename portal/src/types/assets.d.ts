declare module '*.css'

/**
 * The one build-time flag the app reads. Vite replaces it with a literal, so
 * the dead branch is gone from the bundle entirely.
 *
 * Declared here rather than by pulling in `vite/client`, which would declare
 * `*.css` a second time — a conflict with the line above, not a merge.
 */
interface ImportMeta {
  readonly env: {
    readonly PROD: boolean
  }
}
