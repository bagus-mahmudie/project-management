<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\DailyReportItem;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DailyReportSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_report_search_filters_visible_items(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'approval_status' => 'approved',
        ]);

        $report = $this->createDailyReport([
            'user_id' => $user->id,
            'report_date' => '2026-08-13',
        ]);

        $matchedItem = $report->items()->create([
            'source' => 'manual',
            'title' => 'Prepare monthly invoice',
            'snapshot_status' => 'in_progress',
            'position' => 1,
        ]);

        $report->items()->create([
            'source' => 'manual',
            'title' => 'Internal team meeting',
            'snapshot_status' => 'to_do',
            'position' => 2,
        ]);

        $this->actingAs($user)
            ->get('/daily-report?date=2026-08-13&search=invoice')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('DailyReport/Index')
                ->where('filters.search', 'invoice')
                ->has('reports', 1)
                ->has('reports.0.items', 1)
                ->where('reports.0.items.0.id', $matchedItem->id)
            );
    }

    public function test_daily_report_search_keeps_parent_of_matching_subtask(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'approval_status' => 'approved',
        ]);

        $report = $this->createDailyReport([
            'user_id' => $user->id,
            'report_date' => '2026-08-13',
        ]);

        $parent = $report->items()->create([
            'source' => 'manual',
            'title' => 'Contact clients',
            'snapshot_status' => 'in_progress',
            'position' => 1,
        ]);

        $matchedSubtask = DailyReportItem::create([
            'daily_report_id' => $report->id,
            'parent_id' => $parent->id,
            'source' => 'manual',
            'title' => 'Follow up Client Alpha',
            'snapshot_status' => 'to_do',
            'position' => 1,
            'is_subtask' => true,
        ]);

        DailyReportItem::create([
            'daily_report_id' => $report->id,
            'parent_id' => $parent->id,
            'source' => 'manual',
            'title' => 'Follow up Client Beta',
            'snapshot_status' => 'to_do',
            'position' => 2,
            'is_subtask' => true,
        ]);

        $this->actingAs($user)
            ->get('/daily-report?date=2026-08-13&search=alpha')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('DailyReport/Index')
                ->has('reports', 1)
                ->has('reports.0.items', 1)
                ->where('reports.0.items.0.id', $parent->id)
                ->has('reports.0.items.0.subtasks', 1)
                ->where(
                    'reports.0.items.0.subtasks.0.id',
                    $matchedSubtask->id,
                )
            );
    }

    public function test_daily_report_loads_project_files_and_links_from_parent_subtasks(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'approval_status' => 'approved',
        ]);
        $project = Project::create([
            'created_by' => $user->id,
            'name' => 'Synced Project',
            'slug' => 'synced-project',
        ]);
        $project->members()->attach($user->id, ['role' => 'owner']);
        $parent = ProjectTask::create([
            'project_id' => $project->id,
            'created_by' => $user->id,
            'title' => 'Parent task',
            'status' => ProjectTask::STATUS_IN_PROGRESS,
        ]);
        $firstSubtask = ProjectTask::create([
            'project_id' => $project->id,
            'parent_id' => $parent->id,
            'created_by' => $user->id,
            'title' => 'First subtask',
            'status' => ProjectTask::STATUS_IN_PROGRESS,
        ]);
        $secondSubtask = ProjectTask::create([
            'project_id' => $project->id,
            'parent_id' => $parent->id,
            'created_by' => $user->id,
            'title' => 'Second subtask',
            'status' => ProjectTask::STATUS_IN_PROGRESS,
        ]);
        $firstSubtask->files()->create([
            'uploaded_by' => $user->id,
            'name' => 'first.png',
            'path' => 'task-files/first.png',
            'disk' => 'local',
            'mime_type' => 'image/png',
            'size' => 100,
        ]);
        $secondSubtask->files()->create([
            'uploaded_by' => $user->id,
            'name' => 'second.pdf',
            'path' => 'task-files/second.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'size' => 200,
        ]);
        $firstSubtask->links()->create([
            'created_by' => $user->id,
            'url' => 'https://first.example.com',
        ]);
        $secondSubtask->links()->create([
            'created_by' => $user->id,
            'url' => 'https://second.example.com',
        ]);

        $report = $this->createDailyReport([
            'user_id' => $user->id,
            'report_date' => '2026-08-13',
        ]);
        $report->items()->create([
            'project_task_id' => $parent->id,
            'source' => 'project',
            'title' => $parent->title,
            'snapshot_status' => ProjectTask::STATUS_IN_PROGRESS,
            'position' => 1,
        ]);

        $this->actingAs($user)
            ->get('/daily-report?date=2026-08-13')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('reports.0.items.0.task.subtasks', 2)
                ->where(
                    'reports.0.items.0.task.subtasks.0.files.0.name',
                    'first.png',
                )
                ->where(
                    'reports.0.items.0.task.subtasks.0.links.0.url',
                    'https://first.example.com',
                )
                ->where(
                    'reports.0.items.0.task.subtasks.1.files.0.name',
                    'second.pdf',
                )
                ->where(
                    'reports.0.items.0.task.subtasks.1.links.0.url',
                    'https://second.example.com',
                )
            );
    }

    private function createDailyReport(array $attributes): DailyReport
    {
        $id = DB::table('daily_reports')->insertGetId([
            ...$attributes,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DailyReport::findOrFail($id);
    }
}
