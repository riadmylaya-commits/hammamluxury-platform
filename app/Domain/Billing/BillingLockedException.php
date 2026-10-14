<?php

namespace App\Domain\Billing;

use RuntimeException;

class BillingLockedException extends RuntimeException
{
    /** @param list<string> $blockers */
    public function __construct(public readonly array $blockers)
    {
        parent::__construct('Billing is locked: '.implode(', ', $blockers));
    }
}
