<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EmployeeSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_search_filters_by_visible_employee_data(): void
    {
        $viewer = User::factory()->create([
            'role' => 'admin',
            'approval_status' => 'approved',
        ]);

        $matchedEmployee = User::factory()->create([
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
            'role' => 'user',
        ]);
        $matchedEmployee->profile()->create([
            'position' => 'Frontend Developer',
            'phone' => '081234567890',
        ]);

        User::factory()->create([
            'name' => 'Siti Aminah',
            'email' => 'siti@example.com',
            'role' => 'user',
        ])->profile()->create([
            'position' => 'Accountant',
            'phone' => '089876543210',
        ]);

        $this->actingAs($viewer)
            ->get('/employees?search=frontend')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Employee/Index')
                ->has('employees', 1)
                ->where('employees.0.id', $matchedEmployee->id)
            );
    }

    public function test_employee_search_does_not_include_super_admin(): void
    {
        $viewer = User::factory()->create([
            'role' => 'admin',
            'approval_status' => 'approved',
        ]);

        User::factory()->create([
            'name' => 'Hidden Employee',
            'role' => 'super_admin',
        ]);

        $this->actingAs($viewer)
            ->get('/employees?search=hidden')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Employee/Index')
                ->has('employees', 0)
            );
    }
}
