<?php

declare(strict_types=1);

namespace Techork\PaymentService\Paynet;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Money\Currencies\ISOCurrencies;
use Money\Money;
use Techork\PaymentService\Gateway\Command\PlacementCommand;
use Techork\PaymentService\Gateway\Contract\AuthorizationResult;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;
use Override;
use RuntimeException;
use Symfony\Component\Intl\Countries;
use Techork\PaymentService\Common\Contract\PaymentInstrumentVisitor;
use Techork\PaymentService\Common\ValueObject\Customer;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\Challenge\RedirectChallenge;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Common\ValueObject\PaymentMethod;
use Techork\PaymentService\Common\ValueObject\Token;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Exception\UnsupportedInstrument;

/**
 * POST /api/Payments/Send — creates a Paynet payment and receives a PaymentId
 * plus Signature. Paynet only supports hosted-page flow, so the request
 * dispatches solely on {@see HostedPayment}; any other instrument type throws.
 *
 * The response carries a {@see RedirectChallenge}: the client's browser must
 * POST the supplied form fields to Paynet's portal (Acquiring/GetEcom) to
 * reach the hosted UI where the cardholder completes payment. Outcome is
 * delivered asynchronously via webhook.
 *
 * @implements PaymentInstrumentVisitor<array>
 */
final class Purchase implements PaymentInstrumentVisitor
{
    private const string EXPIRY_INTERVAL = 'PT4H';

    public function __construct(
        private readonly GatewayInfrastructure $infrastructure,
        private readonly PlacementCommand $command,
        private readonly Client $http = new Client,
        private readonly ?InvoiceIdGenerator $invoiceIdGenerator = null,
        private readonly string $environment = 'sandbox',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->command->instrument->accept($this);
    }

    #[Override]
    public function visitCreditCard(CreditCard $card): never
    {
        throw UnsupportedInstrument::onlyAccepts('paynet', 'purchase', HostedPayment::type(), $card);
    }

    #[Override]
    public function visitCash(Cash $cash): never
    {
        throw UnsupportedInstrument::onlyAccepts('paynet', 'purchase', HostedPayment::type(), $cash);
    }

    #[Override]
    public function visitToken(Token $token): never
    {
        throw UnsupportedInstrument::onlyAccepts('paynet', 'purchase', HostedPayment::type(), $token);
    }

