<?php

declare(strict_types=1);

namespace Techork\PaymentService\Paynet\Webhook\Handler;

use ArrayObject;
use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Money;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Webhook\Contract\HandlerOutcome;
use Techork\PaymentService\Gateway\Webhook\Contract\TransactionIdResolver;
use Techork\PaymentService\Gateway\Webhook\Contract\WebhookEventHandler;
use Techork\PaymentService\Gateway\Webhook\Recorder\GatewaySuccessRecorder;
use Techork\PaymentService\Gateway\Webhook\Recorder\RecorderOutcome;

/**
 * @implements WebhookEventHandler<ArrayObject>
 */
final readonly class PaymentSucceededHandler implements WebhookEventHandler
{
    public function __construct(
        private TransactionIdResolver $resolver,
        private GatewaySuccessRecorder $recorder,
    ) {}

    public function __invoke(object $event, GatewayId $gatewayId): HandlerOutcome
    {
        /** @var ArrayObject $event */
        $payload = $event->getArrayCopy();

        $gatewayReference = (string) ($payload['Payment']['ID'] ?? '');
        if ($gatewayReference === '') {
            return HandlerOutcome::Skipped;
        }

        $paymentIntentId = $this->resolver->resolvePaymentIntent($gatewayId, $gatewayReference);
        if ($paymentIntentId === null) {
            return HandlerOutcome::Delay;
        }

        $money = new Money(
            (int) ($payload['Payment']['Amount'] ?? 0),
            new Currency($this->resolveCurrencyCode((int) ($payload['Payment']['Currency'] ?? 0))),
        );

        return match ($this->recorder->onGatewaySuccess($gatewayId, $paymentIntentId, $gatewayReference, $money)) {
            RecorderOutcome::Applied => HandlerOutcome::Processed,
            RecorderOutcome::Skipped => HandlerOutcome::Skipped,
            RecorderOutcome::NotFound => HandlerOutcome::Delay,
        };
    }

    private function resolveCurrencyCode(int $numeric): string
    {
        if ($numeric === 0) {
            return 'USD';
        }

        $currencies = new ISOCurrencies;
        foreach ($currencies as $currency) {
            if ($currencies->numericCodeFor($currency) === $numeric) {
                return $currency->getCode();
            }
        }

        return 'USD';
    }
}
