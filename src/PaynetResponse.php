<?php

declare(strict_types=1);

namespace Techork\PaymentService\Paynet;

use Omnipay\Common\Message\AbstractResponse;
use Override;
use Techork\PaymentService\Common\Contract\Challenge;
use Techork\PaymentService\Gateway\Contract\ChallengeProvider;

class PaynetResponse extends AbstractResponse implements ChallengeProvider
{
    #[Override]
    public function isSuccessful(): bool
    {
        return isset($this->data['reference']) && $this->data['reference'] !== null
            && ! isset($this->data['challenge'])
            && ! isset($this->data['error']);
    }

    #[Override]
    public function getTransactionReference(): ?string
    {
        return $this->data['reference'] ?? null;
    }

    #[Override]
    public function getMessage(): ?string
    {
        return $this->data['error'] ?? null;
    }

    #[Override]
    public function getChallenge(): ?Challenge
    {
        return $this->data['challenge'] ?? null;
    }
}
