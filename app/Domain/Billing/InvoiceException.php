<?php

namespace App\Domain\Billing;

use RuntimeException;

class InvoiceException extends RuntimeException
{
    /** @param  list<string>  $blockers */
    public function __construct(public readonly string $reason, public readonly array $blockers = [])
    {
        parent::__construct(__('billing.invoice_error.'.$reason));
    }
}
