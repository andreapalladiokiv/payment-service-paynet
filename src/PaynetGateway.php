<?php

declare(strict_types=1);

namespace Techork\PaymentService\Paynet;

use Omnipay\Common\AbstractGateway;
use Omnipay\Common\Message\AbstractRequest;
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

    public function getName(): string
    {
        return 'paynet';
    }

    public function getDefaultParameters(): array
    {
        return [
            'environment' => 'sandbox',
        ];
    }

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

    public function purchase(array $parameters = []): AbstractRequest
    {
        return $this->createRequest(PurchaseRequest::class, $parameters);
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

    public function createPaymentMethod(array $options = []): AbstractRequest
    {
        throw new UnsupportedPaynetOperation('createPaymentMethod');
    }

    public function void(array $options = []): AbstractRequest
    {
        throw new UnsupportedPaynetOperation('void');
    }

    public function issueVirtualCard(array $options = []): AbstractRequest
    {
        throw new UnsupportedPaynetOperation('issueVirtualCard');
    }

    public function terminateVirtualCard(array $options = []): AbstractRequest
    {
        throw new UnsupportedPaynetOperation('terminateVirtualCard');
    }
}
