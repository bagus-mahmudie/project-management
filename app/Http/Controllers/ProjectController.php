<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\ProjectTask;
use App\Models\User;
use App\Services\DailyReportSyncService;
use App\Services\PublicUploadService;
use App\Services\TaskTimelineValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Throwable;

class ProjectController extends Controller
{
    public function __construct(
        private readonly DailyReportSyncService $dailyReportSync,
        private readonly TaskTimelineValidationService $timelineValidation,
    ) {}

    public function index()
    {
        return redirect()->route('dashboard');
    }

    public function store(Request $request)
    {
        $this->authorize('create', Project::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'logo_icon' => ['nullable', 'string', 'max:100'],
            'logo_color' => ['nullable', 'string', 'max:20'],
            'member_ids' => ['nullable', 'array', 'max:100'],
            'member_ids.*' => ['distinct', Rule::exists('users', 'id')
                ->where('role', 'user')
                ->where('approval_status', 'approved')],
        ], [
            'end_date.after_or_equal' => 'End date tidak boleh lebih awal dari start date.',
        ]);

        return DB::transaction(function () use ($validated) {
            $project = Project::create([
                'created_by' => auth()->id(),
                'name' => $validated['name'],
                'slug' => $this->uniqueSlug($validated['name']),
                'description' => $validated['description'] ?? null,
                'start_date' => $validated['start_date'] ?? null,
                'end_date' => $validated['end_date'] ?? null,
                'logo_icon' => $validated['logo_icon'] ?? 'bi-kanban',
                'logo_color' => $validated['logo_color'] ?? '#fff433',
            ]);

            $project->members()->attach(auth()->id(), ['role' => 'owner']);

            foreach ($validated['member_ids'] ?? [] as $memberId) {
                if ((int) $memberId !== auth()->id()) {
                    $project->members()->syncWithoutDetaching([
                        $memberId => ['role' => 'member'],
                    ]);
                }
            }

            return redirect()->route('projects.show', $project);
        });
    }

    private function uniqueSlug(string $name, ?Project $project = null): string
    {
        $baseSlug = Str::slug($name);
        $slug = $baseSlug;
        $counter = 1;

        while (
            Project::where('slug', $slug)
                ->when($project, fn ($query) => $query->whereKeyNot($project->id))
                ->exists()
        ) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    public function show(Project $project)
    {
        $this->authorize('view', $project);

        $project->load('members.profile');
        $groups = $this->groupsPage($project, 1);
        $project->setRelation('groups', collect($groups['data']));
        $project->setAttribute(
            'task_overview',
            $project->tasks()
                ->whereNull('parent_id')
                ->select([
                    'id',
                    'project_id',
                    'project_group_id',
                    'status',
                    'priority',
                    'start_date',
                    'due_date',
                    'created_at',
                ])
                ->get(),
        );
        $project->setAttribute('assignee_overview', $this->assigneeOverview($project));

        return Inertia::render('Project/Show', [
            'projects' => Project::query()
                ->select(['id', 'name', 'slug', 'logo', 'logo_icon', 'logo_color', 'archived_at', 'created_at'])
                ->latest()
                ->get(),
            'project' => $project,
            'groupPagination' => $groups['pagination'],
            'users' => User::with('profile:id,user_id,avatar,phone,position')
                ->select('id', 'name', 'email', 'role')
                ->where('role', 'user')
                ->where('approval_status', 'approved')
                ->latest()
                ->get(),
            'can' => [
                'update_project' => auth()->user()->can('update', $project),
                'delete_project' => auth()->user()->can('delete', $project),
            ],
        ]);
    }

    public function moreGroups(Request $request, Project $project)
    {
        $this->authorize('view', $project);

        $validated = $request->validate([
            'page' => ['required', 'integer', 'min:2'],
        ]);

        return response()->json($this->groupsPage($project, (int) $validated['page']));
    }

    private function groupsPage(Project $project, int $page): array
    {
        $paginator = $project->groups()
            ->reorder()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->simplePaginate(3, ['*'], 'page', $page);

        return [
            'data' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'has_more' => $paginator->hasMorePages(),
                'next_page' => $paginator->hasMorePages()
                    ? $paginator->currentPage() + 1
                    : null,
            ],
        ];
    }

    private function assigneeOverview(Project $project): array
    {
        $assigned = User::query()
            ->select(['users.id', 'users.name'])
            ->selectRaw('COUNT(DISTINCT project_tasks.id) AS task_count')
            ->join('task_assignees', 'task_assignees.user_id', '=', 'users.id')
            ->join('project_tasks', 'project_tasks.id', '=', 'task_assignees.project_task_id')
            ->where('project_tasks.project_id', $project->id)
            ->whereNull('project_tasks.parent_id')
            ->with('profile:id,user_id,avatar')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('task_count')
            ->limit(6)
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'avatar' => $user->avatar,
                'count' => (int) $user->task_count,
            ]);

        $unassignedCount = $project->tasks()
            ->whereNull('parent_id')
            ->whereDoesntHave('assignees')
            ->count();

        if ($unassignedCount > 0 && $assigned->count() < 6) {
            $assigned->push([
                'id' => 'unassigned',
                'name' => 'Unassigned',
                'avatar' => null,
                'count' => $unassignedCount,
            ]);
        }

        return $assigned->take(6)->values()->all();
    }

    public function groupTasks(Project $project, ProjectGroup $projectGroup)
    {
        $this->authorize('view', $project);

        abort_unless((int) $projectGroup->project_id === (int) $project->id, 404);

        $tasks = $projectGroup->tasks()
            ->with([
                'assignees.profile',
                'files.uploader.profile',
                'links',
                'subtasks.assignees.profile',
                'subtasks.files.uploader.profile',
                'subtasks.links',
            ])
            ->get();

        $user = auth()->user();
        $canManageAllTasks = $user->isSuperAdmin() || $project->isOwner($user);

        $tasks->each(function ($task) use ($canManageAllTasks, $project, $user) {
            $this->setTaskPermissions(
                $task,
                $canManageAllTasks,
                $project->archived_at !== null,
                $user->id,
            );

            $task->subtasks->each(function ($subtask) use ($canManageAllTasks, $project, $user) {
                $this->setTaskPermissions(
                    $subtask,
                    $canManageAllTasks,
                    $project->archived_at !== null,
                    $user->id,
                );
            });
        });

        return response()->json(['tasks' => $tasks]);
    }

    private function setTaskPermissions(
        ProjectTask $task,
        bool $canManageAllTasks,
        bool $isArchived,
        int $userId,
    ): void {
        $task->setAttribute('can', [
            'update' => $canManageAllTasks || (! $isArchived && $task->assignees->contains('id', $userId)),
        ]);
    }

    public function update(Request $request, Project $project, PublicUploadService $uploads)
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'logo_icon' => ['nullable', 'string', 'max:100'],
            'logo_color' => ['nullable', 'string', 'max:20'],
            'logo' => ['nullable', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp,svg'],
            'member_ids' => ['nullable', 'array', 'max:100'],
            'member_ids.*' => ['distinct', Rule::exists('users', 'id')
                ->where('role', 'user')
                ->where('approval_status', 'approved')],
        ], [
            'end_date.after_or_equal' => 'End date tidak boleh lebih awal dari start date.',
        ]);

        $this->timelineValidation->validateProject(
            $project,
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
        );

        $oldLogo = $project->logo;

        if ($request->hasFile('logo')) {
            $validated['logo'] = $uploads->store(
                $request->file('logo'),
                'project-logos',
                'public',
                'logo',
            );
        }

        $removedAssigneeUserIds = [];

        try {
            DB::transaction(function () use ($validated, $project, &$removedAssigneeUserIds) {
                Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

                $project->update([
                    'name' => $validated['name'],
                    'slug' => $project->name !== $validated['name']
                        ? $this->uniqueSlug($validated['name'], $project)
                        : $project->slug,
                    'description' => $validated['description'] ?? null,
                    'start_date' => $validated['start_date'] ?? null,
                    'end_date' => $validated['end_date'] ?? null,
                    'logo_icon' => $validated['logo_icon'] ?? $project->logo_icon,
                    'logo_color' => $validated['logo_color'] ?? $project->logo_color,
                    'logo' => $validated['logo'] ?? $project->logo,
                ]);

                if (array_key_exists('member_ids', $validated)) {
                    $sync = [
                        $project->created_by => ['role' => 'owner'],
                    ];

                    foreach ($validated['member_ids'] ?? [] as $memberId) {
                        if ((int) $memberId !== (int) $project->created_by) {
                            $sync[$memberId] = ['role' => 'member'];
                        }
                    }

                    $allowedUserIds = collect(array_keys($sync))->map(fn ($id) => (int) $id);
                    $projectTaskIds = $project->tasks()->pluck('id');
                    $projectEventIds = $project->events()->pluck('id');

                    $removedAssigneeUserIds = DB::table('task_assignees')
                        ->whereIn('project_task_id', $projectTaskIds)
                        ->whereNotIn('user_id', $allowedUserIds)
                        ->pluck('user_id')
                        ->map(fn ($id) => (int) $id)
                        ->unique()
                        ->values()
                        ->all();

                    DB::table('task_assignees')
                        ->whereIn('project_task_id', $projectTaskIds)
                        ->whereNotIn('user_id', $allowedUserIds)
                        ->delete();

                    DB::table('event_assignees')
                        ->whereIn('event_id', $projectEventIds)
                        ->whereNotIn('user_id', $allowedUserIds)
                        ->delete();

                    $project->members()->sync($sync);
                }
            });
        } catch (Throwable $exception) {
            if (isset($validated['logo'])) {
                Storage::disk('public')->delete($validated['logo']);
            }

            throw $exception;
        }

        if (isset($validated['logo']) && $oldLogo) {
            Storage::disk('public')->delete($oldLogo);
        }

        if ($removedAssigneeUserIds) {
            $this->dailyReportSync->syncDateForUsers(
                now()->toDateString(),
                $removedAssigneeUserIds,
            );
        }

        return redirect()->route('projects.show', $project->fresh());
    }

    public function destroy(Project $project)
    {
        $this->authorize('delete', $project);

        $project->delete();

        return redirect()->route('dashboard');
    }

    public function archive(Project $project)
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        $project->update([
            'archived_at' => now(),
        ]);

        return back();
    }

    public function unarchive(Project $project)
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        $project->update([
            'archived_at' => null,
        ]);

        return back();
    }
}
