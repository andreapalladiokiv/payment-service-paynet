<?php

declare(strict_types=1);

use Techork\PaymentService\Gateway\Exception\UnsupportedByGateway;
use Techork\PaymentService\Gateway\Exception\UnsupportedOperation;
use Techork\PaymentService\Paynet\PaynetGateway;
use Techork\PaymentService\Paynet\Purchase;
use Techork\PaymentService\Paynet\UnsupportedPaynetOperation;
use Money\Currency;
use Money\Money;
use Techork\PaymentService\Gateway\Command\CaptureCommand;
use Techork\PaymentService\Gateway\ValueObject\GatewayId;
use Techork\PaymentService\Gateway\Command\CancelCommand;
use Techork\PaymentService\Gateway\Command\RefundCommand;
use Techork\PaymentService\Common\ValueObject\CustomerId;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Gateway\Command\PlacementCommand;
use Techork\PaymentService\Gateway\Command\IssueCardCommand;
use Techork\PaymentService\Gateway\Command\TerminateCardCommand;
use Techork\PaymentService\Gateway\Command\UpdateCardCommand;
use Techork\PaymentService\Gateway\Command\RegisterCustomerCommand;
use Techork\PaymentService\Gateway\Command\VaultCommand;
use Techork\PaymentService\Gateway\ValueObject\CardSpendCategory;

/**
 * Capture takes a typed command now, so the datasets below cannot call it bare. The helper keeps
 * the refusal sets intact — what they pin is the refusal, not the signature — and shrinks as the
 * remaining operations move onto roles of their own.
 */
function paynetInvoke(Techork\PaymentService\Paynet\PaynetGateway $gateway, string $operation): mixed
{
    return match ($operation) {
        'authorize' => $gateway->authorize(new PlacementCommand(
            gatewayId: GatewayId::generate(),
            instrument: Mockery::mock(PaymentInstrument::class),
            amount: new Money(100, new Currency('USD')),
        )),
        'capture' => $gateway->capture(new CaptureCommand(
            gatewayId: GatewayId::generate(),
            transactionReference: 'ref',
            amount: new Money(100, new Currency('USD')),
        )),
        'cancel' => $gateway->cancel(new CancelCommand(GatewayId::generate(), 'ref')),
        'refund', 'retryRefund' => $gateway->{$operation}(new RefundCommand(
            gatewayId: GatewayId::generate(),
            transactionReference: 'ref',
            amount: new Money(100, new Currency('USD')),
        )),
        'tokenize', 'registerPaymentMethod' => $gateway->{$operation}(new VaultCommand(
            gatewayId: GatewayId::generate(),
            instrument: Mockery::mock(PaymentInstrument::class),
        )),
        'registerCustomer' => $gateway->registerCustomer(new RegisterCustomerCommand(
            gatewayId: GatewayId::generate(),
            customer: paynetSuiteCustomer(firstName: 'Ada', lastName: 'Lovelace'),
        )),
        'issueVirtualCard' => $gateway->issueVirtualCard(new IssueCardCommand(
            gatewayId: GatewayId::generate(),
            transactionReference: 'sale-guid',
            amountLimit: new Money(100, new Currency('USD')),
            spendCategory: CardSpendCategory::TravelAir,
        )),
        'updateVirtualCard' => $gateway->updateVirtualCard(new UpdateCardCommand(
            GatewayId::generate(),
            'card-guid',
            new Money(100, new Currency('USD')),
            CardSpendCategory::TravelAir,
        )),
        'terminateVirtualCard' => $gateway->terminateVirtualCard(
            new TerminateCardCommand(GatewayId::generate(), 'card-guid'),
        ),
        default => $gateway->{$operation}(),
    };
}

it('has name paynet', function () {
    expect((new PaynetGateway)->getName())->toBe('paynet');
});

/*
 * A test asserting that the gateway hands back a `Purchase` lived here, reaching it through the
 * `purchase()` accessor that has since gone: `charge()` charges, so which class it built on the
 * way is a return type PHP checks. The payload and the result mapping are pinned in PurchaseTest.
 */

/**
 * The point of declaring `authorize()` at all: `AbstractGateway` has neither
 * the method nor `__call`, and the gateway stack calls it
 * unconditionally — so without a declaration this is `Error: Call to undefined
 * method`, which the router's catch turns into what reads as a decline.
 */
