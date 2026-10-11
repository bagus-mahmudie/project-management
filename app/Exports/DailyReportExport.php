<?php

namespace App\Exports;

use App\Exports\Concerns\SanitizesSpreadsheetCells;
use App\Models\DailyReport;
use Illuminate\Support\Carbon;

class DailyReportExport
{
    use SanitizesSpreadsheetCells;

    public function __construct(
        private readonly int $userId,
        private readonly string $filter,
        private readonly string $date,
        private readonly ?string $search = null,
    ) {}

    public function headings(): array
    {
        return [
            'Date',
            'Employee',
            'Task',
            'Source',
            'Status',
            'Start Date',
            'Due Date',
            'Work Evidence',
            'Note',
            'Overdue',
        ];
    }

    public function array(): array
    {
        $date = Carbon::createFromFormat('Y-m-d', $this->date);
        [$startDate, $endDate] = match ($this->filter) {
            'month' => [$date->copy()->startOfMonth(), $date->copy()->endOfMonth()],
            'year' => [$date->copy()->startOfYear(), $date->copy()->endOfYear()],
            default => [$date->copy()->startOfDay(), $date->copy()->endOfDay()],
        };

        $reports = DailyReport::query()
            ->where('user_id', $this->userId)
            ->whereDate('report_date', '>=', $startDate->toDateString())
            ->whereDate('report_date', '<=', $endDate->toDateString())
            ->with(['user.profile', 'items.task.project'])
            ->latest()
            ->get();

        $rows = [];

        foreach ($reports as $report) {
            foreach ($report->items as $item) {
                if ($this->search && ! $this->matchesSearch($item)) {
                    continue;
                }

                $rows[] = $this->sanitizeSpreadsheetRow([
                    $report->report_date?->format('Y-m-d'),
                    $report->user?->name,
                    $item->source === 'project'
                        ? ($item->task?->title ?? $item->title)
                        : $item->title,
                    $item->source,
                    $item->snapshot_status,
                    $item->snapshot_start_date?->format('Y-m-d'),
                    $item->snapshot_due_date?->format('Y-m-d'),
                    $item->work_evidence_note,
                    $item->note,
                    $item->is_overdue ? 'Yes' : 'No',
                ]);
            }
        }

        return $rows;
    }

    private function matchesSearch($item): bool
    {
        $keyword = mb_strtolower(trim($this->search));

        return collect([
            $item->source === 'project'
                ? ($item->task?->title ?? $item->title)
                : $item->title,
            $item->project_name,
            $item->task?->project?->name,
            str_replace('_', ' ', (string) $item->snapshot_status),
            $item->note,
            $item->work_evidence_note,
        ])->contains(fn ($value) => str_contains(mb_strtolower((string) $value), $keyword));
    }
}
