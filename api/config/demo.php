<?php

return [
    // Demo-mode toggle. When true, the SPA shows the "synthetic data" banner and —
    // if an access code is set — the shared front-door gate. OFF by default, so local
    // dev, CI, and a future REAL-production build are neither demo-marked nor gated.
    'enabled' => (bool) env('DEMO_MODE', false),

    // The shared front-door access code for the public demo URL. A demo convenience,
    // NOT real authentication — Sanctum + RLS remain the actual controls beneath it.
    // Empty ⇒ no gate (banner still shows if demo is enabled). Supplied as the Cloud
    // Run secret `kap-demo-access-code`; never hardcoded, rotatable without a rebuild.
    'access_code' => (string) env('DEMO_ACCESS_CODE', ''),
];
