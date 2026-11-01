<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Audit
{
    /** Field yang nilainya tidak boleh pernah masuk log. */
    private const MASKED = ['password', 'remember_token', 'nik', 'phone'];

    public static function record(
        string $action,
        ?Model $subject = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?User $actor = null,
    ): AuditLog {
        $actor ??= Auth::user();
        $request = request();

        return AuditLog::create([
            'user_id' => $actor?->getKey(),
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'old_values' => $oldValues ? self::mask($oldValues) : null,
            'new_values' => $newValues ? self::mask($newValues) : null,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);
    }

    private static function mask(array $values): array
    {
        foreach (self::MASKED as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = '[disembunyikan]';
            }
        }

        return $values;
    }
}
