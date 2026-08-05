<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\ServerRequest;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Paynet\Webhook\SignatureVerifier;

/**
 * The verifier decides whether an inbound Paynet callback is trusted, so the
 * cases below are weighted towards the rejections rather than the happy path:
 * a signature scheme that only accepts is not a signature scheme.
 *
 * Every expected digest is recomputed here from the recipe as documented in
 * README.md / the class docblock, not borrowed from the implementation, so a
 * change of field order or of the hash function fails these tests instead of
 * quietly redefining what "valid" means.
 */
function paynetWebhookCredential(array $credentials): GatewayCredential
{
    return new readonly class($credentials) implements GatewayCredential
    {
        public function __construct(private array $credentials) {}

        public function getId(): GatewayId
        {
            return GatewayId::fromString('01929fa5-0000-7000-8000-00000000000a');
        }

        public function getGatewayName(): string
        {
            return 'paynet';
        }

        public function getCredentials(): array
        {
            return $this->credentials;
        }
    };
}

/**
 * A well-formed `PAID` callback. `Payment.Status`/`Payment.Currency` are
 * deliberately present but outside the signed set — see the tampering tests.
 */
function paynetWebhookPayload(array $paymentOverrides = [], array $topOverrides = []): array
{
    return array_replace([
        'EventDate' => '2026-08-04T11:22:33',
        'Eventid' => '778899',
        'EventType' => 'PAID',
        'Payment' => array_replace([
            'ID' => '5551234',
            'Amount' => '1050',
            'Currency' => '840',
            'Customer' => 'customer-77',
            'ExternalID' => '01929fa5-0000-7000-8000-000000000009',
            'Merchant' => 'merchant-1',
            'StatusDate' => '2026-08-04T11:22:30',
            'Status' => 'Paid',
        ], $paymentOverrides),
    ], $topOverrides);
}

/**
 * Independent implementation of the documented recipe:
 * md5(EventDate . Eventid . EventType . Payment.Amount . Payment.Customer .
 *     Payment.ExternalID . Payment.ID . Payment.Merchant . Payment.StatusDate . secret)
 */
function paynetWebhookSign(array $payload, string $secret): string
{
    $payment = is_array($payload['Payment'] ?? null) ? $payload['Payment'] : [];

    return md5(implode('', [
        (string) ($payload['EventDate'] ?? ''),
        (string) ($payload['Eventid'] ?? ''),
        (string) ($payload['EventType'] ?? ''),
        (string) ($payment['Amount'] ?? ''),
        (string) ($payment['Customer'] ?? ''),
        (string) ($payment['ExternalID'] ?? ''),
        (string) ($payment['ID'] ?? ''),
        (string) ($payment['Merchant'] ?? ''),
        (string) ($payment['StatusDate'] ?? ''),
        $secret,
    ]));
}

/**
 * Builds the delivery as Paynet posts it: JSON body plus the hex digest in the
 * `Signature` header. `$body` is accepted raw so malformed-JSON cases can be
 * expressed too.
 */
function paynetWebhookRequest(string $body, ?string $signature): ServerRequest
{
    $headers = $signature === null ? [] : ['Signature' => $signature];

    return new ServerRequest('POST', 'https://merchant.example/webhooks', $headers, $body);
}

function paynetWebhookSignedRequest(array $payload, string $secret): ServerRequest
{
    return paynetWebhookRequest((string) json_encode($payload), paynetWebhookSign($payload, $secret));
}

/** Replaces one dotted path (e.g. `Payment.Amount`) in a payload copy. */
function paynetWebhookAltered(array $payload, string $path, string $value): array
{
    $segments = explode('.', $path);
    if (count($segments) === 1) {
        $payload[$segments[0]] = $value;

        return $payload;
    }

    $payload[$segments[0]][$segments[1]] = $value;

    return $payload;
}

