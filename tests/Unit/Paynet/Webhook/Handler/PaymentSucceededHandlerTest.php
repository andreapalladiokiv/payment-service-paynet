<?php

declare(strict_types=1);

use Money\Money;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewaySuccessRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;
use Techork\PaymentService\Paynet\Webhook\Handler\PaymentSucceededHandler;

/**
 * The event reaches the handler as the ArrayObject produced by EventParser, so
 * the fixtures below are the raw Paynet payload rather than a DTO — the handler
 * does its own digging into `Payment.*` and that digging is what is under test.
 */
function paynetWebhookPaidEvent(array $paymentOverrides = []): ArrayObject
{
    return new ArrayObject([
        'EventType' => 'PAID',
        'Eventid' => '778899',
        'Payment' => array_replace([
            'ID' => '5551234',
            'Amount' => '1050',
            // ISO 4217 *numeric*: 840 = USD. Paynet never sends the alpha code.
            'Currency' => '840',
            'Status' => 'Paid',
        ], $paymentOverrides),
    ]);
}

it('returns Skipped when the callback names no Payment.ID', function () {
    // Without a gateway reference there is nothing to correlate, and no amount
    // of retrying will produce one — so it is Skipped, not Delay, and the
    // resolver must not be consulted at all.
    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldNotReceive('resolvePaymentIntent');
    $recorder = Mockery::mock(GatewaySuccessRecorder::class);
    $recorder->shouldNotReceive('onGatewaySuccess');

    $handler = new PaymentSucceededHandler($resolver, $recorder);

    expect($handler(paynetWebhookPaidEvent(['ID' => '']), GatewayId::generate()))
        ->toBe(HandlerOutcome::Skipped);
});

it('returns Skipped when the callback has no Payment block at all', function () {
    // A malformed body that still verified: the nested lookup must coalesce to
    // '' rather than raise on the missing intermediate key.
    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldNotReceive('resolvePaymentIntent');

    $handler = new PaymentSucceededHandler($resolver, Mockery::mock(GatewaySuccessRecorder::class));

    expect($handler(new ArrayObject(['EventType' => 'PAID']), GatewayId::generate()))
        ->toBe(HandlerOutcome::Skipped);
});

it('returns Delay when the reference correlates to no PaymentIntent yet', function () {
    // Paynet's callback regularly beats our own purchase response into the
    // database. Delay means "retry later", which is the difference between a
    // late-arriving payment being booked and being dropped.
    $gatewayId = GatewayId::generate();

    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')
        ->once()
        ->withArgs(fn (GatewayId $gid, string $reference): bool => $gid->equals($gatewayId) && $reference === '5551234')
        ->andReturnNull();

    $recorder = Mockery::mock(GatewaySuccessRecorder::class);
    $recorder->shouldNotReceive('onGatewaySuccess');

    $handler = new PaymentSucceededHandler($resolver, $recorder);

    expect($handler(paynetWebhookPaidEvent(), $gatewayId))->toBe(HandlerOutcome::Delay);
});

it('records the success with the amount as minor units in the ISO-numeric currency', function () {
    // Paynet echoes the minor-unit integer we sent in `Services[].Amount`, so
    // 1050 is $10.50 and must stay 1050 — the Nuvei adapter's major-unit
    // reading does not apply here. 840 has to resolve to USD by numeric lookup.
    $gatewayId = GatewayId::generate();
    $paymentIntentId = '01929fa5-0000-7000-8000-000000000009';

    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->once()->andReturn($paymentIntentId);

    $recorder = Mockery::mock(GatewaySuccessRecorder::class);
    $recorder->shouldReceive('onGatewaySuccess')
        ->once()
        ->withArgs(function (GatewayId $gid, string $pi, string $reference, Money $amount) use ($gatewayId, $paymentIntentId): bool {
            return $gid->equals($gatewayId)
                && $pi === $paymentIntentId
                && $reference === '5551234'
                && $amount->getAmount() === '1050'
                && $amount->getCurrency()->getCode() === 'USD';
        })
        ->andReturn(RecorderOutcome::Applied);

    $handler = new PaymentSucceededHandler($resolver, $recorder);

    expect($handler(paynetWebhookPaidEvent(), $gatewayId))->toBe(HandlerOutcome::Processed);
});

