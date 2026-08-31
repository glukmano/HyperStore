<?php

declare(strict_types=1);

namespace App\Core\Audit;

use App\Core\Audit\Contracts\AuditManagerInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * AuditManager: Thin wrapper around Spatie Activitylog.
 *
 * Provides a stable contract so that application code never depends
 * directly on Spatie's helper functions — keeping the audit backend swappable.
 */
final class AuditManager implements AuditManagerInterface
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function log(
        string $event,
        array $properties = [],
        mixed $subject = null,
        mixed $causer = null,
    ): void {
        $activity = activity()
            ->withProperties($properties)
            ->event($event);

        if ($subject instanceof Model) {
            $activity = $activity->performedOn($subject);
        }

        if ($causer instanceof Model) {
            $activity = $activity->causedBy($causer);
        }

        $activity->log($event);
    }
}