/** A random per-test secret: no fixture ever carries a real merchant code. */
function paynetWebhookSecret(): string
{
    return 'msc_'.bin2hex(random_bytes(16));
}

it('accepts a delivery whose Signature matches the documented md5 recipe', function () {
    $secret = paynetWebhookSecret();
    $payload = paynetWebhookPayload();

    $verified = (new SignatureVerifier)->verify(
        paynetWebhookSignedRequest($payload, $secret),
        paynetWebhookCredential(['merchant_security_code' => $secret]),
    );

    expect($verified)->toBeTrue();
});

it('rejects a delivery signed with a different secret', function () {
    // The digest is well-formed hex of the right length, so only the secret
    // separates it from the accepted case above.
    $payload = paynetWebhookPayload();
    $forged = paynetWebhookSign($payload, paynetWebhookSecret());

    $verified = (new SignatureVerifier)->verify(
        paynetWebhookRequest((string) json_encode($payload), $forged),
        paynetWebhookCredential(['merchant_security_code' => paynetWebhookSecret()]),
    );

    expect($verified)->toBeFalse();
});

it('rejects a delivery that carries no Signature header at all', function () {
    // Fails closed: an unsigned POST to the webhook endpoint is the cheapest
    // attack there is, so the absent header must not be read as "nothing to
    // check".
    $secret = paynetWebhookSecret();

    $verified = (new SignatureVerifier)->verify(
        paynetWebhookRequest((string) json_encode(paynetWebhookPayload()), null),
        paynetWebhookCredential(['merchant_security_code' => $secret]),
    );

    expect($verified)->toBeFalse();
});

it('rejects a malformed Signature header', function (callable $mangle) {
    // hash_equals() compares length first, so truncated and over-long digests
    // are rejected without ever reaching a byte comparison; a non-hex string of
    // the right length exercises the byte path instead.
    $secret = paynetWebhookSecret();
    $payload = paynetWebhookPayload();

    $verified = (new SignatureVerifier)->verify(
        paynetWebhookRequest((string) json_encode($payload), $mangle(paynetWebhookSign($payload, $secret))),
        paynetWebhookCredential(['merchant_security_code' => $secret]),
    );

    expect($verified)->toBeFalse();
})->with([
    'truncated digest' => [fn (string $digest): string => substr($digest, 0, 16)],
    'digest with trailing junk' => [fn (string $digest): string => $digest.'00'],
    'not hex at all' => [fn (string $digest): string => str_repeat('zz', 16)],
    'hex with 0x prefix' => [fn (string $digest): string => '0x'.substr($digest, 2)],
]);

it('accepts an upper-case hex digest', function () {
    // Paynet's own SDK samples are inconsistent about digest case and the
    // verifier normalises with strtolower(); pinned so the normalisation is not
    // dropped as "dead" code by someone reading only the happy path.
    $secret = paynetWebhookSecret();
    $payload = paynetWebhookPayload();

    $verified = (new SignatureVerifier)->verify(
        paynetWebhookRequest((string) json_encode($payload), strtoupper(paynetWebhookSign($payload, $secret))),
        paynetWebhookCredential(['merchant_security_code' => $secret]),
    );

    expect($verified)->toBeTrue();
});

it('rejects a delivery whose signed field was altered after signing', function (string $path) {
    // The whole point of the digest: each of the nine fields Paynet signs is
    // covered, so editing any one of them in flight invalidates the delivery.
    // A field silently dropped from the recipe would show up here.
    $secret = paynetWebhookSecret();
    $payload = paynetWebhookPayload();
    $signature = paynetWebhookSign($payload, $secret);
    $tampered = paynetWebhookAltered($payload, $path, 'tampered-9');

    $verified = (new SignatureVerifier)->verify(
        paynetWebhookRequest((string) json_encode($tampered), $signature),
        paynetWebhookCredential(['merchant_security_code' => $secret]),
    );

    expect($verified)->toBeFalse();
})->with([
    'EventDate',
    'Eventid',
    'EventType',
    'Payment.Amount',
    'Payment.Customer',
    'Payment.ExternalID',
    'Payment.ID',
    'Payment.Merchant',
    'Payment.StatusDate',
]);

