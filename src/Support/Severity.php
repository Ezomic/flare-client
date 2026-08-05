<?php

declare(strict_types=1);

namespace Thijssensoftware\FlareClient\Support;

/**
 * Orders the PSR-3 levels so a threshold can be applied to a log record.
 *
 * Anything not in the list is refused rather than reported. A log channel is
 * the one source that can flood: a misbehaving loop writing a level nobody
 * recognises should not be the thing that fills the spool.
 */
final class Severity
{
    private const ORDER = [
        'debug' => 100,
        'info' => 200,
        'notice' => 250,
        'warning' => 300,
        'error' => 400,
        'critical' => 500,
        'alert' => 550,
        'emergency' => 600,
    ];

    public static function reaches(string $level, string $threshold): bool
    {
        $weight = self::ORDER[mb_strtolower($level)] ?? 0;
        $minimum = self::ORDER[mb_strtolower($threshold)] ?? self::ORDER['error'];

        return $weight >= $minimum;
    }
}
