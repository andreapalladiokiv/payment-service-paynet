<?php

declare(strict_types=1);

namespace Techork\PaymentService\Paynet;

use Override;
use Techork\PaymentService\Gateway\Contract\CustomerRepository;
use Techork\PaymentService\Gateway\Contract\Gateway;
use Techork\PaymentService\Gateway\Exception\UnsupportedOperation;
use Techork\PaymentService\Gateway\Command\CaptureCommand;
use Techork\PaymentService\Gateway\Concern\HoldsInfrastructure;
use Techork\PaymentService\Gateway\ValueObject\GatewayInfrastructure;
use Techork\PaymentService\Gateway\Contract\GatewayResult;
use Techork\PaymentService\Gateway\Command\CancelCommand;
use Techork\PaymentService\Gateway\Command\RefundCommand;
use Techork\PaymentService\Gateway\Command\PlacementCommand;
use Techork\PaymentService\Gateway\Command\RebillingCommand;
use Techork\PaymentService\Gateway\Contract\AuthorizationResult;
use Techork\PaymentService\Gateway\Command\VaultCommand;
use Techork\PaymentService\Gateway\Contract\RegistrationResult;
use Techork\PaymentService\Gateway\Command\IssueCardCommand;
use Techork\PaymentService\Gateway\Command\TerminateCardCommand;
use Techork\PaymentService\Gateway\Command\UpdateCardCommand;
use Techork\PaymentService\Gateway\Contract\VirtualCardResult;

/**
 * Paynet gateway — hosted-page flow only. Activated by merchant supplying a
 * {@see \Techork\PaymentService\Common\ValueObject\HostedPayment} instrument
 * to {@see the gateway roles::charge()}; Paynet's {@see PurchaseRequest}
 * visits the instrument, calls `/api/Payments/Send`, and returns a redirect
 * Challenge carrying the form the buyer's browser must POST to Paynet's UI.
 *
 * Unsupported operations (createPaymentMethod, void, issueVirtualCard, ...)
 * throw; they are not part of Paynet's product.
 */
final class PaynetGateway implements Gateway
{
    use HoldsInfrastructure;

    private string $environment = 'sandbox';

    public const string SANDBOX_BASE_URL = 'https://test.paynet.md:4446';

    public const string SANDBOX_REDIRECT_URL = 'https://test.paynet.md/acquiring/getecom';

    public const string PRODUCTION_BASE_URL = 'https://paynet.md:4446';

    public const string PRODUCTION_REDIRECT_URL = 'https://paynet.md/acquiring/getecom';

    #[Override]
    public function getName(): string
    {
        return 'paynet';
    }

    /**
     * Reads the one setting Paynet has. Sandbox unless the deployment says otherwise — the
     * default lives here, at the point of use, rather than in a `getDefaultParameters()` the
     * initializer had to merge before anything could read it.
     */
    #[Override]
    public function configure(GatewayInfrastructure $infrastructure): void
    {
        $this->infrastructure = $infrastructure;
        $this->environment = $infrastructure->stringSetting('environment', 'sandbox');
    }

    public function getEnvironment(): string
    {
        return $this->environment;
    }

    public function getApiUrl(): string
    {
        return $this->getEnvironment() === 'production'
            ? self::PRODUCTION_BASE_URL
            : self::SANDBOX_BASE_URL;
    }

    #[Override]
    public function charge(PlacementCommand $command): AuthorizationResult
    {
        return new Purchase($this->infrastructure(), $command, environment: $this->environment)->charge();
    }

    /**
     * Paynet has no auth-only product — the buyer either completes the payment
     * on the hosted page or nothing happens. Declared (rather than left to
     * `AbstractGateway`, which has no `authorize()` and no `__call`) because
     * the placement operations
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
    #[Override]
    public function authorize(PlacementCommand $command): AuthorizationResult
    {
        throw UnsupportedOperation::forGateway(
            'paynet',
            'authorize',
            'Paynet is a hosted-page gateway with no separate authorization step; use charge() instead.',
        );
    }

    /**
     * Marked, like {@see authorize}, and unreachable for the same reason: a hosted payment is
     * capture-method `Immediate` by construction, so it never parks at `Authorized` and there
     * is never an authorization here to capture. A caller arriving with one has the wrong
     * gateway.
     *
     * Until this was declared the method did not exist at all, and
     * {@see \Techork\PaymentService\Gateway\the gateway stack::capture} called it anyway:
     * the `Call to undefined method` Error folded into a failed `GatewayResult`, which
     * `CaptureAdapter` turned into `GatewayDeclinedException` — an acquirer decline
     * recorded for a payment no acquirer was asked about.
     */
    #[Override]
    public function capture(CaptureCommand $command): GatewayResult
    {
        throw UnsupportedOperation::forGateway(
            'paynet',
            'capture',
            'Paynet settles on its hosted page, so a payment is taken in full or not at all; there is no authorization to capture.',
        );
    }

