<?php

declare(strict_types=1);

namespace Techork\PaymentService\Paynet;

use Omnipay\Common\AbstractGateway;
use Omnipay\Common\Message\AbstractRequest;
use Override;
use Techork\PaymentService\Gateway\Contract\CustomerRepository;
use Techork\PaymentService\Gateway\Contract\Gateway;
use Techork\PaymentService\Gateway\Exception\UnsupportedOperation;

/**
 * Paynet gateway — hosted-page flow only. Activated by merchant supplying a
 * {@see \Techork\PaymentService\Common\ValueObject\HostedPayment} instrument
 * to {@see PaymentGatewayInterface::charge()}; Paynet's {@see PurchaseRequest}
 * visits the instrument, calls `/api/Payments/Send`, and returns a redirect
 * Challenge carrying the form the buyer's browser must POST to Paynet's UI.
 *
 * Unsupported operations (createPaymentMethod, void, issueVirtualCard, ...)
 * throw; they are not part of Paynet's product.
 */
final class PaynetGateway extends AbstractGateway implements Gateway
{
    public const string SANDBOX_BASE_URL = 'https://test.paynet.md:4446';

    public const string SANDBOX_REDIRECT_URL = 'https://test.paynet.md/acquiring/getecom';

    public const string PRODUCTION_BASE_URL = 'https://paynet.md:4446';

    public const string PRODUCTION_REDIRECT_URL = 'https://paynet.md/acquiring/getecom';

    #[Override]
    public function getName(): string
    {
        return 'paynet';
    }

    #[Override]
    public function getDefaultParameters(): array
    {
        return [
            'environment' => 'sandbox',
        ];
    }

    #[Override]
    public function setCustomerRepository(CustomerRepository $repository): void
    {
        // Paynet has no customer concept.
    }

    public function getEnvironment(): string
    {
        return $this->getParameter('environment') ?? 'sandbox';
    }

    public function setEnvironment(string $value): static
    {
        return $this->setParameter('environment', $value);
    }

    public function getApiUrl(): string
    {
        return $this->getEnvironment() === 'production'
            ? self::PRODUCTION_BASE_URL
            : self::SANDBOX_BASE_URL;
    }

    public function purchase(array $options = []): AbstractRequest
    {
        return $this->createRequest(PurchaseRequest::class, $options);
    }

    /**
     * Paynet has no auth-only product — the buyer either completes the payment
     * on the hosted page or nothing happens. Declared (rather than left to
     * `AbstractGateway`, which has no `authorize()` and no `__call`) because
     * {@see \Techork\PaymentService\Gateway\PaymentGatewayRouter::authorize}
     * calls it unconditionally: without this the call is a
     * `Call to undefined method` Error, which the router's catch would have
     * laundered into what looks like an acquirer decline.
     *
     * Carries {@see \Techork\PaymentService\Gateway\Exception\UnsupportedByGateway}
     * because reaching it is always a wiring error. A hosted payment is capture-
     * method `Immediate` by construction (the aggregate enforces it), so
     * authorize on Paynet means either a non-hosted instrument or a capture
     * method Paynet cannot honour — neither is retryable.
     */
    public function authorize(array $options = []): AbstractRequest
    {
        throw UnsupportedOperation::forGateway(
            'paynet',
            'authorize',
            'Paynet is a hosted-page gateway with no separate authorization step; use charge() instead.',
        );
    }

    /**
     * Marked, like {@see authorize}: Paynet is hosted-only, so the buyer's card
     * never passes through us and there is nothing here to tokenize. A caller
     * asking Paynet to store an instrument has picked the wrong gateway, and no
     * retry or alternative instrument changes that.
     */
    #[Override]
    public function createPaymentMethod(array $options = []): AbstractRequest
    {
        throw UnsupportedOperation::forGateway(
            'paynet',
            'createPaymentMethod',
            'Paynet is a hosted-page gateway; the card is entered on its page and never reaches us, so there is nothing to tokenize.',
        );
    }

    /**
     * The one refusal here left deliberately UNMARKED, and the reason is a
     * legitimate caller rather than history.
     *
     * {@see \Techork\PaymentService\Gateway\PaymentGatewayRouter::cancel} routes
     * to `void()`, and cancelling is exactly what an abandoned hosted payment
     * needs: it sits in `RequiresAction` while the buyer is on paynet.md, and
     * {@see \Techork\PaymentService\Domain\PaymentIntent\PaymentIntentAggregate::cancel}
     * accepts that status. Today the refusal folds into a failed `GatewayResult`,
     * `OmnipayCancelPort` turns that into `GatewayDeclinedException`, and the
     * aggregate records a terminal event — the intent gets closed out. Marking
     * this would make the exception propagate instead and leave every abandoned
     * Paynet payment stuck in `RequiresAction` with no way to close it. A worse
     * outcome than the imprecise event it currently records.
     */
    #[Override]
    public function void(array $options = []): AbstractRequest
    {
        throw new UnsupportedPaynetOperation('void');
    }

    /**
     * Marked: card issuing is a different product, not a primitive Paynet is
     * missing. Reaching either of these is a routing mistake.
     */
    #[Override]
    public function issueVirtualCard(array $options = []): AbstractRequest
    {
        throw UnsupportedOperation::forGateway(
            'paynet',
            'issueVirtualCard',
            'Paynet acquires hosted payments only and issues no cards; route card issuing to an issuing gateway.',
        );
    }

    #[Override]
    public function terminateVirtualCard(array $options = []): AbstractRequest
    {
        throw UnsupportedOperation::forGateway(
            'paynet',
            'terminateVirtualCard',
            'Paynet acquires hosted payments only and issues no cards; route card issuing to an issuing gateway.',
        );
    }

    #[Override]
    public function updateVirtualCard(array $options = []): AbstractRequest
    {
        throw UnsupportedOperation::forGateway(
            'paynet',
            'updateVirtualCard',
            'Paynet acquires hosted payments only and issues no cards; route card issuing to an issuing gateway.',
        );
    }

    #[Override]
    public function retryRefund(array $options = []): AbstractRequest
    {
        throw UnsupportedOperation::forGateway(
            'paynet',
            'retryRefund',
            'Paynet acquires hosted payments only and refunds nothing, so there is no refund to redirect onto another card.',
        );
    }
}
