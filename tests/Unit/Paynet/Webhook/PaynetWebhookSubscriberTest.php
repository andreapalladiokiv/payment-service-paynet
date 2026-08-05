<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\ServerRequest;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayCredentialRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\StoredWebhookCall;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\HandlerRegistry;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewaySuccessRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;
use Techork\PaymentService\Gateway\Webhook\VerifierRegistry;
use Techork\PaymentService\Gateway\Webhook\WebhookRouter;
use Techork\PaymentService\Paynet\PaynetGateway;
use Techork\PaymentService\Paynet\Webhook\EventParser;
use Techork\PaymentService\Paynet\Webhook\Handler\PaymentSucceededHandler;
use Techork\PaymentService\Paynet\Webhook\PaynetWebhookSubscriber;
use Techork\PaymentService\Paynet\Webhook\SignatureVerifier;

/**
 * These tests drive the real VerifierRegistry / HandlerRegistry / WebhookRouter
 * rather than test doubles for them. Wiring bugs — a registry key that does not
 * match the gateway name, a constructor nobody can satisfy, a handler bound to
 * the wrong event type — only exist between the classes, so a subscriber test
 * that asserted against mocked registries would prove nothing about them.
 *
 * The payload/credential/digest helpers are deliberately re-declared here under
 * their own prefix instead of reaching for `SignatureVerifierTest.php`'s: Pest
 * helpers are global, so borrowing one would make this file pass only when the
 * other file happens to be part of the same run.
 */
function paynetWiringSecret(): string
{
    return 'msc_'.bin2hex(random_bytes(16));
}

function paynetWiringCredential(string $secret): GatewayCredential
{
    return new readonly class($secret) implements GatewayCredential
    {
        public function __construct(private string $secret) {}

        public function getId(): GatewayId
        {
            return GatewayId::fromString('01929fa5-0000-7000-8000-00000000001a');
        }

        public function getGatewayName(): string
        {
            return 'paynet';
        }

        public function getCredentials(): array
        {
            return ['merchant_security_code' => $this->secret];
        }
    };
}

function paynetWiringPayload(string $eventType = 'PAID'): array
{
    return [
        'EventDate' => '2026-08-04T11:22:33',
        'Eventid' => '778899',
        'EventType' => $eventType,
        'Payment' => [
            'ID' => '5551234',
            'Amount' => '1050',
            'Currency' => '840',
            'Customer' => 'customer-77',
            'ExternalID' => '01929fa5-0000-7000-8000-000000000009',
            'Merchant' => 'merchant-1',
            'StatusDate' => '2026-08-04T11:22:30',
        ],
    ];
}

function paynetWiringSign(array $payload, string $secret): string
{
    $payment = is_array($payload['Payment'] ?? null) ? $payload['Payment'] : [];

    return md5(implode('', [
        (string) $payload['EventDate'],
        (string) $payload['Eventid'],
        (string) $payload['EventType'],
        (string) $payment['Amount'],
        (string) $payment['Customer'],
        (string) $payment['ExternalID'],
        (string) $payment['ID'],
        (string) $payment['Merchant'],
        (string) $payment['StatusDate'],
        $secret,
    ]));
}

function paynetWiringRequest(array $payload, string $signature): ServerRequest
{
    return (new ServerRequest(
        'POST',
        'https://merchant.example/webhooks',
        ['Signature' => $signature],
        (string) json_encode($payload),
    ))->withParsedBody($payload);
}

function paynetWebhookSubscriberFor(
    TransactionIdResolver $resolver,
    GatewaySuccessRecorder $recorder,
): PaynetWebhookSubscriber {
    return new PaynetWebhookSubscriber(
        new SignatureVerifier,
        new EventParser,
        new PaymentSucceededHandler($resolver, $recorder),
    );
}

/** Single-tenant credential repository, as the router's candidate iteration sees it. */
function paynetWebhookRepository(GatewayCredential $credential): GatewayCredentialRepository
{
    return new readonly class($credential) implements GatewayCredentialRepository
    {
        public function __construct(private GatewayCredential $credential) {}

        public function findOrFail(GatewayId $gatewayId): GatewayCredential
        {
            return $this->credential;
        }

        public function all(): iterable
        {
            return [$this->credential];
        }
    };
}

it('registers the verifier and parser under the kind the gateway reports', function () {
    // The router looks kinds up by GatewayCredential::getGatewayName(), which for
    // this package is PaynetGateway::getName() = 'paynet', while the subscriber
    // registers the literal 'Paynet'. The registry lowercases both ends; this
    // pins that the two spellings still meet, because if they ever stop meeting
    // every Paynet delivery silently resolves to no verifier and is dropped.
    $verifiers = new VerifierRegistry;
    $handlers = new HandlerRegistry;

    paynetWebhookSubscriberFor(
        Mockery::mock(TransactionIdResolver::class),
        Mockery::mock(GatewaySuccessRecorder::class),
    )->subscribe($verifiers, $handlers);

    $kind = (new PaynetGateway)->getName();

    expect($verifiers->hasKind($kind))->toBeTrue()
        ->and($verifiers->verifier($kind))->toBeInstanceOf(SignatureVerifier::class)
        ->and($verifiers->parser($kind))->toBeInstanceOf(EventParser::class);
});

