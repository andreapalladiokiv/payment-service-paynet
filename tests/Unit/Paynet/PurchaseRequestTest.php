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
use Techork\PaymentService\Paynet\PurchaseRequest;
use Techork\PaymentService\Paynet\PurchaseResponse;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;

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
    $gw = new PaynetGateway;
    $gw->initialize();

    return $gw;
}

function makePaynetPurchaseRequest(Client $httpClient, array $override = []): PurchaseRequest
{
    /** @var PurchaseRequest $request */
    $request = makePaynetGateway()->purchase(array_merge([
        'money' => new Money(1500, new Currency('USD')),
        'gateway' => makePaynetCredential(),
        'decrypter' => makePaynetDecrypter(),
        'clientUniqueId' => '01929fa5-0000-7000-8000-aaaaaaaaaaaa',
        'instrument' => new HostedPayment(
            successUrl: 'https://merchant.example/return',
            cancelUrl: 'https://merchant.example/return',
        ),
    ], $override));
    $request->setHttpClient($httpClient);

    return $request;
}

function makeMockClient(array $responses): Client
{
    $mock = new MockHandler($responses);
    $handler = HandlerStack::create($mock);

    return new Client(['handler' => $handler]);
}

it('returns RedirectChallenge with Paynet form fields on successful Send', function () {
    $client = makeMockClient([
        new Response(200, [], json_encode(['access_token' => 'tok', 'token_type' => 'bearer', 'expires_in' => 3600])),
        new Response(200, [], json_encode(['PaymentId' => 'pay-42', 'Signature' => 'sig-abc'])),
    ]);

    $request = makePaynetPurchaseRequest($client);
    /** @var PurchaseResponse $response */
    $response = $request->send();

    expect($response)->toBeInstanceOf(PurchaseResponse::class)
        ->and($response->getTransactionReference())->toBe('pay-42')
        ->and($response->getChallenge())->toBeInstanceOf(RedirectChallenge::class);

    $challenge = $response->getChallenge();
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

    $request = makePaynetPurchaseRequest($client);
    $response = $request->send();

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->getTransactionReference())->toBeNull()
        ->and($response->getChallenge())->toBeNull()
        ->and($response->getMessage())->toBe('Invalid merchant code');
});

it('returns failed response when Send payload is missing PaymentId', function () {
    $client = makeMockClient([
        new Response(200, [], json_encode(['access_token' => 'tok', 'expires_in' => 3600])),
        new Response(200, [], json_encode(['Signature' => 'sig'])),
    ]);

    $request = makePaynetPurchaseRequest($client);
    $response = $request->send();

    expect($response->isSuccessful())->toBeFalse()
        ->and($response->getMessage())->toContain('missing PaymentId');
});

it('throws when instrument is CreditCard (not hosted)', function () {
    $client = makeMockClient([]);
    $card = new CreditCard(
        new Number('424242', '4242', CardBrand::Visa),
        Expiration::fromMonthAndYear(12, 2030),
        new Holder('Test'),
        new Cvc,
    );

    $request = makePaynetPurchaseRequest($client, ['instrument' => $card]);

    $request->getData();
})->throws(UnsupportedInstrument::class, 'accepts only a "hosted" instrument on the "purchase" operation, got "card"');

it('throws when instrument is Cash', function () {
    $client = makeMockClient([]);
    $request = makePaynetPurchaseRequest($client, ['instrument' => new Cash]);

    $request->getData();
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

    $request = makePaynetPurchaseRequest($client);
    $request->send();

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

    $request = makePaynetPurchaseRequest($client, ['clientUniqueId' => null]);
    $request->setInvoiceIdGenerator($generator);
    $request->send();

    expect($captured)->not->toBeNull()
        ->and($captured['Invoice'])->toBe(4242424242);
});

it('throws when neither clientUniqueId nor InvoiceIdGenerator is provided', function () {
    $client = makeMockClient([]);
    $request = makePaynetPurchaseRequest($client, ['clientUniqueId' => null]);

    $request->getData();
})->throws(RuntimeException::class, 'requires either a clientUniqueId or an InvoiceIdGenerator');
