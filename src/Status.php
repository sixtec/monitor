<?php

namespace Sixtec\Monitor;

/**
 * Status of a probe. The values are the ones the monitor panel reads (the same
 * vocabulary as a Laravel /health endpoint).
 */
enum Status: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Critical = 'critical';
    case Skipped = 'skipped';
    case Unknown = 'unknown';

    /** Weight used to find the worst status of a report. */
    public function severity(): int
    {
        return match ($this) {
            self::Critical => 3,
            self::Warning => 2,
            self::Unknown => 1,
            self::Ok, self::Skipped => 0,
        };
    }
}
