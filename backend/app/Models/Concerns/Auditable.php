<?php

namespace App\Models\Concerns;

use App\Support\Audit;
use Illuminate\Support\Arr;

trait Auditable
{
    public static function bootAuditable(): void
    {
        $name = strtolower(class_basename(static::class));

        static::created(fn($model) => Audit::record(
            "$name.created",
            $model,
            null,
            $model->getAttributes()
        ));

        static::updated(function ($model) use ($name) {
            $changes = Arr::except($model->getChanges(), ['updated_at']);

            if ($changes === []) {
                return;
            }

            Audit::record(
                "$name.updated",
                $model,
                Arr::only($model->getRawOriginal(), array_keys($changes)),
                $changes,
            );
        });

        static::deleted(fn($model) => Audit::record(
            "$name.deleted",
            $model,
            $model->getRawOriginal()
        ));
    }
}
