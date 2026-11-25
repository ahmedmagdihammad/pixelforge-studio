<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * All money in this app is an integer number of nanodollars (1 USD = 1,000,000,000 nanodollars).
 *
 * Pixelforge sends money as exact decimal strings with up to nine decimal places, so
 * nanodollars hold every amount exactly. No floats are used anywhere money is calculated.
 */
final class Money
{
    public const NANOS_PER_DOLLAR = 1_000_000_000;

    /**
     * "0.046125000" -> 46125000. Rejects anything that isn't a plain decimal string.
     */
    public static function fromDecimal(string $amount): int
    {
        if (! preg_match('/^(-)?(\d+)(?:\.(\d{1,9}))?$/', trim($amount), $m)) {
            throw new InvalidArgumentException("Not an exact dollar amount: [{$amount}]");
        }

        $nanos = (int) $m[2] * self::NANOS_PER_DOLLAR + (int) str_pad($m[3] ?? '', 9, '0');

        return $m[1] === '-' ? -$nanos : $nanos;
    }

    public static function fromDollars(int $dollars): int
    {
        return $dollars * self::NANOS_PER_DOLLAR;
    }

    /**
     * 46125000 -> "0.046125000" (the same format Pixelforge uses).
     */
    public static function toDecimal(int $nanos): string
    {
        $sign = $nanos < 0 ? '-' : '';
        $nanos = abs($nanos);

        return $sign.intdiv($nanos, self::NANOS_PER_DOLLAR).'.'.str_pad((string) ($nanos % self::NANOS_PER_DOLLAR), 9, '0', STR_PAD_LEFT);
    }

    /**
     * 46125000 -> "$0.046125". Shows every significant digit (never rounds), with at least two decimals.
     */
    public static function format(int $nanos): string
    {
        [$whole, $fraction] = explode('.', self::toDecimal($nanos));
        $fraction = str_pad(rtrim($fraction, '0'), 2, '0');
        $sign = str_starts_with($whole, '-') ? '-' : '';

        return $sign.'$'.number_format((int) ltrim($whole, '-')).'.'.$fraction;
    }
}
