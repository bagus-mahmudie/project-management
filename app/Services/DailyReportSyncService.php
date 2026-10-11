<?php

namespace App\Services;

use App\Models\DailyReport;
use App\Models\DailyReportItem;
use App\Models\ProjectTask;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DailyReportSyncService
{
    public function syncDateForUser(
        string $date,
        int $userId,
    ): DailyReport {
        return DB::transaction(function () use ($date, $userId) {
            $day = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
            $date = $day->toDateString();
            $dayEnd = $day->copy()->endOfDay();

            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();

            $report = DailyReport::query()
                ->where('user_id', $userId)
                ->whereDate('report_date', $date)
                ->first();

            $report ??= DailyReport::query()->firstOrCreate([
                'user_id' => $userId,
                'report_date' => $date,
            ]);

            $tasks = ProjectTask::query()
                ->whereHas('assignees', function ($query) use ($userId) {
                    $query->where('users.id', $userId);
                })
                ->where('created_at', '<=', $dayEnd)
                ->where(function ($query) use ($day, $dayEnd) {
                    $query
                        ->whereBetween('created_at', [$day, $dayEnd])
                        ->orWhereHas('statusHistories', function ($historyQuery) use ($day, $dayEnd) {
                            $historyQuery->whereBetween('changed_at', [$day, $dayEnd]);
                        })
                        ->orWhereRaw(
                            '(SELECT project_task_status_histories.to_status FROM project_task_status_histories WHERE project_task_status_histories.project_task_id = project_tasks.id AND project_task_status_histories.changed_at <= ? ORDER BY project_task_status_histories.changed_at DESC, project_task_status_histories.id DESC LIMIT 1) = ?',
                            [$dayEnd, ProjectTask::STATUS_IN_PROGRESS],
                        )
                        ->orWhere(function ($legacyQuery) {
                            $legacyQuery
                                ->where('status', ProjectTask::STATUS_IN_PROGRESS)
                                ->whereDoesntHave('statusHistories');
                        });
                })
                ->with([
                    'project:id,name',
                    'parent:id,title',
                    'statusHistories' => fn ($query) => $query
                        ->whereRaw(
                            'project_task_status_histories.id = (SELECT relevant_history.id FROM project_task_status_histories AS relevant_history WHERE relevant_history.project_task_id = project_task_status_histories.project_task_id AND relevant_history.changed_at <= ? ORDER BY relevant_history.changed_at DESC, relevant_history.id DESC LIMIT 1)',
                            [$dayEnd],
                        ),
                ])
                ->orderByRaw('parent_id IS NOT NULL')
                ->orderBy('position')
                ->get();

            $validProjectTaskIds = [];
            $itemIdsByTask = [];

            foreach ($tasks as $task) {
                if (! $task->shouldAppearInDailyReportOn($date)) {
                    continue;
                }

                $statusOnDate = $task->statusOnDate($date);

                if (! $statusOnDate) {
                    continue;
                }

                $validProjectTaskIds[] = $task->id;

                $parentItemId = null;

                if ($task->parent_id) {
                    $parentItemId = $itemIdsByTask[$task->parent_id] ?? null;
                }

                $item = DailyReportItem::updateOrCreate(
                    [
                        'daily_report_id' => $report->id,
                        'project_task_id' => $task->id,
                    ],
                    [
                        'parent_id' => $parentItemId,
                        'source' => 'project',
                        'position' => $task->position,
                        'title' => $task->title,
                        'project_name' => $task->project?->name,
                        'is_subtask' => (bool) $task->parent_id,

                        /*
                        * Status khusus untuk tanggal report.
                        * Bukan status Project terbaru.
                        */
                        'snapshot_status' => $statusOnDate,

                        'snapshot_start_date' => $task->start_date,

                        'snapshot_due_date' => $task->due_date,

                        'is_final_status_snapshot' => $statusOnDate !==
                            ProjectTask::STATUS_IN_PROGRESS,

                        'is_overdue' => $task->due_date &&
                            Carbon::parse(
                                $task->due_date,
                            )->lt($date) &&
                            $statusOnDate !== ProjectTask::STATUS_DONE,
                    ],
                );

                $itemIdsByTask[$task->id] = $item->id;
            }

            /*
             * Hapus hanya item otomatis yang tidak seharusnya tampil.
             * Item manual tidak pernah ikut terhapus.
             */
            $query = DailyReportItem::query()
                ->where('daily_report_id', $report->id)
                ->where('source', 'project');

            if ($validProjectTaskIds) {
                $query->whereNotIn(
                    'project_task_id',
                    $validProjectTaskIds,
                );
            }

            $query->delete();

            return $report;
        });
    }

    public function syncDateForUsers(string $date, iterable $userIds): void
    {
        collect($userIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->each(fn (int $userId) => $this->syncDateForUser($date, $userId));
    }

    public function syncRangeForUser(
        string $startDate,
        string $endDate,
        int $userId,
    ): void {
        $cursor = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);

        while ($cursor->lte($end)) {
            $this->syncDateForUser(
                $cursor->toDateString(),
                $userId,
            );

            $cursor->addDay();
        }
    }

    public function syncDateForAllUsers(string $date): void
    {
        User::query()
            ->select('id')
            ->where('role', 'user')
            ->where('approval_status', 'approved')
            ->chunkById(100, function ($users) use ($date) {
                foreach ($users as $user) {
                    $this->syncDateForUser($date, $user->id);
                }
            });
    }
}
