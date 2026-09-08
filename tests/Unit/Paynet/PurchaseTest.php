<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Money\Currency;
use Money\Money;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\ValueObject\CardBrand;
use Techork\PaymentService\Common\ValueObject\Cash;
use Techork\PaymentService\Common\ValueObject\Challenge\RedirectChallenge;
use Techork\PaymentService\Common\ValueObject\CreditCard;
use Techork\PaymentService\Common\ValueObject\CreditCard\Cvc;
use Techork\PaymentService\Common\ValueObject\CreditCard\Expiration;
use Techork\PaymentService\Common\ValueObject\CreditCard\Holder;
use Techork\PaymentService\Common\ValueObject\CreditCard\Number;
use Techork\PaymentService\Common\ValueObject\HostedPayment;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Exception\UnsupportedInstrument;
use Techork\PaymentService\Paynet\InvoiceIdGenerator;
use Techork\PaymentService\Paynet\PaynetGateway;
use Techork\PaymentService\Paynet\Purchase;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\PaymentInitiation;
use Techork\PaymentService\Gateway\Command\PlacementCommand;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;
use Techork\PaymentService\Gateway\Contract\GatewayCustomerRepository;

function makePaynetCredential(): GatewayCredential
{
    return new readonly class implements GatewayCredential
    {
        public function getId(): GatewayId
        {
            return GatewayId::fromString('01929fa5-0000-7000-8000-000000000001');
        }

        public function getGatewayName(): string
        {
            return 'paynet';
        }

        public function getCredentials(): array
        {
            return [
                'url' => 'enc:https://paynet.example',
                'redirect_url' => 'enc:https://portal.paynet.example',
                'merchant_user' => 'enc:user',
                'merchant_user_password' => 'enc:pass',
                'merchant_code' => 'enc:975860',
            ];
        }
    };
}

function makePaynetDecrypter(): DecryptInterface
{
    return new readonly class implements DecryptInterface
    {
        public function decrypt(string $value): string
        {
            return str_starts_with($value, 'enc:') ? substr($value, 4) : $value;
        }
    };
}

function makePaynetGateway(): PaynetGateway
{
    return new PaynetGateway;
}

function makePaynetPurchase(Client $httpClient, array $override = [], ?InvoiceIdGenerator $invoiceIds = null): Purchase
{
    $request = paynetPurchase(array_merge([
        'money' => new Money(1500, new Currency('USD')),
        'gateway' => makePaynetCredential(),
        'decrypter' => makePaynetDecrypter(),
        'clientUniqueId' => '01929fa5-0000-7000-8000-aaaaaaaaaaaa',
        'instrument' => new HostedPayment(
            successUrl: 'https://merchant.example/return',
            cancelUrl: 'https://merchant.example/return',
        ),
    ], $override), $httpClient, $invoiceIds);

    return $request;
}

function makeMockClient(array $responses): Client
{
    $mock = new MockHandler($responses);
    $handler = HandlerStack::create($mock);

    return new Client(['handler' => $handler]);
}

/**
 * @param  array<string, mixed>  $options
 */
function paynetPurchase(array $options, ?Client $http = null, ?InvoiceIdGenerator $invoiceIds = null): Purchase
{
    $command = new PlacementCommand(
        gatewayId: GatewayId::generate(),
        instrument: $options['instrument'] ?? Mockery::mock(PaymentInstrument::class),
        amount: $options['money'] ?? new Money(1000, new Currency('USD')),
        clientUniqueId: $options['clientUniqueId'] ?? null,
        billingAddress: $options['billingAddress'] ?? null,
        threeDS: $options['threeDS'] ?? null,
        statementDescription: $options['statementDescription'] ?? null,
        description: $options['description'] ?? null,
        initiation: $options['initiation'] ?? PaymentInitiation::CardholderInitiated,
    );

    // Built directly, transport and all: the gateway charges rather than handing back an
    // operation, and every collaborator it would have supplied is an argument here.
    return new Purchase(
        new GatewayInfrastructure(
            $options['gateway'] ?? Mockery::mock(GatewayCredential::class, ['getId' => GatewayId::generate()]),
            $options['decrypter'] ?? Mockery::mock(DecryptInterface::class),
            $options['referenceResolver'] ?? Mockery::mock(GatewayInstrumentRepository::class, ['find' => null]),
            $options['customerRepository'] ?? Mockery::mock(GatewayCustomerRepository::class, ['find' => null]),
        ),
        $command,
        $http ?? new Client,
        $invoiceIds,
        environment: $options['environment'] ?? 'sandbox',
    );
}

