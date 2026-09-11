<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Gateway\Command\PlacementCommand;
use Techork\PaymentService\Gateway\Contract\GatewayCustomerRepository;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;
use Techork\PaymentService\Paynet\PaynetGateway;
use Techork\PaymentService\Paynet\Purchase;

/**
 * Live integration test against the Paynet SANDBOX (test.paynet.md:4446). Skipped unless
 * credentials are provided:
 *
 *   PAYNET_SANDBOX_MERCHANT_USER=... \
 *   PAYNET_SANDBOX_MERCHANT_USER_PASSWORD=... \
 *   PAYNET_SANDBOX_MERCHANT_CODE=... \
 *   vendor/bin/pest src/Paynet/tests/Integration/PaynetSandboxTest.php
 *
 * WHAT THIS CAN ASSERT, AND WHY IT STOPS THERE. Paynet's charge() is the hosted-page flow:
 * it authenticates (POST /auth, password grant), creates the payment server-side
 * (POST /api/Payments/Send), and answers `requiresAction` with a redirect form — money moves
 * only when a human completes that redirect in a browser, and the outcome arrives through
 * the DMN-style webhook. There is no capture/void/refund on Paynet at all
 * (UnsupportedPaynetOperation), so the house "void after hold" discipline has nothing to
 * release: the cleanup is that the payment is never completed. The test pins the shape of
 * the redirect — the same thing the ConnexPay hosted-page test does for a payload
 * established by probing rather than documented.
 */
const PAYNET_SANDBOX_SKIP = 'Set PAYNET_SANDBOX_MERCHANT_USER / _MERCHANT_USER_PASSWORD / _MERCHANT_CODE to run the Paynet sandbox integration test.';

function paynetSandboxConfigured(): bool
{
    return (getenv('PAYNET_SANDBOX_MERCHANT_USER') ?: '') !== ''
        && (getenv('PAYNET_SANDBOX_MERCHANT_USER_PASSWORD') ?: '') !== ''
        && (getenv('PAYNET_SANDBOX_MERCHANT_CODE') ?: '') !== '';
}

function paynetSandboxInfrastructure(): GatewayInfrastructure
{
    return new GatewayInfrastructure(
        new readonly class implements GatewayCredential
        {
            public function getId(): GatewayId
            {
                return GatewayId::generate();
            }

            public function getGatewayName(): string
            {
                return 'Paynet';
            }

            public function getCredentials(): array
            {
                return [
                    'merchant_user' => (string) getenv('PAYNET_SANDBOX_MERCHANT_USER'),
                    'merchant_user_password' => (string) getenv('PAYNET_SANDBOX_MERCHANT_USER_PASSWORD'),
                    'merchant_code' => (string) getenv('PAYNET_SANDBOX_MERCHANT_CODE'),
                ];
            }
        },
        new readonly class implements Techork\PaymentService\Common\Contract\DecryptInterface
        {
            public function decrypt(string $data): string
            {
                return $data;
            }
        },
        Mockery::mock(GatewayInstrumentRepository::class, ['find' => null]),
        Mockery::mock(GatewayCustomerRepository::class, ['find' => null]),
        ['environment' => 'sandbox'],
    );
}

it('creates a hosted payment and returns the redirect form', function () {
    // The `Invoice` Paynet correlates the webhook on: unique per partner, and small enough
    // to sit in a long.
    $invoice = (string) (time() * 100 + random_int(0, 99));

    $result = new Purchase(
        paynetSandboxInfrastructure(),
        new PlacementCommand(
            gatewayId: GatewayId::generate(),
            instrument: new HostedPayment(
                successUrl: 'https://foundation-tests.example/paid',
                cancelUrl: 'https://foundation-tests.example/cancelled',
            ),
            amount: new Money(1099, new Currency('USD')),
            clientUniqueId: $invoice,
            customer: paynetSuiteCustomer(),
        ),
        environment: 'sandbox',
    )->charge();

    // A hosted payment is requires_action by construction: nothing has been asked of an
    // acquirer, the buyer has merely been sent somewhere.
    expect($result->isRequiresAction())->toBeTrue($result->message ?? 'payment creation failed')
        ->and($result->reference)->not->toBeEmpty()
        ->and($result->challenge)->not->toBeNull()
        // The sandbox redirect target the browser would be POSTed to — pinned so a move
        // off test.paynet.md surfaces here rather than in production wiring.
        ->and($result->challenge->url)->toBe(PaynetGateway::SANDBOX_REDIRECT_URL)
        ->and($result->challenge->formFields)->toHaveKeys(['operation', 'Signature', 'LinkUrlSucces', 'LinkUrlCancel', 'ExpiryDate'])
        ->and($result->metadata)->toHaveKey('opening_transaction_reference', $result->reference);
})->skip(! paynetSandboxConfigured(), PAYNET_SANDBOX_SKIP);