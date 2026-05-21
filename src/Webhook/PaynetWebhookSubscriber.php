<?php

declare(strict_types=1);

namespace Techork\PaymentService\Paynet\Webhook;

use Techork\PaymentService\Paynet\Webhook\Handler\PaymentSucceededHandler;
use Techork\PaymentService\Gateway\Webhook\Contract\WebhookSubscriber;
use Techork\PaymentService\Gateway\Webhook\HandlerRegistry;
use Techork\PaymentService\Gateway\Webhook\VerifierRegistry;

final readonly class PaynetWebhookSubscriber implements WebhookSubscriber
{
    private const string KIND = 'Paynet';

    public function __construct(
        private SignatureVerifier $verifier,
        private EventParser $parser,
        private PaymentSucceededHandler $paymentSucceeded,
    ) {}

    public function subscribe(VerifierRegistry $verifiers, HandlerRegistry $handlers): void
    {
        $verifiers->register(self::KIND, $this->verifier, $this->parser);
        $handlers->register(self::KIND, EventParser::TYPE_PAID, $this->paymentSucceeded);
    }
}
