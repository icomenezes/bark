<?php

namespace App\Support;

/**
 * Normalização e validação de CPF.
 *
 * O banco guarda o CPF formatado (000.000.000-00), mas toda comparação deve passar
 * por digits() — comparar as strings formatadas quebraria com máscaras diferentes.
 */
class Cpf
{
    public static function digits(?string $cpf): string
    {
        return preg_replace('/\D/', '', (string) $cpf);
    }

    public static function isValid(?string $cpf): bool
    {
        $digits = self::digits($cpf);

        if (strlen($digits) !== 11) {
            return false;
        }

        // Sequências repetidas (111.111.111-11 e afins) satisfazem o cálculo do DV.
        if (preg_match('/^(\d)\1{10}$/', $digits)) {
            return false;
        }

        foreach ([9, 10] as $position) {
            $sum = 0;
            for ($i = 0; $i < $position; $i++) {
                $sum += (int) $digits[$i] * ($position + 1 - $i);
            }

            $remainder = $sum % 11;
            $check = $remainder < 2 ? 0 : 11 - $remainder;

            if ((int) $digits[$position] !== $check) {
                return false;
            }
        }

        return true;
    }

    public static function format(string $cpf): string
    {
        $digits = self::digits($cpf);

        if (strlen($digits) !== 11) {
            return $cpf;
        }

        return substr($digits, 0, 3).'.'.substr($digits, 3, 3).'.'.substr($digits, 6, 3).'-'.substr($digits, 9, 2);
    }

    /** Mascara no padrão da Receita (***.456.789-**) — usado na trilha de auditoria. */
    public static function mask(string $cpf): string
    {
        $digits = self::digits($cpf);

        if (strlen($digits) !== 11) {
            return '***.***.***-**';
        }

        return '***.'.substr($digits, 3, 3).'.'.substr($digits, 6, 3).'-**';
    }
}
