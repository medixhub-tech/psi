<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class Money
{
    public static function cents(string $value): int
    {
        if (! preg_match('/^([0-9]{1,15})(?:[.,]([0-9]{1,2}))?$/D', $value, $m)) {
            throw ValidationException::withMessages(['amount' => 'Informe um valor com até duas casas decimais, sem separador de milhar.']);
        }

        return ((int) $m[1]) * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    public static function decimal(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function display(int $cents): string
    {
        $digits = (string) intdiv(abs($cents), 100);

        return ($cents < 0 ? '-' : '').'R$ '.preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $digits).','.str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }
}