it('registers the success handler for PAID and for nothing else', function () {
    // Only PAID is mapped. An unmapped type must resolve to no handler so the
    // router reports Skipped rather than running the success path on, say, a
    // cancellation.
    $verifiers = new VerifierRegistry;
    $handlers = new HandlerRegistry;

    paynetWebhookSubscriberFor(
        Mockery::mock(TransactionIdResolver::class),
        Mockery::mock(GatewaySuccessRecorder::class),
    )->subscribe($verifiers, $handlers);

    expect($handlers->resolve('paynet', EventParser::TYPE_PAID))
        ->toBeInstanceOf(PaymentSucceededHandler::class)
        ->and($handlers->resolve('paynet', 'CANCELED'))->toBeNull()
        ->and($handlers->resolve('paynet', ''))->toBeNull();
});

it('identifies the tenant and the idempotency key from a signed delivery', function () {
    // End to end over the real router: signature verification, kind resolution
    // and Eventid extraction in one pass. This is the path a live delivery takes
    // before anything is stored, so it is the one that has to work on the real
    // classes and not on doubles.
    $secret = paynetWiringSecret();
    $payload = paynetWiringPayload();
    $credential = paynetWiringCredential($secret);

    $verifiers = new VerifierRegistry;
    $handlers = new HandlerRegistry;
    paynetWebhookSubscriberFor(
        Mockery::mock(TransactionIdResolver::class),
        Mockery::mock(GatewaySuccessRecorder::class),
    )->subscribe($verifiers, $handlers);

    $router = new WebhookRouter(paynetWebhookRepository($credential), $verifiers, $handlers);

    $match = $router->identifyGateway(paynetWiringRequest($payload, paynetWiringSign($payload, $secret)));

    expect($match)->not->toBeNull()
        ->and($match->kind)->toBe('paynet')
        ->and($match->externalId)->toBe('778899')
        ->and($match->gatewayId->equals($credential->getId()))->toBeTrue();
});

it('identifies no tenant when the delivery is not signed for any candidate', function () {
    // The rejection has to survive the wiring too: a forged delivery must leave
    // identifyGateway with null so nothing is stored or dispatched under a
    // tenant it does not belong to.
    $payload = paynetWiringPayload();
    $credential = paynetWiringCredential(paynetWiringSecret());

    $verifiers = new VerifierRegistry;
    $handlers = new HandlerRegistry;
    paynetWebhookSubscriberFor(
        Mockery::mock(TransactionIdResolver::class),
        Mockery::mock(GatewaySuccessRecorder::class),
    )->subscribe($verifiers, $handlers);

    $router = new WebhookRouter(paynetWebhookRepository($credential), $verifiers, $handlers);

    // Signed with a secret no configured tenant holds.
    $forged = paynetWiringRequest($payload, paynetWiringSign($payload, paynetWiringSecret()));

    expect($router->identifyGateway($forged))->toBeNull();
});

it('dispatches a stored PAID delivery through the parser into the handler', function () {
    // The other half of the chain, from the stored record onwards: the parser's
    // ArrayObject has to be exactly what the handler can dig Payment.* out of.
    // A DTO mismatch between the two would be invisible to per-class tests.
    $gatewayId = GatewayId::generate();
    $payload = paynetWiringPayload();

    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')
        ->once()
        ->withArgs(fn (GatewayId $gid, string $reference): bool => $reference === '5551234')
        ->andReturn('01929fa5-0000-7000-8000-000000000009');

    $recorder = Mockery::mock(GatewaySuccessRecorder::class);
    $recorder->shouldReceive('onGatewaySuccess')->once()->andReturn(RecorderOutcome::Applied);

    $verifiers = new VerifierRegistry;
    $handlers = new HandlerRegistry;
    paynetWebhookSubscriberFor($resolver, $recorder)->subscribe($verifiers, $handlers);

    $router = new WebhookRouter(paynetWebhookRepository(
        paynetWiringCredential(paynetWiringSecret()),
    ), $verifiers, $handlers);

    $outcome = $router->dispatch(new StoredWebhookCall('paynet', $gatewayId, $payload));

    expect($outcome)->toBe(HandlerOutcome::Processed);
});

it('skips a stored delivery whose event type has no registered handler', function () {
    // Paynet sends more event types than we map. They must come back as Skipped
    // — neither retried forever nor mistaken for a payment.
    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldNotReceive('resolvePaymentIntent');

    $verifiers = new VerifierRegistry;
    $handlers = new HandlerRegistry;
    paynetWebhookSubscriberFor($resolver, Mockery::mock(GatewaySuccessRecorder::class))
        ->subscribe($verifiers, $handlers);

    $router = new WebhookRouter(paynetWebhookRepository(
        paynetWiringCredential(paynetWiringSecret()),
    ), $verifiers, $handlers);

    $outcome = $router->dispatch(new StoredWebhookCall(
        'paynet',
        GatewayId::generate(),
        paynetWiringPayload('CANCELED'),
    ));

    expect($outcome)->toBe(HandlerOutcome::Skipped);
});
