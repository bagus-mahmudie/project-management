<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EmployeeManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_update_employee_account_and_profile(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        $employee = User::factory()->create([
            'role' => 'user',
            'email' => 'old@example.com',
        ]);
        $employee->profile()->create([
            'position' => 'Designer',
            'phone' => '08123456789',
            'bio' => 'Existing bio',
        ]);

        $this->actingAs($superAdmin)
            ->patch(route('employees.update', $employee), [
                'name' => 'Updated Employee',
                'email' => 'updated@example.com',
                'phone' => '08987654321',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', [
            'id' => $employee->id,
            'name' => 'Updated Employee',
            'email' => 'updated@example.com',
        ]);
        $this->assertDatabaseHas('user_profiles', [
            'user_id' => $employee->id,
            'position' => 'Designer',
            'phone' => '08987654321',
            'bio' => 'Existing bio',
        ]);
    }

    public function test_regular_user_cannot_update_or_delete_employee(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $employee = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)
            ->patch(route('employees.update', $employee), [
                'name' => 'Unauthorized Update',
                'email' => 'unauthorized@example.com',
                'phone' => null,
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->delete(route('employees.destroy', $employee))
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $employee->id]);
    }

    public function test_regular_user_can_update_their_own_employee_card_but_cannot_delete_it(): void
    {
        $employee = User::factory()->create([
            'role' => 'user',
            'email' => 'employee@example.com',
        ]);
        $employee->profile()->create([
            'position' => 'Developer',
            'phone' => '08123456789',
        ]);

        $this->actingAs($employee)
            ->get(route('employees.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('employees.0.id', $employee->id)
                ->where('employees.0.can.update', true)
                ->where('employees.0.can.delete', false)
            );

        $this->actingAs($employee)
            ->patch(route('employees.update', $employee), [
                'name' => 'Self Updated Employee',
                'email' => 'self-updated@example.com',
                'phone' => '08987654321',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', [
            'id' => $employee->id,
            'name' => 'Self Updated Employee',
            'email' => 'self-updated@example.com',
        ]);
        $this->assertDatabaseHas('user_profiles', [
            'user_id' => $employee->id,
            'position' => 'Developer',
            'phone' => '08987654321',
        ]);

        $this->actingAs($employee)
            ->delete(route('employees.destroy', $employee))
            ->assertForbidden();

        $this->assertDatabaseHas('users', [
            'id' => $employee->id,
            'approval_status' => 'approved',
        ]);
    }

    public function test_super_admin_can_deactivate_employee_but_not_another_super_admin(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        $otherSuperAdmin = User::factory()->create(['role' => 'super_admin']);
        $employee = User::factory()->create(['role' => 'user']);

        $this->actingAs($superAdmin)
            ->delete(route('employees.destroy', $employee))
            ->assertRedirect();

        $this->assertDatabaseHas('users', [
            'id' => $employee->id,
            'approval_status' => 'inactive',
        ]);

        $this->actingAs($superAdmin)
            ->delete(route('employees.destroy', $otherSuperAdmin))
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $otherSuperAdmin->id]);
    }
}