it('returns RedirectChallenge with Paynet form fields on successful Send', function () {
    $client = makeMockClient([
        new Response(200, [], json_encode(['access_token' => 'tok', 'token_type' => 'bearer', 'expires_in' => 3600])),
        new Response(200, [], json_encode(['PaymentId' => 'pay-42', 'Signature' => 'sig-abc'])),
    ]);

    $result = makePaynetPurchase($client)->charge();

    expect($result->isRequiresAction())->toBeTrue()
        ->and($result->reference)->toBe('pay-42')
        ->and($result->challenge)->toBeInstanceOf(RedirectChallenge::class);

    $challenge = $result->challenge;
    expect($challenge->url)->toBe('https://test.paynet.md/acquiring/getecom')
        ->and($challenge->transactionId)->toBe('pay-42')
        ->and($challenge->formFields['operation'])->toBe('pay-42')
        ->and($challenge->formFields['Signature'])->toBe('sig-abc')
        ->and($challenge->formFields['LinkUrlSucces'])->toBe('https://merchant.example/return')
        ->and($challenge->formFields['LinkUrlCancel'])->toBe('https://merchant.example/return');
});

it('returns failed response with error message on non-2xx from Send', function () {
    $client = makeMockClient([
        new Response(200, [], json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
        new Response(400, [], json_encode(['Message' => 'Invalid merchant code'])),
    ]);

    $result = makePaynetPurchase($client)->charge();

    expect($result->success)->toBeFalse()
        ->and($result->reference)->toBeNull()
        ->and($result->challenge)->toBeNull()
        ->and($result->message)->toBe('Invalid merchant code');
});

it('returns failed response when Send payload is missing PaymentId', function () {
    $client = makeMockClient([
        new Response(200, [], json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
        new Response(200, [], json_encode(['Signature' => 'sig'])),
    ]);

    $result = makePaynetPurchase($client)->charge();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toContain('missing PaymentId');
});

it('throws when instrument is CreditCard (not hosted)', function () {
    $client = makeMockClient([]);
    $card = new CreditCard(
        new Number('424242', '4242', CardBrand::Visa),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Test'),
        new Cvc,
    );

    $request = makePaynetPurchase($client, ['instrument' => $card]);

    $request->payload();
})->throws(UnsupportedInstrument::class, 'accepts only a "hosted" instrument on the "purchase" operation, got "card"');

it('throws when instrument is Cash', function () {
    $client = makeMockClient([]);
    $request = makePaynetPurchase($client, ['instrument' => new Cash]);

    $request->payload();
})->throws(UnsupportedInstrument::class, 'accepts only a "hosted" instrument on the "purchase" operation, got "cash"');

it('builds Send payload with Invoice from clientUniqueId', function () {
    $captured = null;
    $client = makeMockClient([
        new Response(200, [], json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
        function ($req) use (&$captured) {
            $captured = json_decode((string) $req->getBody(), true);

            return new Response(200, [], json_encode(['PaymentId' => 'x', 'Signature' => 'y']));
        },
    ]);

    $request = makePaynetPurchase($client);
    $request->charge();

    expect($captured)->not->toBeNull()
        ->and($captured['Invoice'])->toBe('01929fa5-0000-7000-8000-aaaaaaaaaaaa')
        ->and($captured['MerchantCode'])->toBe('975860')
        ->and($captured['Currency'])->toBe(840) // USD numeric
        ->and($captured['Services'][0]['Amount'])->toBe(1500);
});

it('falls back to InvoiceIdGenerator when clientUniqueId is null', function () {
    $captured = null;
    $client = makeMockClient([
        new Response(200, [], json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
        function ($req) use (&$captured) {
            $captured = json_decode((string) $req->getBody(), true);

            return new Response(200, [], json_encode(['PaymentId' => 'x', 'Signature' => 'y']));
        },
    ]);

    $generator = new class implements InvoiceIdGenerator
    {
        public function next(): int
        {
            return 4242424242;
        }
    };

    makePaynetPurchase($client, ['clientUniqueId' => null], $generator)->charge();

    expect($captured)->not->toBeNull()
        ->and($captured['Invoice'])->toBe(4242424242);
});

it('throws when neither clientUniqueId nor InvoiceIdGenerator is provided', function () {
    $client = makeMockClient([]);
    $request = makePaynetPurchase($client, ['clientUniqueId' => null]);

    $request->payload();
})->throws(RuntimeException::class, 'requires either a clientUniqueId or an InvoiceIdGenerator');
