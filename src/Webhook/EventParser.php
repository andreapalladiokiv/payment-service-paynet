<?php

declare(strict_types=1);

namespace Techork\PaymentService\Paynet\Webhook;

use ArrayObject;
use Techork\PaymentService\Gateway\Webhook\Contract\EventParser as EventParserContract;
use Techork\PaymentService\Gateway\Webhook\Contract\ParsedEvent;

/**
 * Parses a Paynet webhook body. Only `PAID` is mapped today — other event
 * types surface as the raw `EventType` string so the router resolves to no
 * handler (HandlerOutcome::Skipped).
 *
 * Idempotency key: `Eventid` (unique per delivery from Paynet).
 */
final readonly class EventParser implements EventParserContract
{
    public const string TYPE_PAID = 'PAID';

    /**
     * @return ParsedEvent<ArrayObject>
     */
    public function parse(array $payload): ParsedEvent
    {
        $eventType = (string) ($payload['EventType'] ?? '');
        $eventId = (string) ($payload['Eventid'] ?? '');

        return new ParsedEvent($eventType, $eventId, new ArrayObject($payload));
    }
}
