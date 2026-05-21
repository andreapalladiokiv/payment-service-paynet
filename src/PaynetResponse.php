<?php

declare(strict_types=1);

namespace Techork\PaymentService\Paynet;

use Omnipay\Common\Message\AbstractResponse;
use Techork\PaymentService\Common\Contract\Challenge;
use Techork\PaymentService\Gateway\Contract\ChallengeProvider;

class PaynetResponse extends AbstractResponse implements ChallengeProvider
{
    public function isSuccessful(): bool
    {
        return isset($this->data['reference']) && $this->data['reference'] !== null
            && ! isset($this->data['challenge'])
            && ! isset($this->data['error']);
    }

    public function getTransactionReference(): ?string
    {
        return $this->data['reference'] ?? null;
    }

    public function getMessage(): ?string
    {
        return $this->data['error'] ?? null;
    }

    public function getChallenge(): ?Challenge
    {
        return $this->data['challenge'] ?? null;
    }
}
