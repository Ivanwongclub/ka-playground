<?php

return [
    // S05-4 (OD-45): the additional grace after an order's payment_due_at before a
    // family-paid, unpaid order is treated as lapsed. Global default; the lapse job
    // and the deadlines.no_silent_lapse assertion read the SAME value so they never
    // drift. A per-member grace extension (grace-ONCE, OD-37) overrides via
    // team_members.grace_until.
    'lapse_grace_days' => (int) env('TEAMS_LAPSE_GRACE_DAYS', 7),
];
