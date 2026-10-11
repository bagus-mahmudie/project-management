<?php

namespace App\Http\Controllers;

use App\Models\DailyReport;
use App\Models\DailyReportItem;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use App\Services\DailyReportCatchUpService;
use App\Services\DailyReportSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class DailyReportController extends Controller
{
    public function index(
        Request $request,
        DailyReportCatchUpService $catchUpService,
        DailyReportSyncService $syncService,
    ) {
        $context = $this->reportContext($request);
        $today = now(config('app.timezone'))->toDateString();

        // Tanggal hari ini harus langsung terlihat; backlog tetap diproses
        // berurutan oleh queue melalui cursor per user.
        $catchUpService->initializeUserCursor($today, $context['selectedUserId']);
        $syncService->syncDateForUser($today, $context['selectedUserId']);
        $catchUpService->queueUserThrough(
            $today,
            $context['selectedUserId'],
        );
        [$reports, $pagination] = $this->paginatedReports($context);

        return Inertia::render('DailyReport/Index', [
            'projects' => Project::latest()->get(),

            'users' => User::with('profile')
                ->select('id', 'name', 'email', 'role')
                ->where('role', '!=', 'super_admin')
                ->where('approval_status', 'approved')
                ->orderBy('name')
                ->get(),

            'reports' => $reports,
            'reportPagination' => $pagination,

            'filters' => [
                'filter' => $context['filter'],
                'date' => $context['date']->toDateString(),
                'user_id' => $context['selectedUserId'],
                'search' => $context['search'],
            ],
            'can' => [
                'export' => auth()->user()->isSuperAdmin() || $context['selectedUserId'] === (int) auth()->id(),
                'manage' => auth()->user()->isSuperAdmin() || $context['selectedUserId'] === (int) auth()->id(),
            ],
        ]);
    }

    public function more(Request $request)
    {
        $context = $this->reportContext($request);
        [$reports, $pagination] = $this->paginatedReports($context);

        return response()->json([
            'reports' => $reports,
            'pagination' => $pagination,
        ]);
    }

    private function reportContext(Request $request): array
    {
        $validatedQuery = $request->validate([
            'filter' => ['nullable', Rule::in(['day', 'month', 'year'])],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')
                    ->where('role', 'user')
                    ->where('approval_status', 'approved'),
            ],
            'search' => ['nullable', 'string', 'max:255'],
            'report_page' => ['nullable', 'integer', 'min:1'],
        ]);
        $filter = $validatedQuery['filter'] ?? 'day';
        $date = Carbon::createFromFormat('Y-m-d', $validatedQuery['date'] ?? now()->toDateString());
        $defaultUserId = auth()->user()->isSuperAdmin()
            ? (User::query()
                ->where('role', 'user')
                ->where('approval_status', 'approved')
                ->orderBy('name')
                ->value('id') ?? auth()->id())
            : auth()->id();

        return [
            'filter' => $filter,
            'date' => $date,
            'selectedUserId' => (int) ($validatedQuery['user_id'] ?? $defaultUserId),
            'search' => trim($validatedQuery['search'] ?? ''),
            'page' => (int) ($validatedQuery['report_page'] ?? 1),
        ];
    }

    private function paginatedReports(array $context): array
    {
        [$startDate, $endDate] = match ($context['filter']) {
            'month' => [
                $context['date']->copy()->startOfMonth(),
                $context['date']->copy()->endOfMonth(),
            ],
            'year' => [
                $context['date']->copy()->startOfYear(),
                $context['date']->copy()->endOfYear(),
            ],
            default => [
                $context['date']->copy()->startOfDay(),
                $context['date']->copy()->endOfDay(),
            ],
        };

        $search = $context['search'];
        $paginator = DailyReport::query()
            ->where('user_id', $context['selectedUserId'])
            ->whereBetween('report_date', [
                $startDate->toDateString(),
                $endDate->toDateString(),
            ])
            ->when($search !== '', fn ($query) => $query->whereHas(
                'items',
                fn ($items) => $this->applyItemSearch($items, $search),
            ))
            ->with($this->reportRelations())
            ->orderByDesc('report_date')
            ->simplePaginate(
                perPage: 3,
                pageName: 'report_page',
                page: $context['page'],
            );

        $reports = $paginator->getCollection();

        if ($search !== '') {
            $reports = $this->filterReportsBySearch($reports, $search);
        }

        return [
            $reports->values(),
            [
                'current_page' => $paginator->currentPage(),
                'has_more' => $paginator->hasMorePages(),
                'next_page' => $paginator->hasMorePages()
                    ? $paginator->currentPage() + 1
                    : null,
            ],
        ];
    }

    private function applyItemSearch($query, string $search): void
    {
        $like = "%{$search}%";

        $query->where(function ($match) use ($like) {
            $match
                ->where('title', 'like', $like)
                ->orWhere('project_name', 'like', $like)
                ->orWhere('snapshot_status', 'like', $like)
                ->orWhere('note', 'like', $like)
                ->orWhere('work_evidence_note', 'like', $like)
                ->orWhereHas('task', function ($task) use ($like) {
                    $task
                        ->where('title', 'like', $like)
                        ->orWhereHas('project', fn ($project) => $project->where('name', 'like', $like));
                });
        });
    }

    private function reportRelations(): array
    {
        return [
            'user.profile',
            'items' => fn ($query) => $query
                ->whereNull('parent_id')
                ->orderByRaw("CASE WHEN source = 'project' THEN 0 ELSE 1 END")
                ->orderBy('position')
                ->orderBy('id'),
            'items.files',
            'items.links',
            'items.subtasks' => fn ($query) => $query
                ->orderByRaw("CASE WHEN source = 'project' THEN 0 ELSE 1 END")
                ->orderBy('position')
                ->orderBy('id'),
            'items.subtasks.files',
            'items.subtasks.links',
            'items.subtasks.comments.user.profile',
            'items.task.project',
            'items.task.files',
            'items.task.links',
            'items.task.assignees.profile',
            'items.task.subtasks.files',
            'items.task.subtasks.links',
            'items.subtasks.task.project',
            'items.subtasks.task.files',
            'items.subtasks.task.links',
            'items.comments.user.profile',
        ];
    }

    private function filterReportsBySearch(
        $reports,
        string $search,
    ) {
        $keyword = mb_strtolower($search);

        return $reports
            ->map(function (DailyReport $report) use ($keyword) {
                $items = $report->items
                    ->filter(function (DailyReportItem $item) use ($keyword) {
                        $subtasks = $item->subtasks
                            ->filter(fn (DailyReportItem $subtask) => $this->dailyReportItemMatches(
                                $subtask,
                                $keyword,
                            ))
                            ->values();

                        if (
                            ! $this->dailyReportItemMatches($item, $keyword) &&
                            $subtasks->isEmpty()
                        ) {
                            return false;
                        }

                        $item->setRelation('subtasks', $subtasks);

                        return true;
                    })
                    ->values();

                $report->setRelation('items', $items);

                return $report;
            })
            ->filter(fn (DailyReport $report) => $report->items->isNotEmpty())
            ->values();
    }

    private function dailyReportItemMatches(
        DailyReportItem $item,
        string $keyword,
    ): bool {
        $title = $item->source === 'project'
            ? $item->task?->title
            : $item->title;

        $projectName = $item->task?->project?->name
            ?? $item->project_name;

        $status = str_replace(
            '_',
            ' ',
            (string) $item->snapshot_status,
        );

        return collect([
            $title,
            $projectName,
            $status,
            $item->note,
            $item->work_evidence_note,
        ])->contains(fn ($value) => str_contains(
            mb_strtolower((string) $value),
            $keyword,
        ));
    }

    public function storeItem(Request $request)
    {
        $validated = $request->validate([
            'report_date' => ['required', 'date_format:Y-m-d'],
            'user_id' => [
                'nullable',
                Rule::exists('users', 'id')
                    ->where('role', 'user')
                    ->where('approval_status', 'approved'),
            ],
            'parent_id' => [
                'nullable',
                'exists:daily_report_items,id',
            ],
            'title' => ['required', 'string', 'max:255'],
            'snapshot_status' => [
                'nullable',
                Rule::in([
                    'to_do',
                    'in_progress',
                    'pending',
                    'ready_to_check',
                    'need_revision',
                    'done',
                ]),
            ],
            'snapshot_start_date' => ['nullable', 'date_format:Y-m-d'],
            'snapshot_due_date' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:snapshot_start_date',
            ],
            'note' => ['nullable', 'string', 'max:10000'],
        ]);

        $userId = (int) (
            $validated['user_id'] ?? auth()->id()
        );

        /*
         * Apabila hanya admin yang boleh menambahkan untuk user lain,
         * tambahkan pengecekan role di sini.
         */
        if (
            $userId !== auth()->id() &&
            ! auth()->user()->isSuperAdmin()
        ) {
            abort(403);
        }

        DB::transaction(function () use ($validated, $userId) {
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();

            $report = DailyReport::query()
                ->where('user_id', $userId)
                ->whereDate('report_date', $validated['report_date'])
                ->first();

            $report ??= DailyReport::query()->firstOrCreate([
                'user_id' => $userId,
                'report_date' => $validated['report_date'],
            ]);

            $report = DailyReport::query()->whereKey($report->id)->lockForUpdate()->firstOrFail();
            $parent = null;

            if (! empty($validated['parent_id'])) {
                $parent = DailyReportItem::query()
                    ->whereKey($validated['parent_id'])
                    ->where('daily_report_id', $report->id)
                    ->where('source', 'manual')
                    ->whereNull('parent_id')
                    ->firstOrFail();
            }

            $position = DailyReportItem::query()
                ->where('daily_report_id', $report->id)
                ->where('parent_id', $parent?->id)
                ->max('position');

            $status = $validated['snapshot_status'] ?? 'to_do';
            $dueDate = $validated['snapshot_due_date'] ?? null;

            $report->items()->create([
                'parent_id' => $parent?->id,
                'source' => 'manual',
                'position' => ($position ?? 0) + 1,
                'title' => $validated['title'],
                'is_subtask' => (bool) $parent,
                'snapshot_status' => $status,
                'snapshot_start_date' => $validated['snapshot_start_date'] ?? null,
                'snapshot_due_date' => $dueDate,
                'note' => $validated['note'] ?? null,
                'is_overdue' => $dueDate
                    && Carbon::parse($dueDate)->lt($report->report_date)
                    && $status !== ProjectTask::STATUS_DONE,
                'is_final_status_snapshot' => false,
            ]);
        });

        return back();
    }

    public function updateItem(
        Request $request,
        DailyReportItem $dailyReportItem,
    ) {
        $dailyReportItem->loadMissing('report');

        $isOwner =
            $dailyReportItem->report->user_id === auth()->id();

        $isAdmin = auth()->user()->isSuperAdmin();

        $validated = $request->validate([
            'title' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],
            'snapshot_status' => [
                'sometimes',
                'nullable',
                Rule::in([
                    'to_do',
                    'in_progress',
                    'pending',
                    'ready_to_check',
                    'need_revision',
                    'done',
                ]),
            ],
            'snapshot_start_date' => [
                'sometimes',
                'nullable',
                'date_format:Y-m-d',
            ],
            'snapshot_due_date' => [
                'sometimes',
                'nullable',
                'date_format:Y-m-d',
            ],
            'note' => [
                'sometimes',
                'nullable',
                'string',
                'max:10000',
            ],
            'position' => [
                'sometimes',
                'integer',
                'min:0',
            ],
        ]);

        if (! $isOwner && ! $isAdmin) {
            abort_if(
                collect($validated)->keys()->contains(fn ($key) => $key !== 'note'),
                403,
            );

            $dailyReportItem->update(
                collect($validated)->only(['note'])->toArray(),
            );

            return back();
        }

        $effectiveStartDate = array_key_exists('snapshot_start_date', $validated)
            ? $validated['snapshot_start_date']
            : $dailyReportItem->snapshot_start_date?->toDateString();
        $effectiveDueDate = array_key_exists('snapshot_due_date', $validated)
            ? $validated['snapshot_due_date']
            : $dailyReportItem->snapshot_due_date?->toDateString();

        if (
            $effectiveStartDate &&
            $effectiveDueDate &&
            Carbon::parse($effectiveDueDate)->lt(Carbon::parse($effectiveStartDate))
        ) {
            throw ValidationException::withMessages([
                'snapshot_due_date' => ['Deadline tidak boleh lebih awal dari tanggal mulai.'],
            ]);
        }

        /*
         * Project item hanya boleh mengubah note.
         */
        if ($dailyReportItem->source === 'project') {
            $dailyReportItem->update(
                collect($validated)
                    ->only(['note'])
                    ->toArray(),
            );

            return back();
        }

        $effectiveStatus = array_key_exists('snapshot_status', $validated)
            ? $validated['snapshot_status']
            : $dailyReportItem->snapshot_status;

        $validated['is_overdue'] = $effectiveDueDate
            && Carbon::parse($effectiveDueDate)->lt($dailyReportItem->report->report_date)
            && $effectiveStatus !== ProjectTask::STATUS_DONE;

        $dailyReportItem->update($validated);

        return back();
    }

    public function destroyItem(
        DailyReportItem $dailyReportItem,
    ) {
        $dailyReportItem->loadMissing('report');

        abort_if(
            $dailyReportItem->source === 'project',
            422,
            'Automatic project items cannot be deleted.',
        );

        abort_unless(
            $dailyReportItem->report->user_id === auth()->id() ||
            auth()->user()->isSuperAdmin(),
            403,
        );

        $dailyReportItem->delete();

        return back();
    }
}
