<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ProvisionAdmin extends Command
{
    protected $signature = 'hrm:admin
        {email : Email akun Super Admin}
        {--name= : Nama lengkap (wajib saat membuat akun baru)}';

    protected $description = 'Buat akun Super Admin awal, atau setel ulang password Super Admin yang terkunci. Password sementara ditampilkan sekali.';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        if (Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:190']])->fails()) {
            $this->error('Email tidak valid.');

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        // Perintah ini tidak boleh dipakai untuk mengambil alih akun peran lain.
        if ($user && $user->role !== Role::SuperAdmin) {
            $this->error('Email ini dipakai akun yang bukan Super Admin. Perintah ini tidak mengubah peran siapa pun.');

            return self::FAILURE;
        }

        $name = trim((string) $this->option('name'));

        if (! $user && $name === '') {
            $this->error('Akun baru membutuhkan --name="Nama Lengkap".');

            return self::FAILURE;
        }

        $created = $user === null;
        $password = Str::password(14, symbols: false);

        DB::transaction(function () use (&$user, $created, $email, $name, $password) {
            if ($created) {
                $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
                $user->forceFill(['role' => Role::SuperAdmin, 'is_active' => true])->save();
            } else {
                $user->forceFill(['password' => $password, 'is_active' => true])->save();
                $user->tokens()->delete();
                $this->revokeSessions($user);
            }

            Audit::record('admin.provisioned', $user, newValues: [
                'mode' => $created ? 'created' : 'password_reset',
            ]);
        });

        $this->info($created ? 'Akun Super Admin dibuat.' : 'Password Super Admin disetel ulang dan akun diaktifkan.');
        $this->line("Email: {$email}");
        $this->line("Password sementara: {$password}");
        $this->warn('Catat sekarang: password ini tidak akan ditampilkan lagi. Segera ganti setelah login.');

        return self::SUCCESS;
    }

    private function revokeSessions(User $user): void
    {
        $table = config('session.table', 'sessions');

        if (config('session.driver') === 'database' && Schema::hasTable($table)) {
            DB::table($table)->where('user_id', $user->id)->delete();
        }
    }
}
