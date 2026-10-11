<?php

namespace App\Services;

use App\Models\ProjectTask;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProjectTaskHierarchyService
{
    /**
     * Hitung ulang status parent berdasarkan seluruh subtask.
     */
    public function syncParentStatus(
        ProjectTask $subtask,
        ?int $changedBy = null,
    ): ?ProjectTask {
        if (! $subtask->parent_id) {
            return null;
        }

        $parent = ProjectTask::query()
            ->with('subtasks:id,parent_id,status')
            ->find($subtask->parent_id);

        if (! $parent) {
            return null;
        }

        return $this->syncTaskStatusFromSubtasks(
            $parent,
            $changedBy,
        );
    }

    /**
     * Hitung status task dari seluruh subtask-nya.
     */
    public function syncTaskStatusFromSubtasks(
        ProjectTask $parent,
        ?int $changedBy = null,
    ): ProjectTask {
        $statuses = $parent
            ->subtasks()
            ->pluck('status');

        /*
         * Jika sudah tidak mempunyai subtask,
         * status parent kembali menjadi manual.
         * Jangan ubah status terakhirnya secara otomatis.
         */
        if ($statuses->isEmpty()) {
            return $parent->refresh();
        }

        $nextStatus = $this->calculateParentStatus(
            $statuses,
        );

        if ($parent->status === $nextStatus) {
            return $parent->refresh();
        }

        $oldStatus = $parent->status;
        $changedAt = now();

        DB::transaction(function () use (
            $parent,
            $oldStatus,
            $nextStatus,
            $changedAt,
            $changedBy,
        ) {
            $parent->update([
                'status' => $nextStatus,
                'status_changed_at' => $changedAt,
            ]);

            /*
             * Simpan histori perubahan status parent.
             * Relasi statusHistories sebelumnya sudah dibuat.
             */
            $parent->statusHistories()->create([
                'changed_by' => $changedBy,
                'from_status' => $oldStatus,
                'to_status' => $nextStatus,
                'changed_at' => $changedAt,
            ]);
        });

        return $parent
            ->refresh()
            ->load([
                'assignees',
                'subtasks',
            ]);
    }

    /**
     * Tentukan status parent dari kumpulan status subtask.
     */
    public function calculateParentStatus(
        Collection $statuses,
    ): string {
        /*
         * Semua selesai berarti parent selesai.
         */
        if (
            $statuses->every(
                fn (string $status) => $status === ProjectTask::STATUS_DONE
            )
        ) {
            return ProjectTask::STATUS_DONE;
        }

        /*
         * Need Revision mempunyai prioritas paling tinggi.
         */
        if (
            $statuses->contains(
                ProjectTask::STATUS_NEED_REVISION,
            )
        ) {
            return ProjectTask::STATUS_NEED_REVISION;
        }

        /*
         * Jika ada subtask yang sedang dikerjakan,
         * parent pasti sedang dikerjakan.
         */
        if (
            $statuses->contains(
                ProjectTask::STATUS_IN_PROGRESS,
            )
        ) {
            return ProjectTask::STATUS_IN_PROGRESS;
        }

        /*
         * Jika tidak ada yang sedang dikerjakan,
         * tetapi ada yang tertunda, parent ikut pending.
         */
        if (
            $statuses->contains(
                ProjectTask::STATUS_PENDING,
            )
        ) {
            return ProjectTask::STATUS_PENDING;
        }

        /*
         * Parent Ready to Check hanya jika seluruh subtask
         * sudah Ready to Check atau Done.
         */
        $allReadyOrDone = $statuses->every(
            fn (string $status) => in_array(
                $status,
                [
                    ProjectTask::STATUS_READY_TO_CHECK,
                    ProjectTask::STATUS_DONE,
                ],
                true,
            )
        );

        if (
            $allReadyOrDone &&
            $statuses->contains(
                ProjectTask::STATUS_READY_TO_CHECK,
            )
        ) {
            return ProjectTask::STATUS_READY_TO_CHECK;
        }

        /*
         * Jika sebagian sudah selesai atau ready,
         * tetapi sebagian lainnya masih To Do,
         * berarti pekerjaan sudah dimulai.
         */
        if (
            $statuses->contains(
                ProjectTask::STATUS_DONE,
            ) ||
            $statuses->contains(
                ProjectTask::STATUS_READY_TO_CHECK,
            )
        ) {
            return ProjectTask::STATUS_IN_PROGRESS;
        }

        return ProjectTask::STATUS_TO_DO;
    }

    /**
     * Jadikan assignee parent sebagai gabungan unik assignee seluruh subtask.
     */
    public function syncParentAssignees(
        ProjectTask $subtask,
    ): ?ProjectTask {
        if (! $subtask->parent_id) {
            return null;
        }

        $parent = ProjectTask::query()
            ->find($subtask->parent_id);

        if (! $parent) {
            return null;
        }

        return $this->syncTaskAssigneesFromSubtasks($parent);
    }

    public function syncTaskAssigneesFromSubtasks(ProjectTask $parent): ProjectTask
    {
        return DB::transaction(function () use ($parent) {
            ProjectTask::query()->whereKey($parent->id)->lockForUpdate()->firstOrFail();

            $assigneeIds = DB::table('task_assignees')
                ->join('project_tasks', 'project_tasks.id', '=', 'task_assignees.project_task_id')
                ->where('project_tasks.parent_id', $parent->id)
                ->distinct()
                ->lockForUpdate()
                ->pluck('task_assignees.user_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $parent->assignees()->sync($assigneeIds);

            return $parent->refresh()->load('assignees');
        });
    }

    public function syncTaskPriorityFromSubtasks(ProjectTask $parent): ProjectTask
    {
        return DB::transaction(function () use ($parent) {
            ProjectTask::query()->whereKey($parent->id)->lockForUpdate()->firstOrFail();

            $highestPriority = (int) $parent->subtasks()->max('priority');

            if ((int) $parent->priority !== $highestPriority) {
                $parent->update(['priority' => $highestPriority]);
            }

            return $parent->refresh();
        });
    }

    /**
     * Sinkronkan status dan assignee parent sekaligus.
     */
    public function syncParentFromSubtask(
        ProjectTask $subtask,
        ?int $changedBy = null,
    ): ?ProjectTask {
        if (! $subtask->parent_id) {
            return null;
        }

        $this->syncParentAssignees($subtask);

        $parent = ProjectTask::query()->find($subtask->parent_id);

        if ($parent) {
            $this->syncTaskPriorityFromSubtasks($parent);
        }

        return $this->syncParentStatus(
            $subtask,
            $changedBy,
        );
    }
}
