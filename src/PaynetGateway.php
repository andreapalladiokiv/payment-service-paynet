<?php

declare(strict_types=1);

namespace Techork\PaymentService\Paynet;

use Omnipay\Common\AbstractGateway;
use Omnipay\Common\Message\AbstractRequest;
use Techork\PaymentService\Gateway\Contract\CustomerRepository;
use Techork\PaymentService\Gateway\Contract\Gateway;

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
