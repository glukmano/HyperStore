<?php

declare(strict_types=1);

namespace App\Core\Audit\Contracts;

interface AuditManagerInterface
{
    /**
     * Log an event.
     *
     * @param  string  $event  Human-readable event name
     * @param  array<string, mixed>  $properties  Arbitrary metadata
     * @param  mixed  $subject  The model being audited (Eloquent model or null)
     * @param  mixed  $causer  Who caused the event (User model or null)
     */
    public function log(
        string $event,
        array $properties = [],
        mixed $subject = null,
        mixed $causer = null,
    ): void;
}
