<?php

namespace App\Services\Reconciliation\Assertions;

use App\Services\Reconciliation\Assertion;
use App\Services\Reconciliation\AssertionResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Leo instruction (S04B step 3): structural confinement matching S04C's
 * public-context discipline. Three parts: (a) exactly the two /pay routes are
 * the guest-accessible money surface; (b) no money-table policy admits an
 * anonymous/public context; (c) payment_links stores a hash, never plaintext,
 * and the /pay routes map only to the designated controller.
 */
class PaymentLinkSingleReaderAssertion implements Assertion
{
    private const MONEY_TABLES = ['orders', 'order_lines', 'receipts', 'receipt_sequences', 'payments', 'payment_links', 'payment_obligations', 'fee_items'];

    public function key(): string
    {
        return 'payment_links.single_reader';
    }

    public function proves(): string
    {
        return 'the token-resolution path is the ONLY unauthenticated reader of any payment data — by route table, by pg_policies, and by hash-only storage';
    }

    public function cites(): string
    {
        return 'OD-44 · Leo instruction 2026-07-27';
    }

    public function tags(): array
    {
        return ['S04B'];
    }

    public function check(): AssertionResult
    {
        $failures = [];

        // (a) guest routes touching money: exactly GET pay/{token} + POST pay/{token}/confirm
        $moneyWords = ['pay', 'order', 'receipt', 'invoice', 'payment', 'fee', 'refund'];
        $allowed = ['GET api/pay/{token}', 'POST api/pay/{token}/confirm'];
        $guestMoney = [];
        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();
            $isGuest = ! collect($middleware)->contains(fn ($m) => str_starts_with((string) $m, 'auth'));
            if (! $isGuest) {
                continue;
            }
            $uri = $route->uri();
            $touchesMoney = collect($moneyWords)->contains(fn ($w) => str_contains(strtolower($uri.' '.($route->getActionName() ?? '')), $w));
            if ($touchesMoney) {
                foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                    $guestMoney[] = "{$method} {$uri}";
                }
            }
        }
        sort($guestMoney);
        sort($allowed);
        if ($guestMoney !== $allowed) {
            $failures[] = 'guest money routes are ['.implode(', ', $guestMoney).'] — expected exactly ['.implode(', ', $allowed).']';
        }

        // (b) no anonymous/public context in any money-table policy
        $tables = "'".implode("','", self::MONEY_TABLES)."'";
        $badPolicies = DB::select("SELECT tablename, policyname FROM pg_policies
            WHERE tablename IN ({$tables}) AND (coalesce(qual,'') LIKE '%''public''%' OR coalesce(with_check,'') LIKE '%''public''%')");
        foreach ($badPolicies as $p) {
            $failures[] = "policy {$p->policyname} on {$p->tablename} references the public context";
        }
        // and every money table is RLS-forced
        $unforced = DB::select("SELECT relname FROM pg_class WHERE relname IN ({$tables})
            AND relkind = 'r' AND NOT (relrowsecurity AND relforcerowsecurity)");
        foreach ($unforced as $t) {
            $failures[] = "money table {$t->relname} is not RLS-forced";
        }

        // (c) hash-only storage + the designated controller owns both /pay routes
        $hasPlaintext = DB::selectOne("SELECT count(*) AS c FROM information_schema.columns
            WHERE table_name = 'payment_links' AND column_name = 'token'");
        if ((int) $hasPlaintext->c > 0) {
            $failures[] = 'payment_links has a plaintext token column';
        }
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/pay/') && ! str_contains((string) $route->getActionName(), 'PaymentLinkController')) {
                $failures[] = "route {$route->uri()} is not handled by PaymentLinkController";
            }
        }

        return $failures !== []
            ? AssertionResult::fail(implode(' · ', $failures))
            : AssertionResult::pass('exactly two /pay routes, no public-context money policy, all money tables RLS-forced, hash-only storage');
    }
}
