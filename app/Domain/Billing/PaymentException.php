<?php

namespace App\Domain\Billing;

use RuntimeException;

class PaymentException extends RuntimeException
{
    /** @param  array<string, mixed>  $context */
    public function __construct(public readonly string $reason, public readonly array $context = [])
    {
        parent::__construct(__('billing.payment_error.'.$reason, $context));
    }
}
