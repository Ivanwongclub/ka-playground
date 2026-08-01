<?php

// S04F STEP 3 — school-settled receivable aging (OD-55). Terms open the clock at
// issuance; grace is the quiet window past due before the invoice ages to
// `overdue`. Both overridable; neither ever touches a child's enrolment.
return [
    'school_invoice_terms_days' => (int) env('SCHOOL_INVOICE_TERMS_DAYS', 30),
    'school_invoice_grace_days' => (int) env('SCHOOL_INVOICE_GRACE_DAYS', 7),
];
