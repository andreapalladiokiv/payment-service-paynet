<?php

declare(strict_types=1);

use Techork\PaymentService\Gateway\Exception\UnsupportedByGateway;
use Techork\PaymentService\Gateway\Exception\UnsupportedOperation;
use Techork\PaymentService\Paynet\PaynetGateway;
use Techork\PaymentService\Paynet\PurchaseRequest;
use Techork\PaymentService\Paynet\UnsupportedPaynetOperation;

it('has name paynet', function () {
    expect((new PaynetGateway)->getName())->toBe('paynet');
});

it('creates a purchase request', function () {
    expect((new PaynetGateway)->purchase())->toBeInstanceOf(PurchaseRequest::class);
});

/**
 * The point of declaring `authorize()` at all: `AbstractGateway` has neither
 * the method nor `__call`, and `PaymentGatewayRouter::authorize()` calls it
 * unconditionally — so without a declaration this is `Error: Call to undefined
 * method`, which the router's catch turns into what reads as a decline.
 */
it('refuses authorize as an invariant violation rather than an undefined method', function () {
    $gateway = new PaynetGateway;

    expect(fn () => $gateway->authorize())
        ->toThrow(UnsupportedOperation::class, 'Paynet is a hosted-page gateway with no separate authorization step');
});

it('marks the authorize refusal so the router rethrows instead of folding it into a result', function () {
    $thrown = null;

    try {
        (new PaynetGateway)->authorize();
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(UnsupportedByGateway::class);
});

/**
 * Deliberately NOT marked: `void()` backs `PaymentGatewayRouter::cancel()`,
 * where a refusal has always surfaced as a failed `GatewayResult` rather than
 * an exception. Marking it would change that mid-saga, which is a separate
 * decision from the hosted-payment work.
 */
it('leaves the older per-operation refusals unmarked', function (string $operation) {
    $thrown = null;

    try {
        (new PaynetGateway)->{$operation}();
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(UnsupportedPaynetOperation::class)
        ->and($thrown)->not->toBeInstanceOf(UnsupportedByGateway::class);
})->with(['void', 'createPaymentMethod', 'issueVirtualCard', 'terminateVirtualCard']);
