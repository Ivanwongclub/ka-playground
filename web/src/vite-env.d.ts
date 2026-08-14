/// <reference types="vite/client" />

// P0-2b — build-time git short-sha injected by vite.config.ts `define`. Not backend; not runtime.
declare const __APP_VERSION__: string;
