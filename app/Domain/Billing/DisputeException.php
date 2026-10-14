<?php

namespace App\Domain\Billing;

use RuntimeException;

class DisputeException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(__('billing.dispute_error.'.$reason));
    }
}
