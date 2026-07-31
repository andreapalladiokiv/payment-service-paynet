# Paynet gateway

`techork/payment-service-paynet` — hosted-page gateway for
[Paynet](https://paynet.md) (Moldova). The gateway supports **exactly one
operation**: `purchase` with a `HostedPayment` instrument. The buyer finishes
the payment on Paynet's own portal UI; the outcome arrives asynchronously via
webhook.

## Operations

| Operation | Behavior |
| --- | --- |
| `purchase` | `PurchaseRequest` → `POST /api/Payments/Send`, returns a `RedirectChallenge` |
| `authorize` | throws `UnsupportedOperation` — Paynet has no auth-only step |
| `createPaymentMethod`, `void`, `issueVirtualCard`, `terminateVirtualCard` | throw `UnsupportedPaynetOperation` |

`PurchaseRequest` is a `PaymentInstrumentVisitor`; only `visitHostedPayment()`
builds a payload. `CreditCard`, `Cash`, `Token` and `PaymentMethod` instruments
throw `UnsupportedInstrument` — Paynet accepts no raw card data or stored
methods.

`authorize` is declared rather than left to Omnipay's `AbstractGateway` (which
has neither the method nor `__call`) because `PaymentGatewayRouter::authorize()`
calls it unconditionally: without a declaration the call is a
`Call to undefined method` Error that the router would report as a decline. It
carries the `UnsupportedByGateway` marker, so the router rethrows it. The four
older refusals deliberately do **not** carry the marker — see the Gateway
package README.

## Purchase flow

1. **Auth** — OAuth password grant: `POST {base}/auth` with
   `merchant_user` / `merchant_user_password`, yielding a bearer `access_token`.
2. **Send** — `POST {base}/api/Payments/Send` with the invoice payload
   (`Invoice`, `MerchantCode`, ISO-numeric `Currency`, `ExternalDate`,
   `ExpiryDate` = now + 4 h, `Customer`, one `Services` entry whose `Amount`
   is the minor-unit integer). `200`/`202` with `PaymentId` + `Signature`
   is success; anything else becomes a failed response carrying the body's
   `Message`.
3. **Redirect** — `PurchaseResponse::getChallenge()` returns a
   `RedirectChallenge` whose form the buyer's browser must POST to the
   environment's hosted-page redirect URL (`…/acquiring/getecom`, see
   below): fields `operation` (= `PaymentId`),
   `ExpiryDate`, `Signature`, `LinkUrlSucces` (sic — Paynet's spelling) and
   `LinkUrlCancel`, taken from `HostedPayment::successUrl` / `cancelUrl`.

`sendData()` never throws: Guzzle errors and auth failures are converted into
failed `PurchaseResponse`s (`getMessage()` carries the reason).
`PaynetResponse::isSuccessful()` is only true with a reference and **no**
challenge and **no** error — a fresh purchase always carries a challenge, so it
is never "successful" until the webhook confirms it.

## Environments

Base and redirect URLs are hard-coded constants on `PaynetGateway`, selected by
the `environment` parameter (default `sandbox`); they are **not** read from
credentials.

| Environment | API base | Hosted-page redirect |
| --- | --- | --- |
| `sandbox` | `https://test.paynet.md:4446` | `https://test.paynet.md/acquiring/getecom` |
| `production` | `https://paynet.md:4446` | `https://paynet.md/acquiring/getecom` |

## Credentials

`GatewayCredential::getCredentials()` values are decrypted through the request's
`DecryptInterface` before use (the webhook `SignatureVerifier` reads them as
stored, without a decrypter).

| Credential | Meaning |
| --- | --- |
| `merchant_user` | OAuth username for the password grant |
| `merchant_user_password` | OAuth password |
| `merchant_code` | `MerchantCode` in the Send payload |
| `service_name`, `service_description` | Optional `Services[0]` name/description (default `Payment`) |
| `merchant_security_code` (fallback: `secret_key`) | Webhook signature secret |

## Invoice ids

Paynet's `Invoice` / `ExternalID` must be unique per partner and fit a `long`
(Paynet API v0.5). `PurchaseRequest` uses the `clientUniqueId` parameter when
set, otherwise falls back to an injected `InvoiceIdGenerator::next()`;
providing neither throws. Generators must be concurrency-safe but need not be
strictly monotonic (Paynet's own SDK example uses a millisecond timestamp).

## Webhooks

`PaynetWebhookSubscriber` (wired via `composer.json` → `extra.laravel.webhook`)
registers kind `Paynet` with the Gateway webhook registries:

- `SignatureVerifier` — expects the hex digest in the `Signature` header and
  recomputes `md5(EventDate . Eventid . EventType . Payment.Amount .
  Payment.Customer . Payment.ExternalID . Payment.ID . Payment.Merchant .
  Payment.StatusDate . secret)`, compared with `hash_equals`.
- `EventParser` — event type from `EventType`, idempotency key from `Eventid`.
  Only `PAID` is mapped to a handler; other event types resolve to no handler
  and are skipped.
- `PaymentSucceededHandler` (`PAID`) — resolves the PaymentIntent from
  `Payment.ID` via `TransactionIdResolver` (unknown reference →
  `HandlerOutcome::Delay`, i.e. retry later), then records the success through
  `GatewaySuccessRecorder` with `Payment.Amount` and the ISO-numeric
  `Payment.Currency` (unknown/missing numeric code falls back to `USD`).

## Testing

Pest unit tests stub HTTP with Guzzle's `MockHandler` via
`PurchaseRequest::setHttpClient()`; no real Paynet credentials are needed.
