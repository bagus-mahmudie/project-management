<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Services\DailyReportSyncService;
use App\Services\ProjectTaskHierarchyService;
use App\Services\TaskTimelineValidationService;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProjectTaskController extends Controller
{
    public function __construct(
        private readonly ProjectTaskHierarchyService $hierarchyService,
        private readonly DailyReportSyncService $dailyReportSync,
        private readonly TaskTimelineValidationService $timelineValidation,
    ) {}

    public function show(ProjectTask $projectTask)
    {
        $this->authorize('view', $projectTask);

        return response()->json($projectTask->load([
            'assignees.profile',
            'files.uploader.profile',
            'links',
            'comments.user.profile',
            'comments.replies.user.profile',
        ]));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'project_group_id' => [
                'nullable',
                Rule::exists('project_groups', 'id')->where('project_id', $request->input('project_id')),
            ],
            'parent_id' => [
                'nullable',
                Rule::exists('project_tasks', 'id')
                    ->where('project_id', $request->input('project_id'))
                    ->whereNull('parent_id'),
            ],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => [
                'nullable',
                'in:to_do,in_progress,pending,ready_to_check,need_revision,done',
            ],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'due_date' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:start_date',
            ],
            'priority' => [
                'nullable',
                'integer',
                'min:0',
                'max:3',
            ],
            'assignee_ids' => ['nullable', 'array', 'max:100'],
            'assignee_ids.*' => [
                Rule::exists('project_members', 'user_id')->where('project_id', $request->input('project_id')),
            ],
        ]);

        $project = Project::findOrFail($validated['project_id']);

        $parent = null;

        if (! empty($validated['parent_id'])) {
            $parent = ProjectTask::query()->findOrFail($validated['parent_id']);

            if ((int) $parent->project_group_id !== (int) ($validated['project_group_id'] ?? 0)) {
                throw ValidationException::withMessages([
                    'project_group_id' => ['Group subtask harus sama dengan group parent task.'],
                ]);
            }
        }

        if (
            ! auth()->user()->isSuperAdmin() &&
            ! $project->isMember(auth()->user())
        ) {
            abort(403, 'Only project members can create tasks.');
        }

        abort_if($project->archived_at && ! auth()->user()->isSuperAdmin() && ! $project->isOwner(auth()->user()), 403);

        $this->timelineValidation->validate(
            $project,
            $parent,
            $validated['start_date'] ?? null,
            $validated['due_date'] ?? null,
        );

        return DB::transaction(function () use ($validated, $project) {
            Project::query()->whereKey($project->id)->lockForUpdate()->first();
            $position = ProjectTask::query()
                ->where('project_id', $project->id)
                ->where(
                    'project_group_id',
                    $validated['project_group_id'] ?? null,
                )
                ->where('parent_id', $validated['parent_id'] ?? null)
                ->max('position');

            $task = ProjectTask::create([
                'project_id' => $project->id,
                'project_group_id' => $validated['project_group_id'] ?? null,
                'parent_id' => $validated['parent_id'] ?? null,
                'created_by' => auth()->id(),
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'status' => $validated['status'] ??
                    ProjectTask::STATUS_TO_DO,
                'start_date' => $validated['start_date'] ?? null,
                'due_date' => $validated['due_date'] ?? null,
                'priority' => $validated['priority'] ?? 0,
                'position' => ($position ?? 0) + 1,
                'status_changed_at' => now(),
            ]);

            $task->statusHistories()->create([
                'changed_by' => auth()->id(),
                'from_status' => null,
                'to_status' => $task->status,
                'changed_at' => $task->created_at ?? now(),
            ]);

            /*
            * Task utama default ke user pembuat.
            * Subtask default mewarisi assignee parent.
            */
            $defaultAssigneeIds = $project->isMember(auth()->user())
                ? [auth()->id()]
                : $project->members()->wherePivot('role', 'owner')->pluck('users.id')->all();

            if ($task->parent_id) {
                $parentAssigneeIds = ProjectTask::query()
                    ->find($task->parent_id)
                    ?->assignees()
                    ->pluck('users.id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                if (! empty($parentAssigneeIds)) {
                    $defaultAssigneeIds = $parentAssigneeIds;
                }
            }

            $assigneeIds = collect(
                $validated['assignee_ids'] ??
                $defaultAssigneeIds,
            )
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

            $task->assignees()->sync($assigneeIds);

            /*
             * Aktivitas pembuatan task milik user yang membuat task.
             */
            ActivityLogger::log(
                'task_created',
                'Membuat task '.$task->title,
                $task,
                [
                    'task_id' => $task->id,
                    'task_title' => $task->title,
                    'project_id' => $task->project_id,
                    'status' => $task->status,
                ],
            );

            /*
             * Catat assignment pada masing-masing employee.
             */
            foreach ($assigneeIds as $assigneeId) {
                /*
                 * User pembuat sudah memperoleh log task_created.
                 * Hapus kondisi ini apabila Anda juga ingin menampilkan
                 * "Assigned to ..." pada activity milik pembuat.
                 */
                if ($assigneeId === (int) auth()->id()) {
                    continue;
                }

                ActivityLogger::log(
                    'task_assigned',
                    'Assigned to '.$task->title,
                    $task,
                    [
                        'task_id' => $task->id,
                        'task_title' => $task->title,
                        'project_id' => $task->project_id,
                    ],
                    $assigneeId,
                );
            }

            /*
             * Apabila task langsung dibuat dengan status done,
             * catat completed ke seluruh assignee.
             */
            if ($task->status === ProjectTask::STATUS_DONE) {
                foreach ($assigneeIds as $assigneeId) {
                    ActivityLogger::log(
                        'task_completed',
                        'Complete '.$task->title,
                        $task,
                        [
                            'task_id' => $task->id,
                            'task_title' => $task->title,
                            'project_id' => $task->project_id,
                            'status' => $task->status,
                        ],
                        $assigneeId,
                    );
                }
            }

            $task->load([
                'assignees',
                'parent',
            ]);

            /*
            * Jika yang dibuat adalah subtask:
            * - tambahkan assignee subtask ke parent;
            * - hitung ulang status parent;
            * - sinkronkan parent ke Daily Report.
            */
            $parent = null;

            if ($task->parent_id) {
                $parent = $this->hierarchyService
                    ->syncParentFromSubtask(
                        $task,
                        auth()->id(),
                    );
            }

            $this->syncTodayDailyReportItems($task);

            if ($parent) {
                $parent->loadMissing('assignees');

                $this->syncTodayDailyReportItems(
                    $parent,
                );
            }

            return back();
        });
    }

    public function update(
        Request $request,
        ProjectTask $projectTask,
    ) {
        return DB::transaction(
            fn () => $this->updateWithinTransaction($request, $projectTask),
        );
    }

    private function updateWithinTransaction(
        Request $request,
        ProjectTask $projectTask,
    ) {
        $this->authorize('update', $projectTask);

        /*
        * Parent yang mempunyai subtask memakai derived status.
        * Statusnya tidak boleh diubah langsung oleh user.
        */
        if (
            $request->exists('status') &&
            $projectTask->subtasks()->exists()
        ) {
            throw ValidationException::withMessages([
                'status' => [
                    'Status task ini dihitung otomatis dari status seluruh subtask.',
                ],
            ]);
        }

        if (
            $request->exists('assignee_ids') &&
            $projectTask->subtasks()->exists()
        ) {
            throw ValidationException::withMessages([
                'assignee_ids' => [
                    'Assignee parent dihitung otomatis dari gabungan assignee seluruh subtask.',
                ],
            ]);
        }

        if (
            $request->exists('priority') &&
            $projectTask->subtasks()->exists()
        ) {
            throw ValidationException::withMessages([
                'priority' => [
                    'Priority parent dihitung otomatis dari priority tertinggi seluruh subtask.',
                ],
            ]);
        }

        $statusChangedAt = null;
        /*
         * Simpan status lama sebelum task diperbarui.
         * Digunakan untuk mendeteksi perubahan menjadi done.
         */
        $oldStatus = $projectTask->status;

        $validated = $request->validate([
            'title' => [
                'sometimes',
                'required',
                'string',
                'max:10000',
                'max:255',
            ],
            'description' => [
                'sometimes',
                'nullable',
                'string',
            ],
            'status' => [
                'sometimes',
                'required',
                'in:to_do,in_progress,pending,ready_to_check,need_revision,done',
            ],
            'start_date' => [
                'sometimes',
                'nullable',
                'date_format:Y-m-d',
            ],
            'due_date' => [
                'sometimes',
                'nullable',
                'date_format:Y-m-d',
            ],
            'priority' => [
                'sometimes',
                'integer',
                'min:0',
                'max:3',
            ],
            'assignee_ids' => [
                'sometimes',
                'array',
                'max:100',
            ],
            'assignee_ids.*' => [
                Rule::exists('project_members', 'user_id')->where('project_id', $projectTask->project_id),
            ],
        ]);

        $effectiveStartDate = array_key_exists('start_date', $validated)
            ? $validated['start_date']
            : $projectTask->start_date?->toDateString();
        $effectiveDueDate = array_key_exists('due_date', $validated)
            ? $validated['due_date']
            : $projectTask->due_date?->toDateString();

        if (
            $effectiveStartDate &&
            $effectiveDueDate &&
            Carbon::parse($effectiveDueDate)
                ->lt(Carbon::parse($effectiveStartDate))
        ) {
            throw ValidationException::withMessages([
                'due_date' => ['Deadline tidak boleh lebih awal dari tanggal mulai.'],
            ]);
        }

        $projectTask->loadMissing(['project', 'parent']);
        $this->timelineValidation->validate(
            $projectTask->project,
            $projectTask->parent,
            $effectiveStartDate,
            $effectiveDueDate,
            $projectTask,
        );

        if (
            isset($validated['status']) &&
            $validated['status'] !==
                $projectTask->status
        ) {
            $statusChangedAt = now();

            $validated['status_changed_at'] =
                $statusChangedAt;
        }

        /*
         * Ambil assignee lama sebelum dilakukan sync.
         */
        $previousAssigneeIds = $projectTask
            ->assignees()
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $projectTask->update(
            collect($validated)
                ->except('assignee_ids')
                ->toArray(),
        );

        if (
            array_key_exists('status', $validated) &&
            $oldStatus !== $projectTask->status
        ) {
            $projectTask
                ->statusHistories()
                ->create([
                    'changed_by' => auth()->id(),
                    'from_status' => $oldStatus,
                    'to_status' => $projectTask->status,
                    'changed_at' => $statusChangedAt ?? now(),
                ]);
        }

        if (array_key_exists('assignee_ids', $validated)) {
            $newAssigneeIds = collect(
                $validated['assignee_ids'],
            )
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

            $projectTask
                ->assignees()
                ->sync($newAssigneeIds);

            $addedAssigneeIds = array_diff(
                $newAssigneeIds,
                $previousAssigneeIds,
            );

            $removedAssigneeIds = array_diff(
                $previousAssigneeIds,
                $newAssigneeIds,
            );

            foreach ($addedAssigneeIds as $assigneeId) {
                ActivityLogger::log(
                    'task_assigned',
                    'Assigned to '.$projectTask->title,
                    $projectTask,
                    [
                        'task_id' => $projectTask->id,
                        'task_title' => $projectTask->title,
                        'project_id' => $projectTask->project_id,
                    ],
                    (int) $assigneeId,
                );
            }

            foreach ($removedAssigneeIds as $assigneeId) {
                ActivityLogger::log(
                    'task_unassigned',
                    'Removed from '.$projectTask->title,
                    $projectTask,
                    [
                        'task_id' => $projectTask->id,
                        'task_title' => $projectTask->title,
                        'project_id' => $projectTask->project_id,
                    ],
                    (int) $assigneeId,
                );
            }
        }

        $projectTask
            ->refresh()
            ->load([
                'assignees',
                'parent',
                'subtasks',
            ]);

        $parent = null;

        /*
        * Parent perlu disinkronkan jika status, assignee, atau priority subtask berubah.
        */
        if (
            $projectTask->parent_id &&
            ($oldStatus !== $projectTask->status ||
                array_key_exists('assignee_ids', $validated) ||
                array_key_exists('priority', $validated))
        ) {
            $parent = $this->hierarchyService
                ->syncParentFromSubtask(
                    $projectTask,
                    auth()->id(),
                );
        }

        $this->syncTodayDailyReportItems($projectTask, $previousAssigneeIds);

        if ($parent) {
            $parent->loadMissing('assignees');

            $this->syncTodayDailyReportItems(
                $parent,
            );
        }

        $justCompleted =
            $oldStatus !== ProjectTask::STATUS_DONE &&
            $projectTask->status === ProjectTask::STATUS_DONE;

        if ($justCompleted) {
            /*
             * Catat complete ke seluruh employee yang ditugaskan.
             */
            foreach ($projectTask->assignees as $assignee) {
                ActivityLogger::log(
                    'task_completed',
                    'Complete '.$projectTask->title,
                    $projectTask,
                    [
                        'task_id' => $projectTask->id,
                        'task_title' => $projectTask->title,
                        'project_id' => $projectTask->project_id,
                        'status' => $projectTask->status,
                    ],
                    (int) $assignee->id,
                );
            }
        } else {
            ActivityLogger::log(
                'task_updated',
                'Mengupdate task '.$projectTask->title,
                $projectTask,
                [
                    'task_id' => $projectTask->id,
                    'task_title' => $projectTask->title,
                    'project_id' => $projectTask->project_id,
                    'status' => $projectTask->status,
                ],
            );
        }

        return back();
    }

    private function syncTodayDailyReportItems(
        ProjectTask $task,
        iterable $additionalUserIds = [],
    ): void {
        $task->loadMissing('assignees:id');

        $this->dailyReportSync->syncDateForUsers(
            now()->toDateString(),
            collect($additionalUserIds)->merge($task->assignees->pluck('id')),
        );
    }

    public function destroy(ProjectTask $projectTask)
    {
        $this->authorize('delete', $projectTask);

        ActivityLogger::log(
            'task_deleted',
            'Menghapus task '.$projectTask->title,
            $projectTask,
            [
                'task_id' => $projectTask->id,
                'task_title' => $projectTask->title,
                'project_id' => $projectTask->project_id,
            ],
        );

        $parentId = $projectTask->parent_id;
        $affectedUserIds = $projectTask->assignees()->pluck('users.id');

        $projectTask->delete();

        if ($parentId) {
            $parent = ProjectTask::query()
                ->with([
                    'assignees',
                    'subtasks',
                ])
                ->find($parentId);

            if ($parent) {
                /*
                * Jika masih mempunyai subtask,
                * hitung ulang status parent.
                *
                * Jika subtask terakhir dihapus,
                * status terakhir parent dipertahankan
                * dan selanjutnya kembali bisa diedit manual.
                */
                if ($parent->subtasks()->exists()) {
                    $parent = $this->hierarchyService
                        ->syncTaskAssigneesFromSubtasks($parent);

                    $parent = $this->hierarchyService
                        ->syncTaskPriorityFromSubtasks($parent);

                    $parent = $this->hierarchyService
                        ->syncTaskStatusFromSubtasks(
                            $parent,
                            auth()->id(),
                        );
                }

                $parent->loadMissing('assignees');

                $this->syncTodayDailyReportItems(
                    $parent,
                );
            }
        }

        $this->dailyReportSync->syncDateForUsers(
            now()->toDateString(),
            $affectedUserIds,
        );

        return back();
    }

    public function toggleAssignee(
        Request $request,
        ProjectTask $projectTask,
    ) {
        return DB::transaction(function () use ($request, $projectTask) {
            $this->authorize('update', $projectTask);

            if ($projectTask->subtasks()->exists()) {
                throw ValidationException::withMessages([
                    'assignees' => [
                        'Assignee parent dihitung otomatis dari gabungan assignee seluruh subtask.',
                    ],
                ]);
            }

            ProjectTask::query()->whereKey($projectTask->id)->lockForUpdate()->first();

            $validated = $request->validate([
                'user_id' => [
                    'required',
                    Rule::exists('project_members', 'user_id')->where('project_id', $projectTask->project_id),
                ],
                'checked' => [
                    'required',
                    'boolean',
                ],
            ]);

            $userId = (int) $validated['user_id'];

            if ($validated['checked']) {
                $projectTask
                    ->assignees()
                    ->syncWithoutDetaching([$userId]);

                ActivityLogger::log(
                    'task_assigned',
                    'Assigned to '.$projectTask->title,
                    $projectTask,
                    [
                        'task_id' => $projectTask->id,
                        'task_title' => $projectTask->title,
                        'project_id' => $projectTask->project_id,
                    ],
                    $userId,
                );
            } else {
                $projectTask
                    ->assignees()
                    ->detach($userId);

                ActivityLogger::log(
                    'task_unassigned',
                    'Removed from '.$projectTask->title,
                    $projectTask,
                    [
                        'task_id' => $projectTask->id,
                        'task_title' => $projectTask->title,
                        'project_id' => $projectTask->project_id,
                    ],
                    $userId,
                );
            }

            $projectTask
                ->refresh()
                ->load([
                    'assignees',
                    'parent',
                ]);

            $parent = null;

            if ($projectTask->parent_id) {
                $parent = $this->hierarchyService
                    ->syncParentAssignees(
                        $projectTask,
                    );
            }

            if ($parent) {
                $parent->loadMissing('assignees');

                $this->syncTodayDailyReportItems(
                    $parent,
                );
            }

            $projectTask->load('assignees:id');
            $this->syncTodayDailyReportItems($projectTask, [$userId]);

            return back();
        });
    }
}