    #[Override]
    public function visitPaymentMethod(PaymentMethod $paymentMethod): never
    {
        throw UnsupportedInstrument::onlyAccepts('paynet', 'purchase', HostedPayment::type(), $paymentMethod);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function visitHostedPayment(HostedPayment $hosted): array
    {
        $money = $this->command->amount;
        $credentials = $this->decryptCredentials($this->infrastructure->credential);

        $externalId = $this->resolveExternalId();
        $now = new DateTimeImmutable;
        $expiry = $now->add(new DateInterval(self::EXPIRY_INTERVAL));

        return [
            'url' => rtrim($this->resolveBaseUrl(), '/'),
            'redirect_url' => $this->resolveRedirectUrl(),
            'credentials' => $credentials,
            'hosted' => $hosted,
            'external_id' => $externalId,
            'now' => $now,
            'expiry' => $expiry,
            'body' => $this->buildPayload($money, $credentials, $externalId, $now, $expiry),
        ];
    }

    private function resolveExternalId(): int|string
    {
        $explicit = $this->command->clientUniqueId;
        if ($explicit !== null && $explicit !== '') {
            return $explicit;
        }

        $generator = $this->invoiceIdGenerator;
        if ($generator === null) {
            throw new RuntimeException('Paynet PurchaseRequest requires either a clientUniqueId or an InvoiceIdGenerator.');
        }

        return $generator->next();
    }

    private function resolveBaseUrl(): string
    {
        return $this->isProduction()
            ? PaynetGateway::PRODUCTION_BASE_URL
            : PaynetGateway::SANDBOX_BASE_URL;
    }

    private function resolveRedirectUrl(): string
    {
        return $this->isProduction()
            ? PaynetGateway::PRODUCTION_REDIRECT_URL
            : PaynetGateway::SANDBOX_REDIRECT_URL;
    }

    private function isProduction(): bool
    {
        return $this->environment === 'production';
    }

    public function charge(): AuthorizationResult
    {
        $data = $this->payload();

        try {
            $accessToken = $this->authenticate($data['url'], $data['credentials']);

            $http = $this->http;
            $response = $http->post($data['url'].'/api/Payments/Send', [
                'headers' => [
                    'Authorization' => 'Bearer '.$accessToken,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'json' => $data['body'],
                'http_errors' => false,
            ]);

            $status = $response->getStatusCode();
            $payload = json_decode((string) $response->getBody(), true) ?? [];

            if ($status !== 200 && $status !== 202) {
                return AuthorizationResult::failed($payload['Message'] ?? 'Paynet Send failed with status '.$status);
            }

            $paymentId = (string) ($payload['PaymentId'] ?? '');
            $signature = (string) ($payload['Signature'] ?? '');

            if ($paymentId === '' || $signature === '') {
                return AuthorizationResult::failed('Paynet Send response missing PaymentId or Signature');
            }

            /** @var HostedPayment $hosted */
            $hosted = $data['hosted'];

            $challenge = new RedirectChallenge(
                transactionId: $paymentId,
                url: rtrim($data['redirect_url'], '/'),
                formFields: [
                    'operation' => $paymentId,
                    'ExpiryDate' => $data['expiry']->format(DateTimeInterface::W3C),
                    'Signature' => $signature,
                    'LinkUrlSucces' => $hosted->successUrl,
                    'LinkUrlCancel' => $hosted->cancelUrl,
                ],
            );

            // `opening_transaction_reference` is added by every operation that OPENS a payment
            // intent, and by no other, because `reference` is overwritten on transition: once a
            // capture lands the row holds the settle reference and can no longer answer which
            // transaction opened the intent. `RebillingCreateAdapter` reads it back to anchor a
            // series.
            return AuthorizationResult::requiresAction($paymentId, $challenge)
                ->withMetadata(['opening_transaction_reference' => $paymentId]);
        } catch (GuzzleException|RuntimeException $e) {
            return AuthorizationResult::failed($e->getMessage());
        }
    }

    /**
     * @param  array<string, string>  $credentials
     */
    private function buildPayload(Money $money, array $credentials, int|string $externalId, DateTimeImmutable $now, DateTimeImmutable $expiry): array
    {
        return [
            'Invoice' => $externalId,
            'MerchantCode' => $credentials['merchant_code'],
            'Currency' => (new ISOCurrencies)->numericCodeFor($money->getCurrency()),
            'ExternalDate' => $now->format(DateTimeInterface::W3C),
            'ExpiryDate' => $expiry->format(DateTimeInterface::W3C),
            'Customer' => $this->buildCustomer($this->command->customer, $externalId),
            'Services' => [[
                'Name' => $credentials['service_name'] ?? 'Payment',
                'Description' => $credentials['service_description'] ?? 'Payment',
                'Amount' => (int) $money->getAmount(),
            ]],
        ];
    }

    /**
     * Paynet's `Customer` block, which is the payer and their address in one object like every
     * other provider's.
     *
     * `Code` still prefers the email over the invoice id, which is the one place left here that
     * reads an attribute as an identity — and it is deliberate rather than missed. It is Paynet's
     * customer key and existing records are filed under it, so replacing it with our own customer
     * id is a migration of their side, not a rename here. Nuvei's `userTokenId` was the same shape
     * of mistake and cost stored cards to undo, which is why this one is written down.
     */
    private function buildCustomer(?Customer $customer, int|string $externalId): array
    {
        if ($customer === null) {
            return [
                'Code' => (string) $externalId,
                'Name' => 'Customer',
                'NameFirst' => 'Customer',
                'NameLast' => '',
            ];
        }

        $identity = $customer->identity;
        $address = $customer->billingAddress;
        $email = $identity->email !== null ? (string) $identity->email : null;

        return array_filter([
            'Code' => $email ?? (string) $externalId,
            'Address' => $address->line,
            'Name' => $identity->firstName.' '.$identity->lastName,
            'NameFirst' => $identity->firstName,
            'NameLast' => $identity->lastName,
            'email' => $email,
            'Country' => Countries::getName((string) $address->country),
            'City' => $address->city,
            'PhoneNumber' => $identity->phone,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @param string $url
     * @param array<string, string> $credentials
     * @return string
     * @throws GuzzleException
     */
    private function authenticate(string $url, array $credentials): string
    {
        $http = $this->http;

        $response = $http->post($url.'/auth', [
            'form_params' => [
                'grant_type' => 'password',
                'username' => $credentials['merchant_user'],
                'password' => $credentials['merchant_user_password'],
            ],
            'headers' => ['Accept' => 'application/json'],
            'http_errors' => true,
        ]);

        $payload = json_decode((string) $response->getBody(), true) ?? [];
        $token = $payload['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Paynet OAuth response missing access_token');
        }

        return $token;
    }

    /**
     * @return array<string, string>
     */
    private function decryptCredentials(GatewayCredential $gateway): array
    {
        return array_map($this->infrastructure->decrypter->decrypt(...), $gateway->getCredentials());
    }
}
