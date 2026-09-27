<?php

namespace App\Domain\Phone;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Valide qu'un numéro (brut) est normalisable en E.164 pour le pays indiqué. */
final class PhoneRule implements ValidationRule
{
    public function __construct(private readonly ?string $country = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (PhoneNumber::normalize(is_string($value) ? $value : null, $this->country) === null) {
            $fail(__('phone.invalid'));
        }
    }
}
