<?php

declare(strict_types=1);

use Techork\PaymentService\Paynet\Webhook\EventParser;

it('maps a PAID callback to its type, idempotency key and native payload', function () {
    // The three things downstream depends on: `type` picks the handler in
    // HandlerRegistry, `externalId` is the de-duplication key for the delivery,
    // and `native` must still carry the whole payload — PaymentSucceededHandler
    // reads Payment.ID / Amount / Currency out of it, so a parser that narrowed
    // the payload to the two scalars would break the handler and not the parser.
    $parsed = (new EventParser)->parse([
        'EventType' => 'PAID',
        'Eventid' => '778899',
        'Payment' => ['ID' => '5551234', 'Amount' => '1050', 'Currency' => '840'],
    ]);

    expect($parsed->type)->toBe('PAID')
        ->and($parsed->externalId)->toBe('778899')
        ->and($parsed->native)->toBeInstanceOf(ArrayObject::class)
        ->and($parsed->native->getArrayCopy())->toBe([
            'EventType' => 'PAID',
            'Eventid' => '778899',
            'Payment' => ['ID' => '5551234', 'Amount' => '1050', 'Currency' => '840'],
        ]);
});

it('surfaces an unmapped event type verbatim instead of normalising it', function () {
    // Only PAID is registered, and the router turns "no handler for this type"
    // into HandlerOutcome::Skipped. That only works if the parser passes the
    // vendor's string through untouched: a type coerced to '' or to PAID would
    // either lose the skip or run the success handler on a failed payment.
    $parsed = (new EventParser)->parse([
        'EventType' => 'CANCELED',
        'Eventid' => '99',
    ]);

    expect($parsed->type)->toBe('CANCELED')
        ->and($parsed->externalId)->toBe('99');
});

it('returns empty strings for a payload carrying neither type nor Eventid', function () {
    // A body that survived signature verification but has no event fields must
    // still parse: the empty type resolves to no handler (Skipped) rather than
    // throwing inside the webhook transport.
    $parsed = (new EventParser)->parse([]);

    expect($parsed->type)->toBe('')
        ->and($parsed->externalId)->toBe('')
        ->and($parsed->native->getArrayCopy())->toBe([]);
});

it('stringifies a numeric Eventid so the idempotency key has one shape', function () {
    // Paynet sends Eventid as a JSON number on some event types. The key ends up
    // in storage and in equality checks against earlier deliveries, so 778899
    // and '778899' must not be able to describe two different deliveries.
    $parsed = (new EventParser)->parse([
        'EventType' => 'PAID',
        'Eventid' => 778899,
    ]);

    expect($parsed->externalId)->toBe('778899');
});

it('names PAID as the one mapped event type', function () {
    // PaynetWebhookSubscriber registers the handler under this constant, so its
    // value is part of the wire contract, not an internal label.
    expect(EventParser::TYPE_PAID)->toBe('PAID');
});
