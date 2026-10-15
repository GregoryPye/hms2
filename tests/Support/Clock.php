<?php

namespace Tests\Support;

use Carbon\Carbon;
use Carbon\CarbonImmutable;

final class Clock
{
    /** Controls PHP only; SQL UTC_TIMESTAMP() needs bounded relative fixtures in SQL suites. */
    public static function during(string $instant, callable $work): mixed
    {
        $mutable = Carbon::getTestNow();
        $immutable = CarbonImmutable::getTestNow();
        $timezone = date_default_timezone_get();
        try {
            date_default_timezone_set('UTC');
            Carbon::setTestNow(Carbon::parse($instant));
            CarbonImmutable::setTestNow(CarbonImmutable::parse($instant));

            return $work();
        } finally {
            Carbon::setTestNow($mutable);
            CarbonImmutable::setTestNow($immutable);
            date_default_timezone_set($timezone);
        }
    }
}
