<?php

declare(strict_types=1);

namespace Techork\PaymentService\Paynet;

use RuntimeException;

/**
 * Now down to a single caller — {@see PaynetGateway::void} — and deliberately
 * WITHOUT the {@see \Techork\PaymentService\Gateway\Exception\UnsupportedByGateway}
 * marker, so a refusal keeps folding into a failed `GatewayResult` and an
 * abandoned hosted payment can still be closed out. See that method for why
 * marking it would be a regression rather than a fix.
 *
 * Every other Paynet refusal (authorize, createPaymentMethod, issueVirtualCard,
 * terminateVirtualCard) is a routing mistake with no legitimate caller and now
 * throws the marked {@see \Techork\PaymentService\Gateway\Exception\UnsupportedOperation}
 * instead. Do not "tidy up" by moving void across to join them without deciding
 * what should close an abandoned intent in its place.
 */
final class UnsupportedPaynetOperation extends RuntimeException
{
    public function __construct(string $operation)
    {
        parent::__construct(sprintf('Paynet does not support "%s".', $operation));
    }
}
