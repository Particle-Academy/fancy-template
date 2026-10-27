<?php

declare(strict_types=1);

namespace FancyTemplate;

/**
 * The `date` type and the `date:"pattern"` filter (SPEC.md sections 3 and 7).
 *
 * English names only, no time-zone database, no Intl: a certificate's date is
 * the date the issuer wrote, and an offset is carried but never applied.
 */
final class Dates
{
    private const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    private const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    /** Pattern letter => allowed run lengths. */
    private const RUNS = ['y' => [2, 4], 'M' => [1, 2, 3, 4], 'd' => [1, 2], 'E' => [3, 4], 'H' => [1, 2], 'h' => [1, 2], 'm' => [2], 's' => [2], 'a' => [1]];

    /**
     * Parse an ISO date or date-time. Null when it is not one, or not a real
     * calendar date and time. Data, not template text, and anchored without
     * nested quantifiers, so the regex is linear.
     *
     * @return array{y: int, m: int, d: int, H: int, i: int, s: int}|null
     */
    public static function parse(mixed $value): ?array
    {
        if (! is_string($value)) {
            return null;
        }
        $ok = preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?(Z|[+-](\d{2}):(\d{2}))?)?$/D', $value, $m);
        if ($ok !== 1) {
            return null;
        }
        [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        $hour = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 0;
        $minute = isset($m[5]) && $m[5] !== '' ? (int) $m[5] : 0;
        $second = isset($m[6]) && $m[6] !== '' ? (int) $m[6] : 0;

        if ($mo < 1 || $mo > 12 || $d < 1 || $d > self::daysIn($y, $mo)) {
            return null;
        }
        if ($hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }
        if (isset($m[8]) && $m[8] !== '' && ((int) $m[8] > 23 || (int) $m[9] > 59)) {
            return null;
        }

        return ['y' => $y, 'm' => $mo, 'd' => $d, 'H' => $hour, 'i' => $minute, 's' => $second];
    }

    /**
     * Compile a pattern to a list of [kind, value] pieces, or null if invalid.
     * A forward-only scan; quotes are literal text, '' is one quote.
     *
     * @return list<array{0: string, 1: string|int}>|null
     */
    public static function compile(string $pattern): ?array
    {
        $out = [];
        $n = strlen($pattern);
        $i = 0;
        while ($i < $n) {
            $c = $pattern[$i];
            if ($c === "'") {
                if ($i + 1 < $n && $pattern[$i + 1] === "'") {
                    $out[] = ['lit', "'"];
                    $i += 2;

                    continue;
                }
                $i++;
                $lit = '';
                for (;;) {
                    if ($i >= $n) {
                        return null;
                    }
                    if ($pattern[$i] === "'") {
                        if ($i + 1 < $n && $pattern[$i + 1] === "'") {
                            $lit .= "'";
                            $i += 2;

                            continue;
                        }
                        $i++;
                        break;
                    }
                    $lit .= $pattern[$i++];
                }
                $out[] = ['lit', $lit];

                continue;
            }
            if (ctype_alpha($c)) {
                $run = 1;
                while ($i + $run < $n && $pattern[$i + $run] === $c) {
                    $run++;
                }
                if (! isset(self::RUNS[$c]) || ! in_array($run, self::RUNS[$c], true)) {
                    return null;
                }
                $out[] = [$c, $run];
                $i += $run;

                continue;
            }
            $out[] = ['lit', $c];
            $i++;
        }

        return $out;
    }

    /**
     * @param  array{y: int, m: int, d: int, H: int, i: int, s: int}  $date
     * @param  list<array{0: string, 1: string|int}>  $pieces
     */
    public static function format(array $date, array $pieces): string
    {
        $out = '';
        foreach ($pieces as [$kind, $value]) {
            $out .= match ($kind) {
                'lit' => $value,
                'y' => $value === 4 ? sprintf('%04d', $date['y']) : sprintf('%02d', $date['y'] % 100),
                'M' => match ($value) {
                    4 => self::MONTHS[$date['m'] - 1],
                    3 => substr(self::MONTHS[$date['m'] - 1], 0, 3),
                    2 => sprintf('%02d', $date['m']),
                    default => (string) $date['m'],
                },
                'd' => $value === 2 ? sprintf('%02d', $date['d']) : (string) $date['d'],
                'E' => $value === 4 ? self::DAYS[self::weekday($date)] : substr(self::DAYS[self::weekday($date)], 0, 3),
                'H' => $value === 2 ? sprintf('%02d', $date['H']) : (string) $date['H'],
                'h' => $value === 2 ? sprintf('%02d', self::twelve($date['H'])) : (string) self::twelve($date['H']),
                'm' => sprintf('%02d', $date['i']),
                's' => sprintf('%02d', $date['s']),
                'a' => $date['H'] < 12 ? 'AM' : 'PM',
            };
        }

        return $out;
    }

    private static function twelve(int $hour): int
    {
        $h = $hour % 12;

        return $h === 0 ? 12 : $h;
    }

    /** 0 = Monday, from days since the civil epoch (Howard Hinnant's algorithm). */
    private static function weekday(array $date): int
    {
        $y = $date['y'] - ($date['m'] <= 2 ? 1 : 0);
        $era = intdiv($y >= 0 ? $y : $y - 399, 400);
        $yoe = $y - $era * 400;
        $mp = ($date['m'] + 9) % 12;
        $doy = intdiv(153 * $mp + 2, 5) + $date['d'] - 1;
        $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;
        $days = $era * 146097 + $doe - 719468;

        // 1970-01-01 was a Thursday (3).
        return (($days % 7) + 7 + 3) % 7;
    }

    private static function daysIn(int $y, int $m): int
    {
        return match ($m) {
            2 => ($y % 4 === 0 && ($y % 100 !== 0 || $y % 400 === 0)) ? 29 : 28,
            4, 6, 9, 11 => 30,
            default => 31,
        };
    }
}
