<?php

namespace App\Rules;

use App\Support\Cpf as CpfSupport;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class Cpf implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! CpfSupport::isValid(is_string($value) ? $value : null)) {
            $fail('Informe um CPF válido.');
        }
    }
}
