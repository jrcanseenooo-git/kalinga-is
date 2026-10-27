<?php

namespace App\Support;

/**
 * Today's date in the Philippines. The application clock runs on UTC, so a
 * plain "today" in a validation rule is still yesterday between midnight and
 * 8 AM Philippine time and rejected dates the case manager correctly entered
 * as today. Use this in date rules instead of "today".
 */
final class PhilippineDate
{
    public const ZONE = 'Asia/Manila';

    public static function today(): string
    {
        return now(self::ZONE)->toDateString();
    }
}