it('resolves Paynet\'s home currency MDL from numeric 498', function () {
    // Paynet is Moldovan and most live traffic is MDL, so the numeric lookup is
    // pinned on a code other than USD: a table that only happened to cover 840
    // would pass the test above and fail in production.
    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->andReturn('01929fa5-0000-7000-8000-00000000000b');

    $recorder = Mockery::mock(GatewaySuccessRecorder::class);
    $recorder->shouldReceive('onGatewaySuccess')
        ->once()
        ->withArgs(fn (GatewayId $gid, string $pi, string $ref, Money $amount): bool => $amount->getAmount() === '25000'
            && $amount->getCurrency()->getCode() === 'MDL')
        ->andReturn(RecorderOutcome::Applied);

    $handler = new PaymentSucceededHandler($resolver, $recorder);

    expect($handler(paynetWebhookPaidEvent(['Amount' => '25000', 'Currency' => '498']), GatewayId::generate()))
        ->toBe(HandlerOutcome::Processed);
});

it('refuses to book a callback whose currency it cannot name', function (mixed $currency) {
    // Deliberate: defaulting an absent or unknown numeric code to USD would book
    // the payment in a currency Paynet never named. The exception surfaces the
    // delivery as failed so it is inspected, rather than recording a wrong unit.
    // Note the header is unsigned by Paynet's recipe, so a corrupted Currency
    // can arrive on an otherwise valid signature — see SignatureVerifierTest.
    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->andReturn('01929fa5-0000-7000-8000-00000000000c');

    $recorder = Mockery::mock(GatewaySuccessRecorder::class);
    $recorder->shouldNotReceive('onGatewaySuccess');

    $handler = new PaymentSucceededHandler($resolver, $recorder);
    $event = paynetWebhookPaidEvent(['Currency' => $currency]);

    expect(fn () => $handler($event, GatewayId::generate()))
        ->toThrow(RuntimeException::class, 'matches no ISO currency');
})->with([
    'absent' => [null],
    'empty string' => [''],
    'non-numeric text' => ['USD'],
    'unassigned numeric' => ['12345'],
]);

it('refuses ISO numeric 999, which names the absence of a currency rather than one', function () {
    // Was a recorded gap: 999 is ISO 4217's "no currency" placeholder and moneyphp lists it, so
    // the "unrecognised code" refusal walked straight past it and the payment was booked in XXX
    // — a currency that exists only to mean there isn't one. Reachable through a corrupted or
    // hostile Currency field, which the vendor's signature recipe does not cover.
    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->andReturn('01929fa5-0000-7000-8000-00000000000f');

    $recorder = Mockery::mock(GatewaySuccessRecorder::class);
    $recorder->shouldNotReceive('onGatewaySuccess');

    $handler = new PaymentSucceededHandler($resolver, $recorder);

    expect(fn () => $handler(paynetWebhookPaidEvent(['Currency' => 999]), GatewayId::generate()))
        ->toThrow(RuntimeException::class, 'no currency');
});

it('books an absent amount as zero in the named currency', function () {
    // Characterisation of the amount branch: a missing Payment.Amount coalesces
    // to 0 and is handed to the recorder as a zero-amount success rather than
    // rejected the way a missing currency is. Pinned so the asymmetry is a
    // visible decision — the recorder is the layer that compares the reported
    // amount against the intent.
    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->andReturn('01929fa5-0000-7000-8000-00000000000d');

    $recorder = Mockery::mock(GatewaySuccessRecorder::class);
    $recorder->shouldReceive('onGatewaySuccess')
        ->once()
        ->withArgs(fn (GatewayId $gid, string $pi, string $ref, Money $amount): bool => $amount->getAmount() === '0'
            && $amount->getCurrency()->getCode() === 'USD')
        ->andReturn(RecorderOutcome::Applied);

    $handler = new PaymentSucceededHandler($resolver, $recorder);

    expect($handler(paynetWebhookPaidEvent(['Amount' => null]), GatewayId::generate()))
        ->toBe(HandlerOutcome::Processed);
});

it('translates the recorder outcome into the handler outcome', function (RecorderOutcome $recorded, HandlerOutcome $expected) {
    // The match arms are the retry policy: Skipped is a duplicate delivery and
    // must not be retried, NotFound is a not-yet-visible aggregate and must be.
    // Swapping them either replays a payment or abandons one.
    $resolver = Mockery::mock(TransactionIdResolver::class);
    $resolver->shouldReceive('resolvePaymentIntent')->andReturn('01929fa5-0000-7000-8000-00000000000e');

    $recorder = Mockery::mock(GatewaySuccessRecorder::class);
    $recorder->shouldReceive('onGatewaySuccess')->once()->andReturn($recorded);

    $handler = new PaymentSucceededHandler($resolver, $recorder);

    expect($handler(paynetWebhookPaidEvent(), GatewayId::generate()))->toBe($expected);
})->with([
    'applied' => [RecorderOutcome::Applied, HandlerOutcome::Processed],
    'already applied' => [RecorderOutcome::Skipped, HandlerOutcome::Skipped],
    'aggregate not visible yet' => [RecorderOutcome::NotFound, HandlerOutcome::Delay],
]);
