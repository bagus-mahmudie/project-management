<?php

namespace App\Http\Controllers;

use App\Exports\DailyReportExport;
use App\Exports\DashboardExport;
use App\Exports\EventExport;
use App\Services\CsvExportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExportController extends Controller
{
    public function __construct(private readonly CsvExportService $csvExport) {}

    public function dashboard()
    {
        return $this->csvExport->download(
            new DashboardExport,
            'dashboard-summary-'.now()->format('Y-m-d').'.csv'
        );
    }

    public function dailyReport(Request $request)
    {
        $validated = $request->validate([
            'filter' => ['nullable', Rule::in(['day', 'month', 'year'])],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);
        $userId = (int) ($validated['user_id'] ?? auth()->id());
        abort_unless(
            auth()->user()->isSuperAdmin() || $userId === (int) auth()->id(),
            403,
        );
        $filter = $validated['filter'] ?? 'day';
        $date = $validated['date'] ?? now()->toDateString();

        return $this->csvExport->download(
            new DailyReportExport($userId, $filter, $date, $validated['search'] ?? null),
            'daily-report-'.$date.'.csv'
        );
    }

    public function events(Request $request)
    {
        $validated = $request->validate([
            'filter' => ['nullable', Rule::in(['day', 'month', 'year'])],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->csvExport->download(
            new EventExport(
                $validated['filter'] ?? 'month',
                $validated['date'] ?? now()->toDateString(),
                $validated['search'] ?? null,
            ),
            'events-'.now()->format('Y-m-d').'.csv'
        );
    }
}
