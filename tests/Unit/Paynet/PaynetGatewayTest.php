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
 * Paynet only does one thing: take a payment on its own hosted page. So every
 * refusal below has no legitimate caller — the card never reaches us, so there
 * is nothing to tokenize, and Paynet issues no cards at all. Reaching any of
 * them is a routing mistake, and an unmarked refusal would be recorded as an
 * acquirer decline for a request no acquirer ever saw.
 */
it('marks refusals that can only be reached by misrouting', function (string $operation) {
    $thrown = null;

    try {
        (new PaynetGateway)->{$operation}();
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(UnsupportedByGateway::class);
})->with(['authorize', 'createPaymentMethod', 'issueVirtualCard', 'terminateVirtualCard']);

/**
 * `void()` is the exception, and not for historical reasons: it backs
 * `PaymentGatewayRouter::cancel()`, which is how an ABANDONED hosted payment
 * gets closed. Such an intent sits in `RequiresAction`; today the refusal folds
 * into a failed `GatewayResult`, becomes `GatewayDeclinedException` in
 * `OmnipayCancelPort`, and the aggregate records a terminal event. Mark it and
 * the exception propagates instead, leaving every abandoned Paynet payment
 * stuck in `RequiresAction` with nothing able to close it.
 *
 * This test pins that asymmetry on purpose. If it ever fails because someone
 * marked `void`, the question to answer first is what closes an abandoned
 * intent instead — not how to make the assertion pass.
 */
it('leaves void unmarked so an abandoned hosted payment can still be closed', function () {
    $thrown = null;

    try {
        (new PaynetGateway)->void();
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(UnsupportedPaynetOperation::class)
        ->and($thrown)->not->toBeInstanceOf(UnsupportedByGateway::class);
});
