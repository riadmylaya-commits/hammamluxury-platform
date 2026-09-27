<?php

namespace App\Domain\Booking;

use RuntimeException;

/** Erreur métier lisible par le client (message déjà traduit). */
class BookingException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function make(string $reason, string $key, array $replace = [], int $status = 422): self
    {
        return new self($reason, __('booking.'.$key, $replace), $status);
    }
}
