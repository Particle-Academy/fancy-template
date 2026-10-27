<?php

declare(strict_types=1);

namespace FancyTemplate;

/**
 * Numbers to text, the same in every runtime.
 *
 * PHP's own conversions are three different answers: `(string) $float` follows
 * the `precision` ini (14 digits), `json_encode` follows `serialize_precision`
 * and writes `1.0e+21`, and `number_format` rounds the binary double. None of
 * them is the spec, and two of them depend on ini settings a host can change.
 * Everything here starts from the SHORTEST round-trip decimal digits of the
 * double, found by asking sprintf for 1..17 significant digits and keeping the
 * first that reads back as the same double, and then works on those digits as
 * a decimal string.
 */
final class Numbers
{
    /** Currency => [minor-unit exponent, prefix]. SPEC.md section 7. */
    public const CURRENCIES = [
        'USD' => [2, '$'], 'EUR' => [2, '€'], 'GBP' => [2, '£'], 'JPY' => [0, '¥'], 'INR' => [2, '₹'], 'KRW' => [0, '₩'],
        'CAD' => [2, null], 'AUD' => [2, null], 'NZD' => [2, null], 'CHF' => [2, null], 'CNY' => [2, null], 'BRL' => [2, null],
        'MXN' => [2, null], 'SEK' => [2, null], 'NOK' => [2, null], 'DKK' => [2, null], 'PLN' => [2, null], 'SGD' => [2, null],
        'HKD' => [2, null], 'ZAR' => [2, null], 'CLP' => [0, null], 'VND' => [0, null], 'ISK' => [0, null],
        'KWD' => [3, null], 'BHD' => [3, null], 'JOD' => [3, null], 'OMR' => [3, null], 'TND' => [3, null],
    ];

    /**
     * Shortest round-trip decimal of a finite number: [negative, digits, point].
     * The value is 0.DIGITS x 10^point, with no leading or trailing zeros in
     * DIGITS ("0" for zero).
     *
     * @return array{0: bool, 1: string, 2: int}
     */
    public static function shortest(int|float $value): array
    {
        if (is_int($value)) {
            $negative = $value < 0;
            $digits = ltrim($negative ? substr((string) $value, 1) : (string) $value, '0');
            if ($digits === '') {
                return [false, '0', 1];
            }
            $point = strlen($digits);

            return [$negative, rtrim($digits, '0'), $point];
        }

        if ($value == 0.0) {
            return [false, '0', 1];
        }

        $negative = $value < 0;
        $abs = abs($value);
        for ($precision = 1; $precision <= 17; $precision++) {
            $text = sprintf('%.'.($precision - 1).'e', $abs);
            if ((float) $text === $abs) {
                break;
            }
        }

        [$mantissa, $exponent] = explode('e', $text);
        $digits = rtrim(str_replace('.', '', $mantissa), '0');

        return [$negative, $digits === '' ? '0' : $digits, (int) $exponent + 1];
    }

    /** JavaScript's Number.prototype.toString, for a finite number. */
    public static function toJsString(int|float $value): string
    {
        [$negative, $digits, $point] = self::shortest($value);
        if ($digits === '0') {
            return '0';
        }

        $k = strlen($digits);
        $sign = $negative ? '-' : '';
        if ($k <= $point && $point <= 21) {
            return $sign.$digits.str_repeat('0', $point - $k);
        }
        if (0 < $point && $point <= 21) {
            return $sign.substr($digits, 0, $point).'.'.substr($digits, $point);
        }
        if (-6 < $point && $point <= 0) {
            return $sign.'0.'.str_repeat('0', -$point).$digits;
        }

        $e = $point - 1;
        $exp = 'e'.($e < 0 ? '-' : '+').abs($e);

        return $sign.($k === 1 ? $digits : $digits[0].'.'.substr($digits, 1)).$exp;
    }

    /** `number:decimals` -- decimal rounding, half away from zero, comma groups. */
    public static function fixed(int|float $value, int $decimals): string
    {
        [$negative, $digits, $point] = self::shortest($value);

        if ($point <= 0) {
            $int = '0';
            $frac = str_repeat('0', -$point).$digits;
        } elseif ($point >= strlen($digits)) {
            $int = $digits.str_repeat('0', $point - strlen($digits));
            $frac = '';
        } else {
            $int = substr($digits, 0, $point);
            $frac = substr($digits, $point);
        }
        if ($digits === '0') {
            $int = '0';
            $frac = '';
        }

        $frac = str_pad($frac, $decimals + 1, '0');
        $roundUp = $frac[$decimals] >= '5';
        $kept = $int.substr($frac, 0, $decimals);
        if ($roundUp) {
            $kept = self::increment($kept);
        }

        $intPart = substr($kept, 0, strlen($kept) - $decimals);
        $fracPart = substr($kept, strlen($kept) - $decimals);
        $intPart = ltrim($intPart, '0');
        if ($intPart === '') {
            $intPart = '0';
        }

        $isZero = trim($intPart.$fracPart, '0') === '';
        $out = self::group($intPart).($decimals > 0 ? '.'.$fracPart : '');

        return ($negative && ! $isZero ? '-' : '').$out;
    }

    /** `money:CUR` -- integer minor units, never through a float. */
    public static function money(int $minor, string $currency): string
    {
        [$exponent, $symbol] = self::CURRENCIES[$currency];
        $negative = $minor < 0;
        $digits = $negative ? substr((string) $minor, 1) : (string) $minor;
        $digits = str_pad($digits, $exponent + 1, '0', STR_PAD_LEFT);

        $int = substr($digits, 0, strlen($digits) - $exponent);
        $frac = $exponent > 0 ? '.'.substr($digits, -$exponent) : '';
        $prefix = $symbol ?? $currency.' ';

        return ($negative ? '-' : '').$prefix.self::group($int).$frac;
    }

    private static function increment(string $digits): string
    {
        $i = strlen($digits) - 1;
        while ($i >= 0) {
            if ($digits[$i] === '9') {
                $digits[$i] = '0';
                $i--;

                continue;
            }
            $digits[$i] = chr(ord($digits[$i]) + 1);

            return $digits;
        }

        return '1'.$digits;
    }

    private static function group(string $int): string
    {
        $out = '';
        $n = strlen($int);
        for ($i = 0; $i < $n; $i++) {
            if ($i > 0 && ($n - $i) % 3 === 0) {
                $out .= ',';
            }
            $out .= $int[$i];
        }

        return $out;
    }
}
