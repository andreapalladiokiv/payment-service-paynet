<?php

declare(strict_types=1);

namespace Techork\PaymentService\Paynet\Webhook\Handler;

use ArrayObject;
use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Money;
use Override;
use RuntimeException;
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

    #[Override]
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

    /**
     * Paynet reports the currency as an ISO 4217 numeric code. An absent or unrecognised code
     * is a malformed callback: defaulting it to USD would book the payment in a currency
     * Paynet never named.
     *
     * @return non-empty-string
     */
    private function resolveCurrencyCode(int $numeric): string
    {
        // 999 is ISO 4217's own "no currency" placeholder, and moneyphp lists it as XXX — so
        // the loop below would resolve it and the payment would be booked in a currency that
        // exists only to mean there isn't one. It belongs with the refusal, not the matches.
        if ($numeric === 999) {
            throw new RuntimeException('Paynet reported ISO numeric currency 999, which is the "no currency" placeholder rather than a currency.');
        }

        $currencies = new ISOCurrencies;
        foreach ($currencies as $currency) {
            if ($currencies->numericCodeFor($currency) === $numeric) {
                return $currency->getCode();
            }
        }

        throw new RuntimeException("Paynet reported ISO numeric currency $numeric, which matches no ISO currency.");
    }
}
