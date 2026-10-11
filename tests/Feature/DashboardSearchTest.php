<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_does_not_expose_super_admin_or_inactive_users(): void
    {
        $user = User::factory()->create();
        User::factory()->create(['role' => 'super_admin']);
        User::factory()->create(['approval_status' => 'inactive']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->has('users', 1)
                ->where('users.0.id', $user->id));
    }

    public function test_dashboard_search_only_filters_schedule_tasks(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'approval_status' => 'approved',
        ]);

        $alphaProject = $this->createProject($user, 'Project Alpha');
        $betaProject = $this->createProject($user, 'Project Beta');

        $matchedTask = $this->createScheduledTask(
            $alphaProject,
            $user,
            'Prepare presentation',
        );

        $this->createScheduledTask(
            $betaProject,
            $user,
            'Review budget',
        );

        $today = now()->toDateString();

        $this->actingAs($user)
            ->get('/dashboard?search=alpha')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('filters.search', 'alpha')
                ->where('stats.total_tasks.value', 2)
                ->has("schedule.tasks_by_date.{$today}", 1)
                ->where(
                    "schedule.tasks_by_date.{$today}.0.id",
                    $matchedTask->id,
                )
            );
    }

    public function test_dashboard_search_can_find_task_by_assignee_name(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'approval_status' => 'approved',
        ]);

        $coworker = User::factory()->create([
            'name' => 'Nadia Specialist',
            'role' => 'user',
            'approval_status' => 'approved',
        ]);

        $project = $this->createProject($user, 'Internal Project');

        $matchedTask = $this->createScheduledTask(
            $project,
            $user,
            'Prepare documentation',
        );
        $matchedTask->assignees()->attach($coworker->id);

        $this->createScheduledTask(
            $project,
            $user,
            'Review documentation',
        );

        $today = now()->toDateString();

        $this->actingAs($user)
            ->get('/dashboard?search=nadia')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->has("schedule.tasks_by_date.{$today}", 1)
                ->where(
                    "schedule.tasks_by_date.{$today}.0.id",
                    $matchedTask->id,
                )
            );
    }

    private function createProject(User $user, string $name): Project
    {
        return Project::create([
            'created_by' => $user->id,
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
        ]);
    }

    private function createScheduledTask(
        Project $project,
        User $user,
        string $title,
    ): ProjectTask {
        $task = ProjectTask::create([
            'project_id' => $project->id,
            'created_by' => $user->id,
            'title' => $title,
            'status' => ProjectTask::STATUS_TO_DO,
            'start_date' => now()->toDateString(),
        ]);

        $task->assignees()->attach($user->id);

        return $task;
    }
}
