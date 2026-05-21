<?php

declare(strict_types=1);

namespace Techork\PaymentService\Paynet;

/**
 * Produces unique partner-side payment identifiers for Paynet's `Invoice` /
 * `ExternalID` field. Per Paynet API v0.5, the value must fit a `long` and be
 * unique within the partner system. Strict monotonic sequencing is not
 * required — Paynet's own SDK example uses a millisecond timestamp — but the
 * generator must be safe under concurrency.
 */
interface InvoiceIdGenerator
{
    public function next(): int;
}