<?php

namespace App\Exports;

use App\Models\ProjectTask;

class DashboardExport
{
    public function headings(): array
    {
        return [
            'Metric',
            'Value',
        ];
    }

    public function array(): array
    {
        $totalTasks = ProjectTask::count();

        $inProgress = ProjectTask::where('status', ProjectTask::STATUS_IN_PROGRESS)->count();

        $overdue = ProjectTask::where('status', '!=', ProjectTask::STATUS_DONE)
            ->whereDate('due_date', '<', now()->toDateString())
            ->count();

        $done = ProjectTask::where('status', ProjectTask::STATUS_DONE)->count();

        $completion = $totalTasks > 0
            ? round(($done / $totalTasks) * 100, 1)
            : 0;

        return [
            ['Total Task', $totalTasks],
            ['In Progress', $inProgress],
            ['Overdue', $overdue],
            ['Completion', $completion.'%'],
        ];
    }
}
