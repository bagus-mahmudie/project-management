<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use App\Services\DashboardAnalyticsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request, DashboardAnalyticsService $analyticsService)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'schedule_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $search = trim($validated['search'] ?? '');
        $selectedDate = Carbon::createFromFormat(
            'Y-m-d',
            $validated['schedule_date'] ?? now()->toDateString(),
        )->startOfDay();
        $analytics = $analyticsService->get();

        return Inertia::render('Dashboard/Index', [
            'projects' => Project::query()
                ->select(['id', 'name', 'slug', 'logo', 'logo_icon', 'logo_color', 'archived_at', 'created_at'])
                ->latest()
                ->get(),
            'users' => User::query()
                ->with('profile')
                ->select('id', 'name', 'email', 'role')
                ->where('role', 'user')
                ->where('approval_status', 'approved')
                ->latest()
                ->get(),
            'stats' => $analytics['stats'],
            'overdueCharts' => $analytics['overdueCharts'],
            'completionCharts' => $analytics['completionCharts'],
            'schedule' => $this->buildSchedule($selectedDate, $search),
            'filters' => ['search' => $search],
        ]);
    }

    private function buildSchedule(Carbon $selectedDate, string $search = ''): array
    {
        $centerDate = $selectedDate->copy()->startOfDay();
        $dates = collect(range(-2, 2))->map(function (int $offset) use ($centerDate) {
            $date = $centerDate->copy()->addDays($offset);

            return [
                'date' => $date->toDateString(),
                'day' => $date->format('D'),
                'day_number' => $date->format('d'),
                'offset' => $offset,
            ];
        });

        $firstDate = Carbon::parse($dates->first()['date'])->startOfDay();
        $lastDate = Carbon::parse($dates->last()['date'])->endOfDay();

        $tasks = ProjectTask::query()
            ->whereHas('assignees', fn ($query) => $query->whereKey(auth()->id()))
            ->where(function ($query) use ($firstDate, $lastDate) {
                $query->whereBetween('start_date', [
                    $firstDate->toDateString(),
                    $lastDate->toDateString(),
                ])->orWhere(function ($query) use ($firstDate, $lastDate) {
                    $query
                        ->whereNull('start_date')
                        ->whereBetween('due_date', [
                            $firstDate->toDateString(),
                            $lastDate->toDateString(),
                        ]);
                })->orWhere(function ($query) use ($firstDate, $lastDate) {
                    $query
                        ->whereNull('start_date')
                        ->whereNull('due_date')
                        ->whereBetween('created_at', [$firstDate, $lastDate]);
                });
            })
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($searchQuery) use ($search) {
                    $searchQuery
                        ->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhereHas(
                            'project',
                            fn ($projectQuery) => $projectQuery->where('name', 'like', "%{$search}%"),
                        )
                        ->orWhereHas(
                            'assignees',
                            fn ($assigneeQuery) => $assigneeQuery->where('users.name', 'like', "%{$search}%"),
                        );
                });
            })
            ->with([
                'project:id,name',
                'assignees:id,name',
                'assignees.profile',
            ])
            ->latest('created_at')
            ->get()
            ->groupBy(fn (ProjectTask $task) => $task->start_date?->toDateString()
                ?? $task->due_date?->toDateString()
                ?? $task->created_at?->toDateString())
            ->map(function ($items) {
                $statusOrder = [
                    ProjectTask::STATUS_IN_PROGRESS => 1,
                    ProjectTask::STATUS_TO_DO => 2,
                    ProjectTask::STATUS_PENDING => 3,
                    ProjectTask::STATUS_NEED_REVISION => 4,
                    ProjectTask::STATUS_READY_TO_CHECK => 5,
                    ProjectTask::STATUS_DONE => 6,
                ];

                return $items
                    ->sort(function (ProjectTask $firstTask, ProjectTask $secondTask) use ($statusOrder) {
                        $rankComparison = ($statusOrder[$firstTask->status] ?? 99)
                            <=> ($statusOrder[$secondTask->status] ?? 99);

                        return $rankComparison !== 0
                            ? $rankComparison
                            : ($secondTask->created_at?->timestamp ?? 0)
                                <=> ($firstTask->created_at?->timestamp ?? 0);
                    })
                    ->values();
            })
            ->all();

        return [
            'selected_date' => $selectedDate->toDateString(),
            'dates' => $dates->values()->all(),
            'tasks_by_date' => $tasks,
        ];
    }
}
