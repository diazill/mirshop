<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class AssignUserRole extends Command
{
    protected $signature = 'users:assign-role {email} {role}';

    protected $description = 'Set the role for an existing user account';

    public function handle(): int
    {
        $role = $this->argument('role');
        $allowedRoles = ['owner', 'admin', 'cashier', 'warehouse'];

        if (! in_array($role, $allowedRoles, true)) {
            $this->error('Role harus salah satu dari: ' . implode(', ', $allowedRoles));

            return self::FAILURE;
        }

        $user = User::query()->where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('Pengguna dengan email tersebut tidak ditemukan.');

            return self::FAILURE;
        }

        $user->role = $role;
        $user->save();

        $this->info("Role pengguna {$user->email} berhasil diubah menjadi {$role}.");

        return self::SUCCESS;
    }
}