it('accepts a delivery whose Payment.Currency was altered, because the vendor recipe does not sign it', function () {
    // NOT a desired guarantee — a recorded limit of Paynet's scheme. Currency
    // is outside the nine signed fields, yet PaymentSucceededHandler books the
    // money in exactly that currency (840 -> USD, 498 -> MDL). A signature
    // therefore authenticates the amount but not the unit it is denominated in.
    // If the recipe ever gains Currency, this test flips and should be inverted.
    $secret = paynetWebhookSecret();
    $payload = paynetWebhookPayload();
    $signature = paynetWebhookSign($payload, $secret);
    $reDenominated = paynetWebhookAltered($payload, 'Payment.Currency', '498');

    $verified = (new SignatureVerifier)->verify(
        paynetWebhookRequest((string) json_encode($reDenominated), $signature),
        paynetWebhookCredential(['merchant_security_code' => $secret]),
    );

    expect($verified)->toBeTrue();
});

it('does not detect a digit shifted across the Amount/Customer boundary', function () {
    // Also a recorded limit, not a goal: the recipe concatenates fields without
    // separators, so the digest binds the joined string and not the field
    // boundaries. Moving the leading '7' of Customer onto the end of Amount
    // leaves the concatenation byte-identical while inflating the booked amount
    // tenfold. Pinned because the fix (a delimiter) is Paynet's to make and we
    // should notice if they ever do.
    $secret = paynetWebhookSecret();
    $payload = paynetWebhookPayload(['Amount' => '1050', 'Customer' => '77']);
    $signature = paynetWebhookSign($payload, $secret);
    $shifted = paynetWebhookPayload(['Amount' => '10507', 'Customer' => '7']);

    $verifier = new SignatureVerifier;

    expect($verifier->verify(
        paynetWebhookRequest((string) json_encode($shifted), $signature),
        paynetWebhookCredential(['merchant_security_code' => $secret]),
    ))->toBeTrue();
});

it('falls back to secret_key when no merchant_security_code is configured', function () {
    // Older Paynet gateway records only carry secret_key; both spellings must
    // keep verifying or those tenants' webhooks silently stop being accepted.
    $secret = paynetWebhookSecret();
    $payload = paynetWebhookPayload();

    $verified = (new SignatureVerifier)->verify(
        paynetWebhookSignedRequest($payload, $secret),
        paynetWebhookCredential(['secret_key' => $secret]),
    );

    expect($verified)->toBeTrue();
});

it('prefers merchant_security_code over secret_key when both are configured', function () {
    // Precedence is a security decision, not a detail: a stale secret_key left
    // on the record must not be able to authenticate deliveries once the
    // dedicated webhook secret is set.
    $webhookSecret = paynetWebhookSecret();
    $apiSecret = paynetWebhookSecret();
    $payload = paynetWebhookPayload();
    $credential = paynetWebhookCredential([
        'merchant_security_code' => $webhookSecret,
        'secret_key' => $apiSecret,
    ]);

    $verifier = new SignatureVerifier;

    expect($verifier->verify(paynetWebhookSignedRequest($payload, $webhookSecret), $credential))->toBeTrue()
        ->and($verifier->verify(paynetWebhookSignedRequest($payload, $apiSecret), $credential))->toBeFalse();
});

it('fails closed when the gateway record configures no webhook secret', function (array $credentials) {
    // An unconfigured or blanked secret must reject rather than compare against
    // the empty string, which would make md5(fields) alone a valid signature
    // anyone could compute.
    $payload = paynetWebhookPayload();

    $verified = (new SignatureVerifier)->verify(
        paynetWebhookSignedRequest($payload, ''),
        paynetWebhookCredential($credentials),
    );

    expect($verified)->toBeFalse();
})->with([
    'nothing configured' => [[]],
    'empty merchant_security_code' => [['merchant_security_code' => '']],
    'empty secret_key' => [['secret_key' => '']],
    // ?? does not step over an empty string, so a blanked security code does
    // NOT silently fall back to secret_key — it fails closed.
    'blanked code alongside a usable secret_key' => [['merchant_security_code' => '', 'secret_key' => 'unused']],
]);

