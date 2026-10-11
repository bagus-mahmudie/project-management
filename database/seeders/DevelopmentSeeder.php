<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DevelopmentSeeder extends Seeder
{
    public function run(): void
    {
        abort_unless(app()->environment(['local', 'testing']), 403, 'DevelopmentSeeder hanya boleh dijalankan di local/testing.');

        User::query()->updateOrCreate(
            ['email' => env('DEV_SUPER_ADMIN_EMAIL', 'user-86509ac5@example.test')],
            [
                'name' => env('DEV_SUPER_ADMIN_NAME', 'Super Admin'),
                'password' => Hash::make(env('DEV_SUPER_ADMIN_PASSWORD', bin2hex(random_bytes(24)))),
                'role' => 'super_admin',
                'approval_status' => 'approved',
                'approved_at' => now(),
            ],
        );

        User::query()->updateOrCreate(
            ['email' => env('DEV_USER_EMAIL', 'user-d0901b1a@example.test')],
            [
                'name' => env('DEV_USER_NAME', 'Development User'),
                'password' => Hash::make(env('DEV_USER_PASSWORD', bin2hex(random_bytes(24)))),
                'role' => 'user',
                'approval_status' => 'approved',
                'approved_at' => now(),
            ],
        );
    }
}
