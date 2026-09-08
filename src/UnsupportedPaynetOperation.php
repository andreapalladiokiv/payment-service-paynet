<?php

declare(strict_types=1);

namespace Techork\PaymentService\Paynet;

use RuntimeException;

/**
 * Two callers — {@see PaynetGateway::void} and {@see PaynetGateway::refund} — and
 * deliberately WITHOUT the {@see \Techork\PaymentService\Gateway\Exception\UnsupportedByGateway}
 * marker, so a refusal keeps folding into a failed `GatewayResult`.
 *
 * What the two share is a legitimate caller. An abandoned hosted payment sits in
 * `RequiresAction` and needs cancelling; a payment taken through Paynet can be
 * refunded by a merchant who routed nothing wrongly. In both cases the gateway did
 * its job and lacks one primitive, which is the degrade half of the marker's test.
 * Marking either would propagate instead: the abandoned intent would be stuck with
 * no way to close it, the refund stranded with no terminal event.
 *
 * Every other Paynet refusal (authorize, capture, createCard, createPaymentMethod,
 * issueVirtualCard, updateVirtualCard, terminateVirtualCard, retryRefund) is a routing
 * mistake with no legitimate caller and throws the marked
 * {@see \Techork\PaymentService\Gateway\Exception\UnsupportedOperation} instead. Do not
 * "tidy up" by moving these two across to join them without deciding what should close
 * an abandoned intent and what should terminate an impossible refund in their place.
 */
final class UnsupportedPaynetOperation extends RuntimeException
{
    public function __construct(string $operation)
    {
        parent::__construct(sprintf('Paynet does not support "%s".', $operation));
    }
}
