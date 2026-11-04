<?php

namespace App\Support;

class ProbeGuard
{
    /** Perintah uji konkurensi hanya boleh menyentuh database MySQL sekali pakai berakhiran _probe. */
    public static function allows(string $driver, string $database): bool
    {
        return $driver === 'mysql'
            && str_ends_with($database, '_probe')
            && ! app()->isProduction();
    }
}
