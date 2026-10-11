<?php

namespace App\Services;

use App\Models\ProjectTask;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

class DashboardAnalyticsService
{
    private const CACHE_KEY = 'dashboard.analytics.v2';

    public function get(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addMinutes(5), fn () => $this->calculate());
    }

    private function calculate(): array
    {
        $now = now();
        $statCutoffs = [
            'current' => $now->copy(),
            'previous' => $now->copy()->subWeek(),
        ];
        $statCounts = [
            'current' => $this->emptySnapshot(),
            'previous' => $this->emptySnapshot(),
        ];

        $overduePoints = [
            'day' => collect(range(6, 0))->map(fn (int $offset) => $this->point(
                $now->copy()->startOfDay()->subDays($offset)->endOfDay(),
                $now->copy()->subDays($offset)->format('d M'),
            ))->all(),
            'month' => collect(range(11, 0))->map(fn (int $offset) => $this->point(
                $now->copy()->subMonthsNoOverflow($offset)->endOfMonth(),
                $now->copy()->subMonthsNoOverflow($offset)->format('M Y'),
            ))->all(),
            'year' => collect(range(4, 0))->map(fn (int $offset) => $this->point(
                $now->copy()->subYears($offset)->endOfYear(),
                $now->copy()->subYears($offset)->format('Y'),
            ))->all(),
        ];

        $completionPoints = [
            'day' => collect(range(10, 19))->map(fn (int $hour) => [
                'label' => sprintf('%02d:00', $hour),
                'start' => $now->copy()->setTime($hour, 0),
                'end' => $now->copy()->setTime($hour, 0)->endOfHour(),
                'completed' => 0,
            ])->all(),
            'month' => collect(range(9, 0))->map(fn (int $offset) => [
                'label' => $now->copy()->subDays($offset)->format('d M'),
                'date' => $now->copy()->subDays($offset)->toDateString(),
                'completed' => 0,
            ])->all(),
            'year' => collect(range(5, 0))->map(fn (int $offset) => [
                'label' => $now->copy()->subMonthsNoOverflow($offset)->format('M Y'),
                'month' => $now->copy()->subMonthsNoOverflow($offset)->format('Y-m'),
                'completed' => 0,
            ])->all(),
        ];

        ProjectTask::query()
            ->select(['id', 'status', 'due_date', 'status_changed_at', 'created_at', 'updated_at'])
            ->with('statusHistories:id,project_task_id,from_status,to_status,changed_at')
            ->chunkById(500, function ($tasks) use (
                $statCutoffs,
                &$statCounts,
                &$overduePoints,
                &$completionPoints,
            ) {
                foreach ($tasks as $task) {
                    foreach ($statCutoffs as $key => $cutoff) {
                        $this->accumulateSnapshot($statCounts[$key], $task, $cutoff);
                    }

                    foreach ($overduePoints as &$points) {
                        foreach ($points as &$point) {
                            if ($this->isOverdueAt($task, $point['cutoff'])) {
                                $point['count']++;
                            }
                        }
                        unset($point);
                    }
                    unset($points);

                    $doneAt = $this->doneAt($task);

                    if (! $doneAt) {
                        continue;
                    }

                    foreach ($completionPoints['day'] as &$point) {
                        if ($doneAt->betweenIncluded($point['start'], $point['end'])) {
                            $point['completed']++;
                        }
                    }
                    unset($point);

                    foreach ($completionPoints['month'] as &$point) {
                        if ($doneAt->toDateString() === $point['date']) {
                            $point['completed']++;
                        }
                    }
                    unset($point);

                    foreach ($completionPoints['year'] as &$point) {
                        if ($doneAt->format('Y-m') === $point['month']) {
                            $point['completed']++;
                        }
                    }
                    unset($point);
                }
            });

        return [
            'stats' => $this->formatStats($statCounts),
            'overdueCharts' => collect($overduePoints)->map(fn ($points) => collect($points)
                ->map(fn ($point) => ['label' => $point['label'], 'count' => $point['count']])
                ->all())->all(),
            'completionCharts' => collect($completionPoints)->map(fn ($points) => collect($points)
                ->map(fn ($point) => ['label' => $point['label'], 'completed' => $point['completed']])
                ->all())->all(),
        ];
    }

    private function point(CarbonInterface $cutoff, string $label): array
    {
        return ['cutoff' => $cutoff, 'label' => $label, 'count' => 0];
    }

    private function emptySnapshot(): array
    {
        return ['total_tasks' => 0, 'in_progress' => 0, 'overdue' => 0, 'done' => 0];
    }

    private function accumulateSnapshot(array &$snapshot, ProjectTask $task, CarbonInterface $cutoff): void
    {
        if (! $task->created_at?->lte($cutoff)) {
            return;
        }

        $snapshot['total_tasks']++;
        $status = $this->statusAt($task, $cutoff);
        $snapshot['in_progress'] += (int) ($status === ProjectTask::STATUS_IN_PROGRESS);
        $snapshot['done'] += (int) ($status === ProjectTask::STATUS_DONE);
        $snapshot['overdue'] += (int) $this->isOverdueAt($task, $cutoff, $status);
    }

    private function isOverdueAt(
        ProjectTask $task,
        CarbonInterface $cutoff,
        ?string $status = null,
    ): bool {
        if (
            ! $task->created_at?->lte($cutoff)
            || ! $task->due_date
            || $task->due_date->gte($cutoff->copy()->startOfDay())
        ) {
            return false;
        }

        return ($status ?? $this->statusAt($task, $cutoff)) !== ProjectTask::STATUS_DONE;
    }

    private function formatStats(array $snapshots): array
    {
        foreach ($snapshots as &$snapshot) {
            $snapshot['completion'] = $snapshot['total_tasks'] > 0
                ? round(($snapshot['done'] / $snapshot['total_tasks']) * 100, 1)
                : 0;
        }
        unset($snapshot);

        return [
            'total_tasks' => $this->statPayload($snapshots['current']['total_tasks'], $snapshots['previous']['total_tasks']),
            'in_progress' => $this->statPayload($snapshots['current']['in_progress'], $snapshots['previous']['in_progress']),
            'overdue' => $this->statPayload($snapshots['current']['overdue'], $snapshots['previous']['overdue']),
            'completion' => $this->statPayload($snapshots['current']['completion'], $snapshots['previous']['completion'], true),
        ];
    }

    private function statPayload(int|float $value, int|float $previous, bool $points = false): array
    {
        $change = $points
            ? round($value - $previous, 1)
            : ((float) $previous === 0.0
                ? ((float) $value === 0.0 ? 0 : 100)
                : round((($value - $previous) / abs($previous)) * 100, 1));

        return [
            'value' => $value,
            'previous_value' => $previous,
            'change' => $change,
            'change_type' => $points ? 'point' : 'percent',
        ];
    }

    private function doneAt(ProjectTask $task): ?Carbon
    {
        $history = $task->statusHistories
            ->where('to_status', ProjectTask::STATUS_DONE)
            ->sortByDesc('changed_at')
            ->first();

        if ($history?->changed_at) {
            return Carbon::parse($history->changed_at);
        }

        return $task->status === ProjectTask::STATUS_DONE
            ? ($task->status_changed_at ?? $task->updated_at)
            : null;
    }

    private function statusAt(ProjectTask $task, CarbonInterface $cutoff): ?string
    {
        $histories = $task->statusHistories;
        $last = $histories->filter(fn ($history) => $history->changed_at?->lte($cutoff))->last();

        if ($last) {
            return $last->to_status;
        }

        $firstAfter = $histories->first(fn ($history) => $history->changed_at?->gt($cutoff));

        if ($firstAfter && $task->created_at?->lte($cutoff)) {
            return $firstAfter->from_status ?? $task->status;
        }

        return $task->created_at?->lte($cutoff) ? $task->status : null;
    }
}
