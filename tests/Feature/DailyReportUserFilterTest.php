<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DailyReportUserFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_report_user_dropdown_excludes_super_admin(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'approval_status' => 'approved',
        ]);

        $employee = User::factory()->create([
            'role' => 'user',
            'approval_status' => 'approved',
        ]);

        User::factory()->create([
            'role' => 'super_admin',
            'approval_status' => 'approved',
        ]);

        $this->actingAs($admin)
            ->get('/daily-report?date=2099-01-01')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('DailyReport/Index')
                ->where('users', fn ($users) => $users->pluck('id')->contains($employee->id) &&
                    $users->pluck('role')->doesntContain('super_admin'))
            );
    }
}
