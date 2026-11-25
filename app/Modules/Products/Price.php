<?php

namespace App\Modules\Products;

use App\Modules\Restaurants\Currencies;
use Illuminate\Validation\ValidationException;

final class Price
{
    public static function validate(string $amount, string $currency): string
    {
        $digits = Currencies::MINOR_UNITS[$currency];
        if (strlen(explode('.', $amount, 2)[1] ?? '') > $digits) {
            throw ValidationException::withMessages(['priceAmount' => 'Use the precision of the restaurant currency.']);
        }

        return self::format($amount, 4);
    }

    public static function format(string $amount, int $scale): string
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        if ($scale === 0) {
            return $whole;
        }

        return $whole.'.'.substr(str_pad($fraction, $scale, '0'), 0, $scale);
    }
}
