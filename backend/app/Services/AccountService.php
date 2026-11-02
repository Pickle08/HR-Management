<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Employee;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountService
{
    /** @return array{0: User, 1: string} akun dan password sementara (plaintext, hanya sekali). */
    public function create(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $role = Role::from($data['role']);
            $employee = null;

            if (! empty($data['employee_id'])) {
                $employee = Employee::whereKey($data['employee_id'])->lockForUpdate()->firstOrFail();

                if (! $employee->is_active) {
                    throw ValidationException::withMessages(['employee_id' => ['Karyawan tidak aktif.']]);
                }

                if ($employee->user_id !== null) {
                    throw ValidationException::withMessages(['employee_id' => ['Karyawan ini sudah punya akun.']]);
                }
            }

            $password = Str::password(14, symbols: false);

            $user = new User([
                'name' => $employee?->full_name ?? $data['name'],
                'email' => $data['email'],
                'password' => $password,
            ]);
            // role dan is_active diisi server, tidak lewat mass assignment.
            $user->forceFill(['role' => $role, 'is_active' => true]);

            try {
                $user->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['email' => ['Email sudah dipakai.']]);
            }

            $employee?->forceFill(['user_id' => $user->id])->save();

            return [$user, $password];
        });
    }

    public function changeRole(User $actor, User $target, Role $to): User
    {
        return DB::transaction(function () use ($actor, $target, $to) {
            $user = $this->lock($target);

            $this->assertNotSelf($actor, $user);

            if ($user->role === $to) {
                return $user;
            }

            if ($to !== Role::SuperAdmin && $user->employee === null) {
                throw ValidationException::withMessages([
                    'role' => ['Peran ini membutuhkan akun yang tertaut ke data karyawan.'],
                ]);
            }

            $this->assertNoActiveTeam($user);

            $user->forceFill(['role' => $to])->save();

            return $user;
        });
    }

    public function lockAccount(User $actor, User $target): User
    {
        return DB::transaction(function () use ($actor, $target) {
            $user = $this->lock($target);

            $this->assertNotSelf($actor, $user);
            $this->assertNoActiveTeam($user);

            $user->forceFill(['is_active' => false])->save();
            $this->revokeAccess($user);

            return $user;
        });
    }

    public function unlockAccount(User $target): User
    {
        return DB::transaction(function () use ($target) {
            $user = $this->lock($target);

            if ($user->employee && ! $user->employee->is_active) {
                abort(409, 'Karyawan masih nonaktif. Aktifkan karyawannya dulu.');
            }

            $user->forceFill(['is_active' => true])->save();

            return $user;
        });
    }

    /** @return array{0: User, 1: string} */
    public function resetPassword(User $actor, User $target): array
    {
        return DB::transaction(function () use ($actor, $target) {
            $user = $this->lock($target);

            $this->assertNotSelf($actor, $user);

            $password = Str::password(14, symbols: false);

            $user->forceFill(['password' => $password])->save();
            $this->revokeAccess($user);

            Audit::record('account.password_reset', $user);

            return [$user, $password];
        });
    }

    private function lock(User $target): User
    {
        return User::whereKey($target->id)->lockForUpdate()->firstOrFail();
    }

    private function assertNotSelf(User $actor, User $user): void
    {
        if ($actor->is($user)) {
            abort(422, 'Tidak bisa mengubah akun sendiri lewat menu ini.');
        }
    }

    // Menyingkirkan atasan yang masih punya bawahan aktif akan membuat tim itu
    // diam-diam tak terlihat oleh siapa pun. Bawahan harus dipindah dulu.
    private function assertNoActiveTeam(User $user): void
    {
        if ($user->role !== Role::Atasan || $user->employee === null) {
            return;
        }

        if ($user->employee->subordinates()->where('is_active', true)->exists()) {
            abort(409, 'Atasan ini masih punya bawahan aktif. Pindahkan bawahannya dulu.');
        }
    }

    private function revokeAccess(User $user): void
    {
        $user->tokens()->delete();

        $table = config('session.table', 'sessions');

        if (config('session.driver') === 'database' && Schema::hasTable($table)) {
            DB::table($table)->where('user_id', $user->id)->delete();
        }
    }
}