    /**
     * Marked, like {@see createPaymentMethod}, which is the same absence for somebody's
     * instrument rather than a bare one: the buyer's card is entered on paynet.md and never
     * reaches us, so there is nothing here to tokenize either way.
     */
    #[Override]
    public function tokenize(VaultCommand $command): RegistrationResult
    {
        throw UnsupportedOperation::forGateway(
            'paynet',
            'tokenize',
            'Paynet is a hosted-page gateway; the card is entered on its page and never reaches us, so there is nothing to tokenize.',
        );
    }

    /**
     * Marked, like {@see authorize}: Paynet is hosted-only, so the buyer's card
     * never passes through us and there is nothing here to tokenize. A caller
     * asking Paynet to store an instrument has picked the wrong gateway, and no
     * retry or alternative instrument changes that.
     */
    #[Override]
    public function registerPaymentMethod(VaultCommand $command): RegistrationResult
    {
        throw UnsupportedOperation::forGateway(
            'paynet',
            'registerPaymentMethod',
            'Paynet is a hosted-page gateway; the card is entered on its page and never reaches us, so there is nothing to tokenize.',
        );
    }

    /**
     * The one refusal here left deliberately UNMARKED, and the reason is a
     * legitimate caller rather than history.
     *
     * the cancel path routes
     * to `void()`, and cancelling is exactly what an abandoned hosted payment
     * needs: it sits in `RequiresAction` while the buyer is on paynet.md, and
     * {@see \Techork\PaymentService\Domain\PaymentIntent\PaymentIntentAggregate::cancel}
     * accepts that status. Today the refusal folds into a failed `GatewayResult`,
     * `CancelAdapter` turns that into `GatewayDeclinedException`, and the
     * aggregate records a terminal event — the intent gets closed out. Marking
     * this would make the exception propagate instead and leave every abandoned
     * Paynet payment stuck in `RequiresAction` with no way to close it. A worse
     * outcome than the imprecise event it currently records.
     */
    #[Override]
    public function cancel(CancelCommand $command): GatewayResult
    {
        throw new UnsupportedPaynetOperation('cancel');
    }

    /**
     * The second refusal left deliberately UNMARKED, and for {@see void}'s reason rather than
     * {@see authorize}'s: the caller is legitimate.
     *
     * A payment really was taken through Paynet, and a merchant refunding it has routed
     * nothing wrongly — Paynet simply has no refund product. That is a missing primitive in a
     * gateway that otherwise did its job, which is the degrade half of
     * {@see \Techork\PaymentService\Gateway\Exception\UnsupportedByGateway}'s test. Folding
     * into a failed `GatewayResult` lets the refund saga record `RefundFailed` and carry on;
     * marking it would propagate instead and strand the refund with no terminal event.
     *
     * This preserves what the missing method already did by accident. Declaring it only moves
     * the outcome from a `Call to undefined method` string to a sentence a merchant can read.
     */
    #[Override]
    public function refund(RefundCommand $command): GatewayResult
    {
        throw new UnsupportedPaynetOperation('refund');
    }

    /**
     * Marked: card issuing is a different product, not a primitive Paynet is
     * missing. Reaching either of these is a routing mistake.
     */
    #[Override]
    public function issueVirtualCard(IssueCardCommand $command): VirtualCardResult
    {
        throw UnsupportedOperation::forGateway(
            'paynet',
            'issueVirtualCard',
            'Paynet acquires hosted payments only and issues no cards; route card issuing to an issuing gateway.',
        );
    }

    #[Override]
    public function terminateVirtualCard(TerminateCardCommand $command): GatewayResult
    {
        throw UnsupportedOperation::forGateway(
            'paynet',
            'terminateVirtualCard',
            'Paynet acquires hosted payments only and issues no cards; route card issuing to an issuing gateway.',
        );
    }

    #[Override]
    public function updateVirtualCard(UpdateCardCommand $command): VirtualCardResult
    {
        throw UnsupportedOperation::forGateway(
            'paynet',
            'updateVirtualCard',
            'Paynet acquires hosted payments only and issues no cards; route card issuing to an issuing gateway.',
        );
    }

    #[Override]
    public function retryRefund(RefundCommand $command): GatewayResult
    {
        throw UnsupportedOperation::forGateway(
            'paynet',
            'retryRefund',
            'Paynet acquires hosted payments only and refunds nothing, so there is no refund to redirect onto another card.',
        );
    }

    /**
     * Marked for {@see authorize()}'s reason, one step further out: a series has no meaning on a
     * gateway that cannot even hold an authorization.
     */
    #[Override]
    public function authorizeRebilling(RebillingCommand $command): AuthorizationResult
    {
        throw UnsupportedOperation::forGateway(
            'paynet',
            'authorizeRebilling',
            'Paynet is a hosted-page gateway with no authorization step, so there is no series for a payment to belong to.',
        );
    }

}
