<?php

// The asSystem() allowlist (Leo, S02A gate review). Every sanctioned elevation
// is declared here: call site => the reason it exists. asSystem() REFUSES any
// call site not listed (runtime LogicException) and audits every elevation.
// A phpunit scan additionally fails when the code contains an asSystem() call
// site absent from this list — by S07 the justifications are on the record,
// not in anyone's memory.
return [
    'App\Services\Identity\LinkRevocationService::revoke' => 'Sole-guardian integrity check (2.2): sole-ness must count ALL active links, while RLS correctly hides co-guardians from each other. Read-only count; result never exposes the hidden rows.',

    'App\Http\Controllers\LinkController::requestByEmail' => 'B4 parent-initiated flow: pre-link student lookup by exact email — the target is by definition outside the guardian\'s scope until the link exists. Response is identical whether or not the account exists; only a pending link (student-confirmable) results.',

    'App\Http\Controllers\LinkController::schoolVouch' => 'B4 school-mediated flow: guardian lookup by exact email for a student already verified to be in the acting school. The guardian is outside the school\'s scope until this link creates the relationship.',
    'App\Services\Identity\GuardianStudentService::createStudent' => 'L4 guardian-led student creation: the child account is outside the guardian\'s scope until the link this very operation creates exists (INSERT..RETURNING checks SELECT policies on the new row). Creates exactly one student + one active link, both audited.',

    'App\Services\Identity\AuthService::login' => 'Credential-verified token issuance: login is an auth-bootstrap act regardless of any pre-existing session (account switching); the token belongs to the just-verified credential holder, not the ambient actor.',

    'App\Services\Identity\InvitationService::accept' => 'Invitation acceptance is a pre-authentication bootstrap act by design (2.11): creates the invited account and activates any school-vouched teacher affiliation — single-use token-gated writes no scoped context could perform.',

    'App\Services\Consent\ConsentSigningService::derivedStatus' => 'Derived consent status (S03): met/outstanding is an aggregate over ALL guardians\' requests, while RLS correctly hides co-guardians\' rows from each other. Returns booleans only; no row, timestamp or identity leaves the elevation.',

    'App\Services\Consent\ConsentDocumentService::download' => 'Consent document download (S03): the signed-PDF upload row is system-owned storage; read authorisation was already decided by the consent_documents RLS read set for the requesting session.',

    'App\Services\Consent\ConsentTemplateService::supersedeForLanguage' => 'OD-20a re-consent fan-out (S03): a material template change must supersede signed requests in the changed language across ALL guardians — rows the publishing admin\'s context rightly cannot read. Status transitions and fresh issuance only, each audited with the publishing admin as actor.',
    'App\Services\Money\PaymentLinkService::resolve' => 'Anonymous payment-link resolution (OD-44): the viewer holds only the bearer token; no session, no context. Reads exactly one frozen-payload row by sha256 token hash; initials-only, no other order data reachable.',

    'App\Services\Money\PaymentLinkService::confirmPayment' => 'Anonymous payment-link confirmation (OD-44): the payer holds only the bearer token. Atomic claim (active→paying CAS) serialises concurrent confirmers; provider self-confirms (OD-47); writes payment + order transition + link death, all audited.',
    'App\Services\Money\ManualPaymentService::confirm' => 'Manual payment BI-10 gate (S04B): confirmation must wait until every evidence upload is scan-clean. Scan status is a system-integrity fact; the confirmer\'s authorisation is already established by finance.confirm + BI-9. Reads only uploads.status for this payment\'s evidence; no upload content or other row leaves the elevation.',
    'App\Services\Teams\FormationService::addMember' => 'Team formation transition (S05): joining a team moves the member\'s enrolment in_pool → teamed. The enr_update policy restricts state writes to system (S04A); the joining student\'s authority was established by the pooled-enrolment + lobby-eligibility checks in their own context immediately prior. Transitions exactly this one enrolment.',
];
