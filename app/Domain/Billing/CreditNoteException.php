<?php

namespace App\Domain\Billing;

use RuntimeException;

class CreditNoteException extends RuntimeException
{
    /** @param  array<string, mixed>  $context */
    public function __construct(public readonly string $reason, public readonly array $context = [])
    {
        parent::__construct(__('billing.credit_note_error.'.$reason, $context));
    }
}
