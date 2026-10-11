<?php

namespace Tests\Feature;

use App\Models\DailyReport;
use App\Models\DailyReportItem;
use App\Models\DailyReportItemFile;
use App\Models\Event;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\ProjectTask;
use App\Models\User;
use App\Services\DailyReportCatchUpService;
use App\Services\DailyReportSyncService;
use App\Support\MediaUploadRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProjectEfficiencyAndSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_disguised_svg_is_still_sanitized(): void
    {
        Storage::fake('local');
        [$owner, $project, $group, $task] = $this->projectContext();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(1)</script></svg>';

        $this->actingAs($owner)
            ->post(route('project-tasks.files.store', $task), [
                'files' => [UploadedFile::fake()->createWithContent('image.png', $svg)],
            ])
            ->assertRedirect();

        $file = $task->files()->firstOrFail();
        $stored = Storage::disk('local')->get($file->path);

        $this->assertStringEndsWith('.svg', $file->path);
        $this->assertSame('image/svg+xml', $file->mime_type);
        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('onload=', $stored);
    }

    public function test_media_upload_is_limited_to_100_mb_per_file_and_total(): void
    {
        $valid = UploadedFile::fake()->create('video.mp4', 102400, 'video/mp4');
        $tooLarge = UploadedFile::fake()->create('video.mp4', 102401, 'video/mp4');
        $first = UploadedFile::fake()->create('first.mp4', 60000, 'video/mp4');
        $second = UploadedFile::fake()->create('second.mp4', 60000, 'video/mp4');

        $this->assertTrue(Validator::make(
            ['files' => [$valid]],
            ['files' => MediaUploadRules::files(), 'files.*' => MediaUploadRules::file()],
        )->passes());
        $this->assertFalse(Validator::make(
            ['files' => [$tooLarge]],
            ['files' => MediaUploadRules::files(), 'files.*' => MediaUploadRules::file()],
        )->passes());
        $this->assertFalse(Validator::make(
            ['files' => [$first, $second]],
            ['files' => MediaUploadRules::files(), 'files.*' => MediaUploadRules::file()],
        )->passes());
    }

    public function test_all_employees_can_read_daily_report_files_but_only_owner_can_write(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $employee = User::factory()->create();
        $report = DailyReport::create([
            'user_id' => $owner->id,
            'report_date' => '2026-08-14',
        ]);
        $item = DailyReportItem::create([
            'daily_report_id' => $report->id,
            'source' => 'manual',
            'title' => 'Manual task',
        ]);
        Storage::disk('local')->put('daily-report-evidence/sample.png', 'content');
        $file = DailyReportItemFile::create([
            'daily_report_item_id' => $item->id,
            'uploaded_by' => $owner->id,
            'name' => 'sample.png',
            'path' => 'daily-report-evidence/sample.png',
            'disk' => 'local',
            'mime_type' => 'image/png',
            'size' => 7,
        ]);

        $this->actingAs($employee)
            ->get(route('daily-report.items.files.preview', $file))
            ->assertOk();
        $this->actingAs($employee)
            ->get(route('daily-report.items.files.download', $file))
            ->assertOk();
        $this->actingAs($employee)
            ->delete(route('daily-report.items.files.destroy', $file))
            ->assertForbidden();
    }

    public function test_project_comments_survive_when_automatic_report_item_is_removed(): void
    {
        Carbon::setTestNow('2026-08-14 10:00:00');
        [$owner, $project, $group, $task] = $this->projectContext();
        $task->update(['status' => ProjectTask::STATUS_IN_PROGRESS]);
        $task->statusHistories()->create([
            'changed_by' => $owner->id,
            'from_status' => ProjectTask::STATUS_TO_DO,
            'to_status' => ProjectTask::STATUS_IN_PROGRESS,
            'changed_at' => now(),
        ]);
        app(DailyReportSyncService::class)->syncDateForUser('2026-08-14', $owner->id);
        $item = DailyReportItem::query()->where('project_task_id', $task->id)->firstOrFail();
        $comment = $item->comments()->create([
            'project_task_id' => $task->id,
            'user_id' => $owner->id,
            'body' => 'Komentar harus tetap ada',
        ]);

        $task->assignees()->detach($owner->id);
        app(DailyReportSyncService::class)->syncDateForUser('2026-08-14', $owner->id);

        $this->assertDatabaseMissing('daily_report_items', ['id' => $item->id]);
        $this->assertDatabaseHas('task_comments', [
            'id' => $comment->id,
            'project_task_id' => $task->id,
            'daily_report_item_id' => null,
        ]);
    }

    public function test_event_can_only_be_edited_by_creator_assignee_or_super_admin(): void
    {
        [$owner, $project] = $this->projectContext();
        $assignee = User::factory()->create();
        $member = User::factory()->create();
        $project->members()->attach([
            $assignee->id => ['role' => 'member'],
            $member->id => ['role' => 'member'],
        ]);
        $event = Event::create([
            'project_id' => $project->id,
            'created_by' => $owner->id,
            'title' => 'Internal event',
            'event_date' => '2026-08-14',
            'start_time' => '09:00',
            'end_time' => '10:00',
        ]);
        $event->assignees()->attach($assignee->id);
        $payload = [
            'project_id' => $project->id,
            'title' => 'Updated event',
            'event_date' => '2026-08-14',
            'start_time' => '09:00',
            'end_time' => '10:00',
            'assignee_ids' => [$assignee->id],
        ];

        $this->actingAs($member)->patch(route('events.update', $event), $payload)->assertForbidden();
        $this->actingAs($assignee)->patch(route('events.update', $event), $payload)->assertRedirect();
    }

    public function test_daily_report_catch_up_is_idempotent(): void
    {
        Carbon::setTestNow('2026-08-16 10:00:00');
        [$owner, $project, $group, $task] = $this->projectContext();
        $task->update(['status' => ProjectTask::STATUS_IN_PROGRESS]);
        $task->statusHistories()->create([
            'changed_by' => $owner->id,
            'from_status' => ProjectTask::STATUS_TO_DO,
            'to_status' => ProjectTask::STATUS_IN_PROGRESS,
            'changed_at' => '2026-08-13 09:00:00',
        ]);
        $service = app(DailyReportCatchUpService::class);

        $service->queueUserThrough('2026-08-14', $owner->id);
        $service->queueUserThrough('2026-08-14', $owner->id);
        $service->queueUserThrough('2026-08-16', $owner->id);

        $this->assertSame(3, DailyReport::query()->where('user_id', $owner->id)->count());
        $this->assertDatabaseHas('daily_report_sync_cursors', [
            'user_id' => $owner->id,
            'last_synced_date' => '2026-08-16',
        ]);
    }

    public function test_upload_quota_is_enforced_across_task_and_daily_report_files(): void
    {
        Storage::fake('local');
        config(['media.user_storage_quota_mb' => 1]);
        [$owner, $project, $group, $task] = $this->projectContext();
        $task->files()->create([
            'uploaded_by' => $owner->id,
            'name' => 'existing.mp4',
            'path' => 'task-files/existing.mp4',
            'disk' => 'local',
            'mime_type' => 'video/mp4',
            'size' => 900 * 1024,
        ]);

        $this->actingAs($owner)
            ->post(route('project-tasks.files.store', $task), [
                'files' => [UploadedFile::fake()->create('next.mp4', 200, 'video/mp4')],
            ])
            ->assertSessionHasErrors('files');

        $this->assertSame(1, $task->files()->count());
    }

    public function test_opening_daily_report_catches_up_selected_user_through_today(): void
    {
        Carbon::setTestNow('2026-08-18 10:00:00');
        $user = User::factory()->create();
        DailyReport::create([
            'user_id' => $user->id,
            'report_date' => '2026-08-14',
        ]);

        $this->actingAs($user)
            ->get(route('daily-report.index', [
                'filter' => 'month',
                'date' => '2026-08-18',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('reports', 3)
                ->where('reports.0.report_date', '2026-08-18')
                ->where('reports.1.report_date', '2026-08-17')
                ->where('reports.2.report_date', '2026-08-16'));

        $this->assertSame(
            ['2026-08-14', '2026-08-15', '2026-08-16', '2026-08-17', '2026-08-18'],
            DailyReport::query()
                ->where('user_id', $user->id)
                ->orderBy('report_date')
                ->pluck('report_date')
                ->map(fn ($date) => $date->format('Y-m-d'))
                ->all(),
        );
    }

    public function test_manual_daily_report_recalculates_overdue_after_due_date_or_status_edit(): void
    {
        $user = User::factory()->create();
        $report = DailyReport::create([
            'user_id' => $user->id,
            'report_date' => '2026-08-14',
        ]);
        $item = DailyReportItem::create([
            'daily_report_id' => $report->id,
            'source' => 'manual',
            'title' => 'Manual overdue task',
            'snapshot_status' => ProjectTask::STATUS_TO_DO,
            'is_overdue' => false,
        ]);

        $this->actingAs($user)
            ->patch(route('daily-report.items.update', $item), [
                'snapshot_due_date' => '2026-08-13',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('daily_report_items', [
            'id' => $item->id,
            'snapshot_due_date' => '2026-08-13',
            'is_overdue' => true,
        ]);

        $this->actingAs($user)
            ->patch(route('daily-report.items.update', $item), [
                'snapshot_status' => ProjectTask::STATUS_DONE,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('daily_report_items', [
            'id' => $item->id,
            'snapshot_status' => ProjectTask::STATUS_DONE,
            'is_overdue' => false,
        ]);
    }

    public function test_daily_report_loads_three_dates_per_backend_page(): void
    {
        $user = User::factory()->create();

        foreach (range(8, 14) as $day) {
            DailyReport::create([
                'user_id' => $user->id,
                'report_date' => sprintf('2026-08-%02d', $day),
            ]);
        }

        $this->actingAs($user)
            ->get(route('daily-report.index', [
                'filter' => 'month',
                'date' => '2026-08-14',
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('reports', 3)
                ->where('reportPagination.has_more', true)
                ->where('reportPagination.next_page', 2));

        $this->actingAs($user)
            ->getJson(route('daily-report.more', [
                'filter' => 'month',
                'date' => '2026-08-14',
                'report_page' => 2,
            ]))
            ->assertOk()
            ->assertJsonCount(3, 'reports')
            ->assertJsonPath('pagination.current_page', 2);
    }

    public function test_project_group_tasks_are_loaded_on_demand(): void
    {
        [$owner, $project, $group, $task] = $this->projectContext();

        $this->actingAs($owner)
            ->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page
                ->missing('project.groups.0.tasks')
                ->has('project.task_overview', 1));

        $this->actingAs($owner)
            ->getJson(route('projects.groups.tasks', [$project, $group]))
            ->assertOk()
            ->assertJsonPath('tasks.0.id', $task->id)
            ->assertJsonPath('tasks.0.can.update', true);
    }

    public function test_project_groups_are_loaded_three_at_a_time_from_backend(): void
    {
        [$owner, $project] = $this->projectContext();

        foreach (range(2, 7) as $position) {
            ProjectGroup::create([
                'project_id' => $project->id,
                'name' => "Group {$position}",
                'position' => $position,
            ]);
        }

        $this->actingAs($owner)
            ->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page
                ->has('project.groups', 3)
                ->where('groupPagination.has_more', true)
                ->where('groupPagination.next_page', 2));

        $this->actingAs($owner)
            ->getJson(route('projects.groups.more', [$project, 'page' => 2]))
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('pagination.current_page', 2);
    }

    public function test_task_timeline_must_stay_inside_project_and_parent(): void
    {
        [$owner, $project, $group, $task] = $this->projectContext();
        $project->update(['start_date' => '2026-08-10', 'end_date' => '2026-08-31']);
        $task->update(['start_date' => '2026-08-12', 'due_date' => '2026-08-20']);

        $this->actingAs($owner)
            ->post(route('project-tasks.store'), [
                'project_id' => $project->id,
                'project_group_id' => $group->id,
                'parent_id' => $task->id,
                'title' => 'Subtask outside parent',
                'start_date' => '2026-08-11',
                'due_date' => '2026-08-20',
            ])
            ->assertSessionHasErrors('start_date');

        $this->actingAs($owner)
            ->post(route('project-tasks.store'), [
                'project_id' => $project->id,
                'project_group_id' => $group->id,
                'title' => 'Task outside project',
                'start_date' => '2026-08-15',
                'due_date' => '2026-09-01',
            ])
            ->assertSessionHasErrors('due_date');
    }

    public function test_task_comments_survive_task_deletion(): void
    {
        [$owner, $project, $group, $task] = $this->projectContext();
        $admin = User::factory()->create(['role' => 'super_admin']);
        $comment = $task->comments()->create([
            'user_id' => $owner->id,
            'body' => 'Riwayat komentar',
        ]);

        $this->actingAs($admin)
            ->delete(route('project-tasks.destroy', $task))
            ->assertRedirect();

        $this->assertDatabaseHas('task_comments', [
            'id' => $comment->id,
            'project_task_id' => null,
        ]);
    }

    private function projectContext(): array
    {
        $owner = User::factory()->create();
        $project = Project::create([
            'created_by' => $owner->id,
            'name' => 'Efficient Project',
            'slug' => 'efficient-project-'.uniqid(),
        ]);
        $project->members()->attach($owner->id, ['role' => 'owner']);
        $group = ProjectGroup::create([
            'project_id' => $project->id,
            'name' => 'Main Group',
            'position' => 1,
        ]);
        $task = ProjectTask::create([
            'project_id' => $project->id,
            'project_group_id' => $group->id,
            'created_by' => $owner->id,
            'title' => 'Main Task',
            'status' => ProjectTask::STATUS_TO_DO,
        ]);
        $task->assignees()->attach($owner->id);

        return [$owner, $project, $group, $task];
    }
}
