<?php

namespace App\Services\Money;

use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The forwardable payment link (OD-44, STEP 3 rulings 2026-07-27):
 * - token: 256-bit random; ONLY the sha256 hash is stored; plaintext exists
 *   solely in the mint response to the minting guardian.
 * - multi-view (ruling 2): viewable until paid/expired; "single-use" is the
 *   PAYMENT ACT, not the view.
 * - payload FROZEN at mint — initials, never a name (no_pii assertion).
 * - anonymous resolution/confirmation are the ONLY unauthenticated money
 *   paths (single_reader assertion), each an audited system elevation.
 * - the paid-link RACE (Leo addition): payment claims the link atomically
 *   active → paying via a single CAS UPDATE; the loser sees constant 404.
 */
class PaymentLinkService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly PaymentProvider $provider,
    ) {}

    /** @return array{link_id: string, url: string, expires_at: string} plaintext token leaves ONCE, here */
    public function mint(string $orderId, User $guardian): array
    {
        $order = DB::table('orders')->where('id', $orderId)->first() ?? abort(404); // RLS-shaped
        if (! in_array($order->payer_party, ['guardian', 'student'], true)) {
            throw ValidationException::withMessages(['order' => ['Payment links exist for family-paid orders only (OD-50b)']]);
        }
        if ($order->status !== 'issued') {
            throw ValidationException::withMessages(['order' => ["Order is {$order->status} — nothing to pay"]]);
        }
        if ($order->payment_due_at !== null && now()->greaterThan($order->payment_due_at)) {
            throw ValidationException::withMessages(['order' => ['The payment deadline has passed']]);
        }
        $programme = DB::table('programmes')->where('id', $order->programme_id)->first();
        $studentName = (string) DB::table('users')->where('id', $order->student_id)->value('name');

        $token = Str::random(64); // 256+ bits of entropy
        $id = (string) Str::uuid7();
        DB::table('payment_links')->insert([
            'id' => $id, 'order_id' => $order->id, 'student_id' => $order->student_id,
            'minted_by' => $guardian->id, 'token_hash' => hash('sha256', $token),
            'status' => 'active', 'order_reference' => Str::upper(Str::substr($order->id, -12)),
            'amount_minor' => (int) $order->total_amount_minor, 'currency' => $order->currency,
            'programme_name_en' => $programme->name_en, 'programme_name_tc' => $programme->name_tc,
            'programme_name_sc' => $programme->name_sc,
            'student_initials' => self::initials($studentName), // FROZEN — never joined live
            'expires_at' => $order->payment_due_at ?? now()->addDays(7),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record('payment_link', $id, 'payment_link.minted',
            toState: 'active', programmeId: (int) $order->programme_id,
            payloadAfter: ['order_id' => $order->id], actor: $guardian);

        return [
            'link_id' => $id,
            // Absolute URL to the PUBLIC PAGE (D-ii, S04C) — a forwardable link
            // opens the initials-only render for a human, never the JSON API.
            // Was url("/pay/{token}") = APP_URL+path = http://localhost/pay/... —
            // wrong host/port and pointed at a page that did not exist.
            'url' => rtrim((string) config('app.public_url'), '/')."/pay/{$token}",
            'expires_at' => (string) ($order->payment_due_at ?? now()->addDays(7)),
        ];
    }

    /**
     * OD-44 (Leo fix, STEP 3b): spaced Latin names → first-of-each (W.Y.C.).
     * Unspaced CJK names → the GIVEN name with the FAMILY name hidden
     * (陳詠恩 → 詠恩): surname-only leaked the shared, searchable family name
     * and told the payer nothing — the given name confirms the child to
     * someone who knows them, which is the entire point.
     */
    public static function initials(string $name): string
    {
        $name = trim($name);
        $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($parts) <= 1) {
            $given = mb_substr($name, 1); // drop the leading family name
            return $given !== '' ? $given : $name; // degenerate single-char name
        }

        return implode('', array_map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)).'.', $parts));
    }

    /**
     * Anonymous view (multi-view, ruling 2). Constant-shape null for unknown,
     * expired, paid AND paying — indistinguishable by design.
     *
     * @return array{order_reference: string, amount_minor: int, currency: string, programme_name: array{en: string, tc: string, sc: string}, student_initials: string}|null
     */
    public function resolve(string $token): ?array
    {
        return app(\App\Services\Authz\ScopeContext::class)->asSystem(
            'Anonymous payment-link resolution (OD-44): the viewer holds only the bearer token; no session, no context. Reads exactly one frozen-payload row by sha256 token hash; initials-only, no other order data reachable.',
            function () use ($token): ?array {
                $link = DB::table('payment_links')->where('token_hash', hash('sha256', $token))->first();
                if ($link === null || $link->status !== 'active' || now()->greaterThan($link->expires_at)) {
                    return null; // unknown/expired/paid/paying — one shape
                }

                return [
                    'order_reference' => $link->order_reference,
                    'amount_minor' => (int) $link->amount_minor,
                    'currency' => $link->currency,
                    'programme_name' => ['en' => $link->programme_name_en, 'tc' => $link->programme_name_tc, 'sc' => $link->programme_name_sc],
                    'student_initials' => $link->student_initials,
                ];
            },
        );
    }

    /**
     * Anonymous payment (the single-use ACT). Claim first — one CAS UPDATE
     * flips active → paying; exactly one concurrent confirmer can win the row
     * lock. Provider IO runs OUTSIDE any DB lock; failure reverts the claim.
     *
     * @return array{paid: bool, receipt_number: int|null}|null null = constant 404
     */
    public function confirmPayment(string $token): ?array
    {
        return app(\App\Services\Authz\ScopeContext::class)->asSystem(
            'Anonymous payment-link confirmation (OD-44): the payer holds only the bearer token. Atomic claim (active→paying CAS) serialises concurrent confirmers; provider self-confirms (OD-47); writes payment + order transition + link death, all audited.',
            function () use ($token): ?array {
                $hash = hash('sha256', $token);
                // THE RACE GUARD: single-statement compare-and-set; the row lock
                // makes exactly one concurrent claimant see status='active'
                $claimed = DB::update(
                    "UPDATE payment_links SET status = 'paying', updated_at = now()
                     WHERE token_hash = ? AND status = 'active' AND expires_at > now()", [$hash]);
                if ($claimed !== 1) {
                    return null; // already paying/paid/expired/unknown — one shape
                }
                $link = DB::table('payment_links')->where('token_hash', $hash)->first();

                $ref = $this->provider->createSession($link->order_id, (int) $link->amount_minor, $link->currency);
                if (! $this->provider->confirm($ref)) {
                    DB::table('payment_links')->where('id', $link->id)->update(['status' => 'active', 'updated_at' => now()]); // compensate
                    return null;
                }

                return DB::transaction(function () use ($link, $ref): array {
                    $paymentId = (string) Str::uuid7();
                    DB::table('payments')->insert([
                        'id' => $paymentId, 'order_id' => $link->order_id, 'origin' => 'provider',
                        'provider' => 'mock', 'provider_ref' => $ref,
                        'amount_minor' => (int) $link->amount_minor, 'currency' => $link->currency,
                        'via_link' => true, 'status' => 'confirmed', 'confirmed_at' => now(),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    DB::table('orders')->where('id', $link->order_id)->update(['status' => 'paid', 'updated_at' => now()]);
                    DB::table('payment_links')->where('id', $link->id)
                        ->update(['status' => 'paid', 'paid_at' => now(), 'updated_at' => now()]);
                    $receipt = app(ReceiptService::class)->issue($link->order_id, null);
                    $this->audit->record('payment', $paymentId, 'payment.confirmed',
                        toState: 'confirmed',
                        payloadAfter: ['order_id' => $link->order_id, 'via_link' => true, 'provider' => 'mock',
                            'amount_minor' => (int) $link->amount_minor, 'receipt_number' => $receipt->receipt_number]);
                    $this->audit->record('order', $link->order_id, 'order.paid',
                        fromState: 'issued', toState: 'paid');
                    $this->audit->record('payment_link', $link->id, 'payment_link.paid',
                        fromState: 'paying', toState: 'paid');

                    return ['paid' => true, 'receipt_number' => (int) $receipt->receipt_number];
                });
            },
        );
    }
}
