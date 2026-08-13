<?php

namespace App\Domain\Audit\Services;

use App\Domain\Audit\DTOs\RequestAuditContext;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ActivityLogger
{
    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function record(
        string $event,
        ?User $actor,
        ?Model $subject,
        RequestAuditContext $context,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): ActivityLog {
        return ActivityLog::query()->create([
            'actor_id' => $actor?->getKey(),
            'event' => $event,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $context->ipAddress,
            'user_agent' => $context->userAgent,
            'occurred_at' => now(),
        ]);
    }
}