it('rejects a body that is not a JSON object', function (string $body) {
    // Signature-first ordering does not help if the body cannot be read: the
    // fields being hashed come from the decoded payload, so anything that is
    // not an array has to be refused instead of hashed as empty strings.
    $verified = (new SignatureVerifier)->verify(
        paynetWebhookRequest($body, str_repeat('a', 32)),
        paynetWebhookCredential(['merchant_security_code' => paynetWebhookSecret()]),
    );

    expect($verified)->toBeFalse();
})->with([
    'empty body' => [''],
    'not json' => ['<html>gateway error</html>'],
    'truncated json' => ['{"EventType":"PAID"'],
    'json null' => ['null'],
    'json scalar' => ['"PAID"'],
    'json number' => ['1050'],
]);

it('treats absent signed fields as empty strings rather than refusing to hash', function () {
    // Paynet omits keys instead of sending nulls on some event types. The
    // recipe's `?? ''` coalescing is what keeps those deliveries verifiable, so
    // a payload carrying only EventType still has a computable digest.
    $secret = paynetWebhookSecret();
    $payload = ['EventType' => 'PAID'];

    $verified = (new SignatureVerifier)->verify(
        paynetWebhookRequest((string) json_encode($payload), md5('PAID'.$secret)),
        paynetWebhookCredential(['merchant_security_code' => $secret]),
    );

    expect($verified)->toBeTrue();
});

it('verifies the same request repeatedly, because it leaves the body readable', function () {
    // Was a characterisation of the opposite. verify() read the body with getContents() and
    // never rewound, so a second call saw an exhausted stream and rejected — and
    // WebhookRouter::identifyGateway() loops candidate credentials over ONE request object,
    // so in any install with more than one candidate only the first could ever authenticate.
    // `(string) $request->getBody()` rewinds; the second call must now agree with the first.
    $secret = paynetWebhookSecret();
    $request = paynetWebhookSignedRequest(paynetWebhookPayload(), $secret);
    $credential = paynetWebhookCredential(['merchant_security_code' => $secret]);

    $verifier = new SignatureVerifier;

    expect($verifier->verify($request, $credential))->toBeTrue()
        ->and($verifier->verify($request, $credential))->toBeTrue();
});

it('rejects the same request for a credential it was not signed with, on any attempt', function () {
    // The other half of the rewind: a readable body must not make a wrong secret pass, and
    // the order candidates are tried in must not matter.
    $secret = paynetWebhookSecret();
    $request = paynetWebhookSignedRequest(paynetWebhookPayload(), $secret);
    $verifier = new SignatureVerifier;

    expect($verifier->verify($request, paynetWebhookCredential(['merchant_security_code' => paynetWebhookSecret()])))->toBeFalse()
        ->and($verifier->verify($request, paynetWebhookCredential(['merchant_security_code' => $secret])))->toBeTrue();
});

it('compares digests in constant time', function () {
    // Timing-safety cannot be observed from the outside, so it is pinned at the
    // source: the digest comparison must stay hash_equals() and never become
    // === / == / strcmp(), any of which leaks the expected digest byte by byte
    // to an attacker who can replay a delivery with a guessed signature.
    $source = (string) file_get_contents((string) (new ReflectionClass(SignatureVerifier::class))->getFileName());

    expect($source)->toContain('hash_equals($expected,')
        ->and($source)->not->toMatch('/\$expected\s*={2,3}/')
        ->and($source)->not->toMatch('/\bstrcmp\s*\(/');
});
