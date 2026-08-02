# SPRINT S-UX2b-f — access-identity report display names (follow-up)

> **UX phase micro-card** (S-UX2b ✓ → S-UX1 ✓ → S-UX2a ✓ → **S-UX2b-f** → S-UX3 chunked → S-UX4).
> Origin: S-UX2a's flagged gap — `/reports/access-identity` returns raw `actor_id`/`student_id` with no
> name field, so AccessIdentity's id→name kills could not be done frontend-only. Ruling (a): close it
> now, before S-UX3, so no admin page is left names-broken. **Same additive LEFT-join pattern + proofs
> as S-UX2b.**

## 1. Goal

Additively add display names to the three id-bearing surfaces of the Access & Identity report, and
adopt them in `AccessIdentity.tsx` (remove the S-UX2a raw-id flag). Backend + a thin frontend consume.

| Surface (in `AccessIdentityReportController`) | Bare id | Add | Join |
|---|---|---|---|
| `auth_events` (from `audit_events`) | `actor_id` | `actor_name` | LEFT JOIN `users` on `actor_id` |
| `capability_log` (from `audit_events`) | `actor_id` | `actor_name` | LEFT JOIN `users` on `actor_id` |
| `replacement_exceptions` (`guardian_replacement_exceptions`) | `student_id` | `student_name` | LEFT JOIN `users` on `student_id` |

## 2. Constraints (identical to S-UX2b)

1. **Additive only** — every existing key stays; new `*_name` keys added.
2. **LEFT JOIN** — never drop a row; a null/dangling/RLS-hidden id → NULL name, row survives. (auth
   actors can be null/system and audit rows outlive deleted users — BI-1.)
3. **Double-RLS-gated** — the report is `audit_read`-scoped; the name join inherits `users_read`, so a
   name resolves only where the caller could already read that user (admins see all → names resolve).
4. No schema change, no migration — read-shape additions only.

## 3. Frontend adoption

`AccessIdentity.tsx`: add `actor_name`/`student_name` to the row interfaces; render `actor_name`
(null → `t('audit.system')` for auth/capability actors, or `—`) and `student_name` in the
replacement-exceptions table; **remove the S-UX2a raw-`actor_id` FLAG comments**.

## 4. VERIFY

- **Additive + names + row-count** — a test: as an audit-capable admin, `GET /reports/access-identity`
  returns the new `*_name` keys correctly populated, every pre-existing key intact, and the row counts
  of `auth_events`/`capability_log`/`replacement_exceptions` are identical with and without the joins
  (LEFT — no drop).
- **Battery** — `php artisan reconcile:run` still **58/58** (this touches no assertion).
- **Suite** — `phpunit --filter '/^(?!.*ClamAv).*/'` green.
- **Screenshot** — AccessIdentity after: the Actor column shows names (not `2/8/7…`); the exceptions
  table shows a student name.

## 5. Out of scope

- Any new surface — only the three flagged columns.
- The polymorphic `entity_id` resolver (its own later card).

## 6. Definition of done

Three LEFT-join name additions; AccessIdentity consumes them and the flag is gone; test green; battery
58/58; suite green; after-screenshot shows names. Then VERIFY → review → commit. `AUDIT.md` at the end.
