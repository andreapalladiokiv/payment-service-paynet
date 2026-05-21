<?php

declare(strict_types=1);

namespace Techork\PaymentService\Paynet;

use RuntimeException;

final class UnsupportedPaynetOperation extends RuntimeException
{
    public function __construct(string $operation)
    {
        parent::__construct(sprintf('Paynet does not support "%s".', $operation));
    }
}
