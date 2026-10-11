<?php

namespace Tests\Feature;

use App\Exports\DailyReportExport;
use App\Models\DailyReport;
use App\Models\DailyReportItem;
use App\Models\Event;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\ProjectTask;
use App\Models\TaskComment;
use App\Models\User;
use App\Services\DailyReportSyncService;
use App\Services\ProjectTaskHierarchyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProjectAccessHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_outsider_can_view_but_cannot_modify_project_content(): void
    {
        [$owner, $project, $task] = $this->projectWithTask();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->get(route('projects.show', $project))->assertOk();
        $this->actingAs($outsider)
            ->patch(route('project-tasks.update', $task), ['title' => 'Changed'])
            ->assertForbidden();
        $this->actingAs($outsider)
            ->post(route('project-tasks.comments.store', $task), ['body' => 'No access'])
            ->assertRedirect();

        $this->assertSame('Original Task', $task->fresh()->title);
        $this->assertDatabaseHas('task_comments', [
            'project_task_id' => $task->id,
            'user_id' => $outsider->id,
            'body' => 'No access',
        ]);
    }

    public function test_only_project_owner_or_task_assignee_can_update_task_and_subtask(): void
    {
        [$owner, $project, $task] = $this->projectWithTask();
        $assignedMember = User::factory()->create();
        $unassignedMember = User::factory()->create();
        $project->members()->attach([
            $assignedMember->id => ['role' => 'member'],
            $unassignedMember->id => ['role' => 'member'],
        ]);
        $task->assignees()->sync([$assignedMember->id]);

        $subtask = ProjectTask::create([
            'project_id' => $project->id,
            'parent_id' => $task->id,
            'created_by' => $owner->id,
            'title' => 'Original Subtask',
            'status' => ProjectTask::STATUS_TO_DO,
        ]);
        $subtask->assignees()->attach($assignedMember->id);

        $this->actingAs($unassignedMember)
            ->patch(route('project-tasks.update', $task), ['title' => 'Unauthorized Task'])
            ->assertForbidden();
        $this->actingAs($unassignedMember)
            ->patch(route('project-tasks.update', $subtask), ['title' => 'Unauthorized Subtask'])
            ->assertForbidden();

        $this->actingAs($assignedMember)
            ->patch(route('project-tasks.update', $task), ['title' => 'Assigned Task'])
            ->assertRedirect();
        $this->actingAs($assignedMember)
            ->patch(route('project-tasks.update', $subtask), ['title' => 'Assigned Subtask'])
            ->assertRedirect();

        $this->actingAs($assignedMember)
            ->delete(route('project-tasks.destroy', $task))
            ->assertForbidden();
        $this->actingAs($assignedMember)
            ->delete(route('project-tasks.destroy', $subtask))
            ->assertForbidden();

        $this->actingAs($owner)
            ->patch(route('project-tasks.update', $task), ['title' => 'Owner Task'])
            ->assertRedirect();
        $this->actingAs($owner)
            ->patch(route('project-tasks.update', $subtask), ['title' => 'Owner Subtask'])
            ->assertRedirect();

        $this->assertSame('Owner Task', $task->fresh()->title);
        $this->assertSame('Owner Subtask', $subtask->fresh()->title);
    }

    public function test_task_comments_are_loaded_on_demand_for_read_only_viewers(): void
    {
        [$owner, $project, $task] = $this->projectWithTask();
        $outsider = User::factory()->create();
        TaskComment::create([
            'project_task_id' => $task->id,
            'user_id' => $owner->id,
            'body' => 'Detail loaded lazily',
        ]);

        $this->actingAs($outsider)
            ->getJson(route('project-tasks.show', $task))
            ->assertOk()
            ->assertJsonPath('comments.0.body', 'Detail loaded lazily');
    }

    public function test_parent_assignees_are_derived_from_all_subtasks_and_cannot_be_edited(): void
    {
        [$owner, $project, $parent] = $this->projectWithTask();
        $secondMember = User::factory()->create();
        $project->members()->attach($secondMember->id, ['role' => 'member']);

        $firstSubtask = ProjectTask::create([
            'project_id' => $project->id,
            'parent_id' => $parent->id,
            'created_by' => $owner->id,
            'title' => 'First subtask',
            'status' => ProjectTask::STATUS_TO_DO,
        ]);
        $secondSubtask = ProjectTask::create([
            'project_id' => $project->id,
            'parent_id' => $parent->id,
            'created_by' => $owner->id,
            'title' => 'Second subtask',
            'status' => ProjectTask::STATUS_TO_DO,
        ]);
        $firstSubtask->assignees()->sync([$owner->id, $secondMember->id]);
        $secondSubtask->assignees()->sync([$secondMember->id]);

        app(ProjectTaskHierarchyService::class)
            ->syncTaskAssigneesFromSubtasks($parent);

        $this->assertEqualsCanonicalizing(
            [$owner->id, $secondMember->id],
            $parent->assignees()->pluck('users.id')->all(),
        );

        $this->actingAs($owner)
            ->patch(route('project-tasks.assignees.toggle', $firstSubtask), [
                'user_id' => $owner->id,
                'checked' => false,
            ])
            ->assertRedirect();

        $this->assertSame(
            [$secondMember->id],
            $parent->assignees()->pluck('users.id')->map(fn ($id) => (int) $id)->all(),
        );

        $this->actingAs($owner)
            ->patch(route('project-tasks.assignees.toggle', $parent), [
                'user_id' => $owner->id,
                'checked' => false,
            ])
            ->assertSessionHasErrors('assignees');

        $this->actingAs($owner)
            ->patch(route('project-tasks.update', $parent), [
                'assignee_ids' => [$owner->id],
            ])
            ->assertSessionHasErrors('assignee_ids');
    }

    public function test_parent_priority_is_derived_from_highest_subtask_priority(): void
    {
        [$owner, $project, $parent] = $this->projectWithTask();
        $firstSubtask = ProjectTask::create([
            'project_id' => $project->id,
            'parent_id' => $parent->id,
            'created_by' => $owner->id,
            'title' => 'Low priority subtask',
            'status' => ProjectTask::STATUS_TO_DO,
            'priority' => 1,
        ]);
        $secondSubtask = ProjectTask::create([
            'project_id' => $project->id,
            'parent_id' => $parent->id,
            'created_by' => $owner->id,
            'title' => 'High priority subtask',
            'status' => ProjectTask::STATUS_TO_DO,
            'priority' => 3,
        ]);

        app(ProjectTaskHierarchyService::class)
            ->syncTaskPriorityFromSubtasks($parent);

        $this->assertSame(3, (int) $parent->fresh()->priority);

        $this->actingAs($owner)
            ->patch(route('project-tasks.update', $parent), ['priority' => 1])
            ->assertSessionHasErrors('priority');

        $this->actingAs($owner)
            ->patch(route('project-tasks.update', $secondSubtask), ['priority' => 2])
            ->assertRedirect();

        $this->assertSame(2, (int) $parent->fresh()->priority);

        $superAdmin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($owner)
            ->delete(route('project-tasks.destroy', $secondSubtask))
            ->assertForbidden();

        $this->actingAs($superAdmin)
            ->delete(route('project-tasks.destroy', $secondSubtask))
            ->assertRedirect();

        $this->assertSame(1, (int) $parent->fresh()->priority);
        $this->assertDatabaseHas('project_tasks', ['id' => $firstSubtask->id]);
    }

    public function test_parent_links_are_derived_from_subtasks_and_cannot_be_edited(): void
    {
        [$owner, $project, $parent] = $this->projectWithTask();
        $subtask = ProjectTask::create([
            'project_id' => $project->id,
            'parent_id' => $parent->id,
            'created_by' => $owner->id,
            'title' => 'Linked subtask',
            'status' => ProjectTask::STATUS_TO_DO,
        ]);
        $existingParentLink = $parent->links()->create([
            'created_by' => $owner->id,
            'url' => 'https://parent.example.com',
        ]);

        $this->actingAs($owner)
            ->put(route('project-tasks.links.sync', $parent), [
                'links' => [['url' => 'https://changed.example.com']],
            ])
            ->assertSessionHasErrors('links');

        $this->actingAs($owner)
            ->delete(route('task-links.destroy', $existingParentLink))
            ->assertSessionHasErrors('links');

        $this->actingAs($owner)
            ->put(route('project-tasks.links.sync', $subtask), [
                'links' => [
                    ['url' => 'https://first.example.com'],
                    ['url' => 'https://second.example.com'],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('task_links', ['id' => $existingParentLink->id]);
        $this->assertCount(2, $subtask->links()->get());
    }

    public function test_removing_project_member_also_removes_task_subtask_and_event_assignments(): void
    {
        [$owner, $project, $parent] = $this->projectWithTask();
        $removedMember = User::factory()->create();
        $keptMember = User::factory()->create();
        $project->members()->attach([
            $removedMember->id => ['role' => 'member'],
            $keptMember->id => ['role' => 'member'],
        ]);

        $subtask = ProjectTask::create([
            'project_id' => $project->id,
            'parent_id' => $parent->id,
            'created_by' => $owner->id,
            'title' => 'Membership sync subtask',
            'status' => ProjectTask::STATUS_TO_DO,
        ]);
        $parent->assignees()->sync([$owner->id, $removedMember->id, $keptMember->id]);
        $subtask->assignees()->sync([$removedMember->id, $keptMember->id]);

        $event = Event::create([
            'project_id' => $project->id,
            'created_by' => $owner->id,
            'title' => 'Membership sync event',
            'event_date' => '2026-08-12',
        ]);
        $event->assignees()->sync([$removedMember->id, $keptMember->id]);

        $this->actingAs($owner)
            ->patch(route('projects.update', $project), [
                'name' => $project->name,
                'member_ids' => [$keptMember->id],
            ])
            ->assertRedirect();

        $this->assertFalse($project->members()->whereKey($removedMember->id)->exists());
        $this->assertFalse($parent->assignees()->whereKey($removedMember->id)->exists());
        $this->assertFalse($subtask->assignees()->whereKey($removedMember->id)->exists());
        $this->assertFalse($event->assignees()->whereKey($removedMember->id)->exists());
        $this->assertTrue($subtask->assignees()->whereKey($keptMember->id)->exists());
        $this->assertTrue($event->assignees()->whereKey($keptMember->id)->exists());
    }

    public function test_daily_report_export_is_csv_and_sanitizes_formulas(): void
    {
        $user = User::factory()->create();
        $report = DailyReport::create([
            'user_id' => $user->id,
            'report_date' => '2026-08-12',
        ]);
        $report->items()->create([
            'source' => 'manual',
            'title' => '=malicious()',
            'position' => 1,
        ]);

        $this->assertCount(1, (new DailyReportExport($user->id, 'day', '2026-08-12'))->array());

        $response = $this->actingAs($user)->get(route('exports.daily-report', [
            'date' => '2026-08-12',
            'user_id' => $user->id,
        ]));

        $response->assertOk();
        $this->assertStringContainsString('.csv', $response->headers->get('content-disposition'));
        $this->assertStringContainsString("'=malicious()", $response->streamedContent());
    }

    public function test_member_cannot_modify_archived_project_but_owner_and_super_admin_can(): void
    {
        [$owner, $project, $task] = $this->projectWithTask();
        $member = User::factory()->create();
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        $project->members()->attach($member->id, ['role' => 'member']);
        $project->update(['archived_at' => now()]);

        $this->actingAs($member)
            ->patch(route('project-tasks.update', $task), ['title' => 'Member Change'])
            ->assertForbidden();
        $this->actingAs($owner)
            ->patch(route('project-tasks.update', $task), ['title' => 'Owner Change'])
            ->assertRedirect();
        $this->actingAs($superAdmin)
            ->patch(route('project-tasks.update', $task), ['title' => 'Super Change'])
            ->assertRedirect();
    }

    public function test_task_rejects_group_parent_and_assignee_from_another_project(): void
    {
        [$member, $project] = $this->projectWithTask();
        [$otherOwner, $otherProject, $otherTask] = $this->projectWithTask('Other');
        $otherGroup = ProjectGroup::create([
            'project_id' => $otherProject->id,
            'name' => 'Other Group',
            'position' => 1,
        ]);

        $this->actingAs($member)
            ->post(route('project-tasks.store'), [
                'project_id' => $project->id,
                'project_group_id' => $otherGroup->id,
                'parent_id' => $otherTask->id,
                'title' => 'Invalid Task',
                'assignee_ids' => [$otherOwner->id],
            ])
            ->assertSessionHasErrors(['project_group_id', 'parent_id', 'assignee_ids.0']);
    }

    public function test_project_create_and_update_reject_end_date_before_start_date(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->post(route('projects.store'), [
                'name' => 'Invalid Timeline',
                'start_date' => '2026-08-10',
                'end_date' => '2026-08-01',
            ])
            ->assertSessionHasErrors([
                'end_date' => 'End date tidak boleh lebih awal dari start date.',
            ]);

        [$projectOwner, $project] = $this->projectWithTask('Timeline');

        $this->actingAs($projectOwner)
            ->patch(route('projects.update', $project), [
                'name' => $project->name,
                'start_date' => '2026-08-10',
                'end_date' => '2026-08-01',
            ])
            ->assertSessionHasErrors([
                'end_date' => 'End date tidak boleh lebih awal dari start date.',
            ]);

        $this->assertNull($project->fresh()->start_date);
        $this->assertNull($project->fresh()->end_date);
    }

    public function test_project_and_task_dates_serialize_as_date_only_values(): void
    {
        [$owner, $project, $task] = $this->projectWithTask('Date Only');
        $project->update([
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-05',
        ]);
        $task->update([
            'start_date' => '2026-08-01',
            'due_date' => '2026-08-05',
        ]);

        $projectData = $project->fresh()->toArray();
        $taskData = $task->fresh()->toArray();

        $this->assertSame('2026-08-01', $projectData['start_date']);
        $this->assertSame('2026-08-05', $projectData['end_date']);
        $this->assertSame('2026-08-01', $taskData['start_date']);
        $this->assertSame('2026-08-05', $taskData['due_date']);
    }

    public function test_project_rename_redirects_to_the_new_slug(): void
    {
        [$owner, $project] = $this->projectWithTask('Before Rename');
        $oldSlug = $project->slug;

        $response = $this->actingAs($owner)
            ->patch(route('projects.update', $project), [
                'name' => 'After Rename',
            ]);

        $project->refresh();

        $this->assertNotSame($oldSlug, $project->slug);
        $this->assertSame('after-rename', $project->slug);
        $response->assertRedirect(route('projects.show', $project));
        $this->actingAs($owner)
            ->get(route('projects.show', $project))
            ->assertOk();
    }

    public function test_user_cannot_export_another_users_daily_report(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        DailyReport::create(['user_id' => $otherUser->id, 'report_date' => '2026-08-12']);

        $this->actingAs($user)
            ->get(route('exports.daily-report', [
                'date' => '2026-08-12',
                'user_id' => $otherUser->id,
            ]))
            ->assertForbidden();
    }

    public function test_event_requires_project_members_and_persists_end_time(): void
    {
        [$owner, $project] = $this->projectWithTask();
        $outsider = User::factory()->create();
        $payload = [
            'project_id' => $project->id,
            'title' => 'Planning',
            'event_date' => '2026-08-12',
            'start_time' => '09:00',
            'end_time' => '10:30',
            'assignee_ids' => [$owner->id],
        ];

        $this->actingAs($outsider)
            ->post(route('events.store'), $payload)
            ->assertForbidden();

        $this->actingAs($owner)
            ->post(route('events.store'), $payload)
            ->assertRedirect(route('events.index'));

        $event = Event::query()->where('title', 'Planning')->firstOrFail();
        $this->assertStringStartsWith('10:30', $event->end_time);
    }

    public function test_event_creator_can_update_but_cannot_delete_event(): void
    {
        [$projectOwner, $project] = $this->projectWithTask();
        $eventOwner = User::factory()->create();
        $project->members()->attach($eventOwner->id, ['role' => 'member']);

        $event = Event::create([
            'project_id' => $project->id,
            'created_by' => $eventOwner->id,
            'title' => 'Owner event',
            'event_date' => '2026-08-12',
            'start_time' => '09:00',
            'end_time' => '10:00',
        ]);
        $event->assignees()->attach([$projectOwner->id, $eventOwner->id]);

        $this->actingAs($eventOwner)
            ->get(route('events.show', $event))
            ->assertOk()
            ->assertJsonPath('can.update', true)
            ->assertJsonPath('can.delete', false);

        $this->actingAs($eventOwner)
            ->patch(route('events.update', $event), [
                'project_id' => $project->id,
                'title' => 'Updated by event owner',
                'event_date' => '2026-08-13',
                'start_time' => '10:00',
                'end_time' => '11:00',
                'assignee_ids' => [$projectOwner->id, $eventOwner->id],
            ])
            ->assertRedirect(route('events.index'));

        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'title' => 'Updated by event owner',
        ]);

        $this->actingAs($eventOwner)
            ->delete(route('events.destroy', $event))
            ->assertForbidden();

        $this->assertDatabaseHas('events', ['id' => $event->id]);
    }

    public function test_event_detail_is_internal_and_event_export_matches_page_scope(): void
    {
        [$owner, $project] = $this->projectWithTask();
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        $project->members()->attach($member->id, ['role' => 'member']);

        $creatorEvent = Event::create([
            'project_id' => $project->id,
            'created_by' => $member->id,
            'title' => 'Creator event visible',
            'event_date' => '2026-08-12',
            'start_time' => '09:00',
        ]);
        $assignedEvent = Event::create([
            'project_id' => $project->id,
            'created_by' => $owner->id,
            'title' => 'Assigned event visible',
            'event_date' => '2026-08-13',
            'start_time' => '09:00',
        ]);
        $assignedEvent->assignees()->attach($member->id);
        $hiddenEvent = Event::create([
            'project_id' => $project->id,
            'created_by' => $owner->id,
            'title' => 'Member only hidden from page',
            'event_date' => '2026-08-14',
            'start_time' => '09:00',
        ]);

        $this->actingAs($member)->get(route('events.show', $hiddenEvent))->assertOk();
        $this->actingAs($outsider)->get(route('events.show', $hiddenEvent))->assertForbidden();
        $this->actingAs($superAdmin)->get(route('events.show', $hiddenEvent))->assertOk();

        $export = $this->actingAs($member)->get(route('exports.events', [
            'filter' => 'month',
            'date' => '2026-08-12',
        ]));

        $export->assertOk();
        $csv = $export->streamedContent();
        $this->assertStringContainsString($creatorEvent->title, $csv);
        $this->assertStringContainsString($assignedEvent->title, $csv);
        $this->assertStringNotContainsString($hiddenEvent->title, $csv);
    }

    public function test_all_employees_can_update_daily_report_note_but_not_other_fields(): void
    {
        $reportOwner = User::factory()->create();
        $employee = User::factory()->create();
        $report = DailyReport::create([
            'user_id' => $reportOwner->id,
            'report_date' => '2026-08-13',
        ]);
        $item = DailyReportItem::create([
            'daily_report_id' => $report->id,
            'source' => 'manual',
            'position' => 1,
            'title' => 'Original item',
            'snapshot_status' => ProjectTask::STATUS_TO_DO,
        ]);

        $this->actingAs($employee)
            ->patch(route('daily-report.items.update', $item), ['note' => 'Shared note'])
            ->assertRedirect();
        $this->assertSame('Shared note', $item->fresh()->note);

        $this->actingAs($employee)
            ->patch(route('daily-report.items.update', $item), ['title' => 'Unauthorized title'])
            ->assertForbidden();
        $this->assertSame('Original item', $item->fresh()->title);
    }

    public function test_daily_report_date_serializes_without_utc_date_shift(): void
    {
        $user = User::factory()->create();
        $report = DailyReport::create([
            'user_id' => $user->id,
            'report_date' => '2026-08-13',
        ]);

        $this->assertSame('2026-08-13', $report->toArray()['report_date']);

        $this->actingAs($user)
            ->post(route('daily-report.items.store'), [
                'report_date' => $report->toArray()['report_date'],
                'user_id' => $user->id,
                'title' => 'Manual task on selected date',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('daily_report_items', [
            'daily_report_id' => $report->id,
            'title' => 'Manual task on selected date',
        ]);

        $item = DailyReportItem::query()
            ->where('daily_report_id', $report->id)
            ->where('title', 'Manual task on selected date')
            ->firstOrFail();
        $item->update([
            'snapshot_start_date' => '2026-08-16',
            'snapshot_due_date' => '2026-08-21',
        ]);

        $this->assertSame('2026-08-16', $item->fresh()->toArray()['snapshot_start_date']);
        $this->assertSame('2026-08-21', $item->fresh()->toArray()['snapshot_due_date']);

        $this->actingAs($user)
            ->patch(route('daily-report.items.update', $item), [
                'snapshot_start_date' => '2026-08-21',
            ])
            ->assertRedirect();

        $this->assertSame('2026-08-21', $item->fresh()->snapshot_start_date->toDateString());
        $this->assertSame('2026-08-21', $item->fresh()->snapshot_due_date->toDateString());
    }

    public function test_in_progress_task_is_synced_to_assignees_daily_report_today(): void
    {
        Carbon::setTestNow('2026-08-13 10:00:00');
        [$owner, $project] = $this->projectWithTask('Daily Sync');
        $group = ProjectGroup::create([
            'project_id' => $project->id,
            'name' => 'Today',
            'position' => 1,
        ]);

        $this->actingAs($owner)
            ->post(route('project-tasks.store'), [
                'project_id' => $project->id,
                'project_group_id' => $group->id,
                'title' => 'In progress today',
                'status' => ProjectTask::STATUS_IN_PROGRESS,
                'assignee_ids' => [$owner->id],
            ])
            ->assertRedirect();

        $task = ProjectTask::query()->where('title', 'In progress today')->firstOrFail();
        $report = DailyReport::query()
            ->where('user_id', $owner->id)
            ->whereDate('report_date', '2026-08-13')
            ->firstOrFail();

        $this->assertDatabaseHas('daily_report_items', [
            'daily_report_id' => $report->id,
            'project_task_id' => $task->id,
            'source' => 'project',
            'snapshot_status' => ProjectTask::STATUS_IN_PROGRESS,
        ]);
    }

    public function test_in_progress_task_waits_for_start_date_and_remains_after_due_date_as_overdue(): void
    {
        Carbon::setTestNow('2026-08-13 10:00:00');
        [$owner, $project] = $this->projectWithTask('Future Daily Sync');

        $this->actingAs($owner)
            ->post(route('project-tasks.store'), [
                'project_id' => $project->id,
                'title' => 'Starts tomorrow',
                'status' => ProjectTask::STATUS_IN_PROGRESS,
                'start_date' => '2026-08-14',
                'due_date' => '2026-08-15',
                'assignee_ids' => [$owner->id],
            ])
            ->assertRedirect();

        $task = ProjectTask::query()->where('title', 'Starts tomorrow')->firstOrFail();

        $this->assertDatabaseMissing('daily_report_items', [
            'project_task_id' => $task->id,
        ]);

        $sync = app(DailyReportSyncService::class);
        $sync->syncDateForUser('2026-08-14', $owner->id);

        $this->assertDatabaseHas('daily_report_items', [
            'project_task_id' => $task->id,
            'snapshot_status' => ProjectTask::STATUS_IN_PROGRESS,
            'snapshot_start_date' => '2026-08-14 00:00:00',
            'snapshot_due_date' => '2026-08-15 00:00:00',
            'is_overdue' => false,
        ]);

        $sync->syncDateForUser('2026-08-16', $owner->id);

        $this->assertDatabaseHas('daily_report_items', [
            'project_task_id' => $task->id,
            'snapshot_status' => ProjectTask::STATUS_IN_PROGRESS,
            'is_overdue' => true,
        ]);
    }

    public function test_archived_project_event_is_read_only_for_members_but_not_owner(): void
    {
        [$owner, $project] = $this->projectWithTask();
        $member = User::factory()->create();
        $project->members()->attach($member->id, ['role' => 'member']);
        $project->update(['archived_at' => now()]);
        $payload = [
            'project_id' => $project->id,
            'title' => 'Archived planning',
            'event_date' => '2026-08-12',
            'start_time' => '09:00',
            'end_time' => '10:00',
            'assignee_ids' => [$member->id],
        ];

        $this->actingAs($member)
            ->post(route('events.store'), $payload)
            ->assertForbidden();

        $this->actingAs($owner)
            ->post(route('events.store'), $payload)
            ->assertRedirect(route('events.index'));
    }

    public function test_svg_upload_is_sanitized(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        [$member, $project, $task] = $this->projectWithTask();
        $project->members()->syncWithoutDetaching([$member->id => ['role' => 'owner']]);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(1)</script><rect width="10" height="10"/></svg>';

        $this->actingAs($member)
            ->post(route('project-tasks.files.store', $task), [
                'files' => [UploadedFile::fake()->createWithContent('unsafe.svg', $svg)],
            ])
            ->assertRedirect();

        $taskFile = $task->files()->firstOrFail();
        $this->assertSame('local', $taskFile->disk);
        Storage::disk('public')->assertMissing($taskFile->path);
        $stored = Storage::disk('local')->get($taskFile->path);
        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('onload=', $stored);
    }

    private function projectWithTask(string $suffix = 'Main'): array
    {
        $owner = User::factory()->create();
        $project = Project::create([
            'created_by' => $owner->id,
            'name' => $suffix.' Project',
            'slug' => strtolower($suffix).'-project-'.uniqid(),
        ]);
        $project->members()->attach($owner->id, ['role' => 'owner']);
        $task = ProjectTask::create([
            'project_id' => $project->id,
            'created_by' => $owner->id,
            'title' => 'Original Task',
            'status' => ProjectTask::STATUS_TO_DO,
        ]);
        $task->assignees()->attach($owner->id);

        return [$owner, $project, $task];
    }
}