it('refuses authorize as an invariant violation rather than an undefined method', function () {
    $gateway = new PaynetGateway;

    expect(fn () => paynetInvoke($gateway, 'authorize'))
        ->toThrow(UnsupportedOperation::class, 'Paynet is a hosted-page gateway with no separate authorization step');
});

it('marks the authorize refusal so the router rethrows instead of folding it into a result', function () {
    $thrown = null;

    try {
        paynetInvoke(new PaynetGateway, 'authorize');
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
        paynetInvoke(new PaynetGateway, $operation);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(UnsupportedByGateway::class);
})->with(['authorize', 'capture', 'tokenize', 'registerPaymentMethod', 'issueVirtualCard', 'terminateVirtualCard']);

/**
 * `void()` is the exception, and not for historical reasons: it backs
 * the gateway stack, which is how an ABANDONED hosted payment
 * gets closed. Such an intent sits in `RequiresAction`; today the refusal folds
 * into a failed `GatewayResult`, becomes `GatewayDeclinedException` in
 * `CancelAdapter`, and the aggregate records a terminal event. Mark it and
 * the exception propagates instead, leaving every abandoned Paynet payment
 * stuck in `RequiresAction` with nothing able to close it.
 *
 * This test pins that asymmetry on purpose. If it ever fails because someone
 * marked `void`, the question to answer first is what closes an abandoned
 * intent instead — not how to make the assertion pass.
 */
it('leaves cancel unmarked so an abandoned hosted payment can still be closed', function () {
    $thrown = null;

    try {
        paynetInvoke(new PaynetGateway, 'cancel');
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(UnsupportedPaynetOperation::class)
        ->and($thrown)->not->toBeInstanceOf(UnsupportedByGateway::class);
});

/**
 * `capture` and `createCard` did not exist here at all until the contract declared them,
 * and the gateway stack called both regardless. The `Call to undefined method` Error
 * folded into a failed result, so a capture routed to a hosted-only gateway was recorded
 * as an acquirer decline — the exact lie the marker exists to prevent. They join the set
 * above rather than getting tests of their own because the reason is identical: neither
 * has a legitimate caller. A hosted payment is capture-method `Immediate` by construction
 * and never parks at `Authorized`, and the buyer's card is entered on paynet.md and never
 * reaches us.
 */
it('refuses capture with a reason a merchant can read rather than an undefined method', function () {
    expect(fn () => paynetInvoke(new PaynetGateway, 'capture'))
        ->toThrow(UnsupportedOperation::class, 'there is no authorization to capture');
});

/**
 * `refund` is the second unmarked refusal, and it joins {@see void} for that method's
 * reason rather than the misroute set's: the caller is legitimate. A payment really was
 * taken through Paynet, and a merchant refunding it routed nothing wrongly — Paynet simply
 * has no refund product. That is a missing primitive in a gateway that otherwise did its
 * job, so it degrades: the failed `GatewayResult` lets the refund saga record
 * `RefundFailed` and carry on. Marking it would propagate instead and strand the refund
 * with no terminal event.
 *
 * Like the `void` assertion, this pins an asymmetry on purpose. If it fails because
 * someone marked `refund`, decide what terminates an impossible refund before making the
 * assertion pass.
 */
it('leaves refund unmarked so a refund Paynet cannot make still reaches a terminal event', function () {
    $thrown = null;

    try {
        paynetInvoke(new PaynetGateway, 'refund');
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(UnsupportedPaynetOperation::class)
        ->and($thrown)->not->toBeInstanceOf(UnsupportedByGateway::class);
});

/**
 * A customer id for the commands these tests route.
 */
function paynetTestCustomerId(): CustomerId
{
    return CustomerId::fromString('01920000-0000-7000-8000-00000000cafe');
}

/**
 * Paynet has no customer object: the buyer is identified per hosted payment and nothing outlives
 * one, so there is no id to mint, look up or send. Marked, like the rest, so the stack rethrows
 * instead of recording a decline for a call nobody made.
 */
it('refuses to register a customer, having no customer object at all', function () {
    $thrown = null;

    try {
        paynetInvoke(new PaynetGateway, 'registerCustomer');
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(UnsupportedOperation::class)
        ->and($thrown)->toBeInstanceOf(UnsupportedByGateway::class)
        ->and($thrown->getMessage())->toContain('Paynet has no customer object');
});
