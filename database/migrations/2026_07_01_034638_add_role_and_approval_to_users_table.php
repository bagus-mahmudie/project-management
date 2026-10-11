<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('user')->after('password');
            $table->string('approval_status')->default('pending')->after('role');
            $table->timestamp('approved_at')->nullable()->after('approval_status');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
        });

        $admin = User::firstOrCreate(
            ['email' => 'admin@example.test'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make(bin2hex(random_bytes(24))),
                'role' => 'super_admin',
                'approval_status' => 'approved',
                'email_verified_at' => now(),
                'approved_at' => now(),
            ]
        );

        if (! $admin->profile()->exists()) {
            $admin->profile()->create([
                'phone' => '',
                'position' => 'Super Admin',
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropColumn([
                'role',
                'approval_status',
                'approved_at',
                'approved_by',
            ]);
        });
    }
};
