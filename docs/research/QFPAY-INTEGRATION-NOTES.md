# QFPay Integration Notes — reference for the future S-QFPAY card

> Research dated 2026-07-27, against QFPay's developer centre (sdk.qfapi.com).
> REFERENCE ONLY — no build depends on this until the merchant account exists and
> the S-QFPAY card is written. The Phase-1 MockProvider is built against the
> PaymentProvider INTERFACE, not against QFPay, and needs none of this.
> Everything below should be re-verified against live docs + a QFPay integration
> manager at onboarding, because per-account provisioning varies.

## The two architecture-critical confirmations
- **Embedded checkout: SUPPORTED.** QFPay Element SDK (`qfpay.js`) renders QFPay-hosted
  payment fields INSIDE our page — no redirect to a QFPay checkout page. Flow: backend
  creates a Payment Intent (`POST /payment_element/v1/create_payment_intent`), frontend
  renders with `elements.create()` / `createEnhance()`, confirms client-side, we poll to
  reconcile. Alipay (both CN and HK) is an Element-supported method.
  - **Do NOT use** the separate "Checkout Services" product — that is a hosted/redirect page.
  - Constraint: the Element container must not sit inside a `<form>`; intents/signatures
    must be created server-side, keys never in frontend code.
  - PCI: card data goes direct to QFPay (SAQ-A-style scope). For an Alipay-CN-only flow we
    likely render only the Alipay wallet element and capture no card PAN at all.
- **Alipay CN → HKD settlement: SUPPORTED and RMB fully abstracted.** Consumer's mainland
  Alipay wallet is debited in CNY; QFPay handles FX; merchant is settled in **HKD**
  (`txcurrcd=HKD`). The platform never holds, stores, or reconciles RMB — consistent with
  our OD-40 currency decision.
  - Select mainland Alipay with `pay_tag=ALIPAYCN` (default is `ALIPAYHK`).
  - Use the **settle-by-QFPay** HK pay types: `801514` (web), `801512` (WAP/H5),
    `801510` (in-app). Confirm which are enabled on our `app_code` at onboarding.
  - Eligibility rule: only **real-name-verified mainland** Alipay accounts work
    cross-border; overseas Alipay wallets do not.

## Integration mechanics the S-QFPAY card must implement
- **Auth / signing (outbound):** headers `X-QF-APPCODE` + `X-QF-SIGN`. Signature = sort params
  ASCII-ascending, join `k=v&...`, append `client_key`, hash, uppercase. **Set
  `X-QF-SIGNTYPE: SHA256`** (MD5 is the insecure default). Amounts in cents; timestamps
  `YYYY-MM-DD hh:mm:ss`.
- **Webhook / async notification:** success-ONLY (no callback on failure — must poll to detect
  failures/missed callbacks). Verification is **MD5(raw_body_JSON + client_key)**, uppercased,
  compared to `X-QF-SIGN` — note this DIFFERS from outbound signing (a classic integration bug;
  verify against the exact received bytes). ACK with HTTP 200 body exactly `SUCCESS`. Retry
  ladder 2m→10m→10m→60m→2h→6h→15h then stops. **Dedupe on `syssn`.** Callback URL is registered
  via a support ticket (one URL per app_code); allowlist source IPs 13.228.112.115,
  18.138.115.47, 18.166.202.92; ports 80/443 only.
- **Idempotency:** NO idempotency-key header. `out_trade_no` (our order ID) is the sole
  mechanism — must be unique per merchant; duplicate → error 1102/2011. Reuse the SAME
  `out_trade_no` on network-timeout retries of the same attempt; fresh unique one per refund.
- **Refund:** full AND partial supported (some wallets no partial → 1270/1271). Request takes
  the ORIGINAL txn's `syssn` + a new unique `out_trade_no`; response returns the refund's
  `syssn` and `orig_syssn` (that pair is the linkage). Sync result PLUS async `refund` webhook;
  1143/1145 = processing → poll. Window is channel-dependent, not a fixed global period, and
  gated by same-day unsettled balance (1269 = insufficient). FPS MPM (802001) can't refund.
  Voiding not-yet-settled/authorised card uses the separate Reversal API, not Refund.
- **Transaction enquiry:** `POST /trade/v1/query` by `syssn` / `out_trade_no` / time window
  (page_size max 100). Returns txn-level status only.

## THE CONSTRAINT THAT AFFECTS DESIGN — settlement reporting
- **There is NO settlement / reconciliation report API and NO SFTP in the current public
  OpenAPI.** Enquiry returns txn status only — **no MDR/fee, no net settlement amount, no
  settlement date, no payout/batch reference.**
- A legacy `/settlement/v1/query` (with fee/net/settlement-date/currency fields) exists in
  QFPay's 2017-era GitHub docs but is NOT in the current developer centre — availability
  unconfirmed.
- **Action for S-QFPAY:** the nightly gateway-reconciliation assertion (every gateway txn
  matches a platform record) must be built on `/trade/v1/query` windowed by `sysdtm`
  (settlement-cutoff timestamp), matching `syssn`/`out_trade_no` and reconciling
  `txamt`/`txcurrcd`. Fees + net settlement must come from the merchant-portal export or a
  support-enabled settlement query. **At onboarding, ASK QFPay: can `/settlement/v1/query`
  (or a successor) be enabled, and what are the exact columns/format of the portal export?**
  Do not architect reconciliation assuming a settlement report exists until confirmed.

## Environments / limits / onboarding
- Base URLs: Production `https://openapi-hk.qfapi.com` · Live Testing
  `https://test-openapi-hk.qfapi.com` (real flow, test accounts, NO settlement) · Sandbox
  `https://openapi-int.qfapi.com` (simulated). Element script per env
  (`cdn-hk` / `test-cdn-hk` / `cdn-int` `.qfapi.com/qfpay_element/qfpay.js`).
- Rate limit: 100 rps / 400 rpm per merchant → HTTP 429 + backoff.
- No official server SDK — QFPay gives code examples (Python/Java/Node/PHP); we implement the
  thin signing/HTTP layer, which suits the PaymentProvider adapter design. Element SDK is JS.
- Onboarding: HK Business Registration + business bank account; issues `app_code`,
  `client_key`, sometimes `mchid`. Cannot get production credentials, register the webhook, or
  confirm Alipay-CN/HKD enablement until the account is approved — **the launch gate.** Request
  sandbox + live-testing test credentials (and the Postman collection) from
  technical.support@qfpay.com to build against now.

## Onboarding questions to put to QFPay (checklist for when the account opens)
1. Confirm `pay_tag=ALIPAYCN` + settle-by-QFPay pay type, settled to us in HKD, FX on QFPay side.
2. Confirm partial refunds enabled for that channel.
3. Can `/settlement/v1/query` (or successor) be enabled? If not, exact portal-export schema?
4. Does a per-request `notify_url` override the support-registered webhook URL?
5. Confirm our SAQ level for the Element integration.
