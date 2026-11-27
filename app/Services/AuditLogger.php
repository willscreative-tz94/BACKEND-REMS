<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/** Who did what, to which record, in which agency. Admin > Audit log. */
class AuditLogger
{
    public static function log(string $action, ?Model $subject, string $description, array $properties = []): void
    {
        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'description' => $description,
            'properties' => $properties ?: null,
            'ip_address' => request()?->ip(),
        ]);
    }
}
