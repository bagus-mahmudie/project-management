<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateEmployeeRequest;
use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $authenticatedUser = $request->user();

        $employees = User::with('profile')
            ->where('role', '!=', 'super_admin')
            ->where('approval_status', 'approved')
            ->when($search, function ($query) use ($search) {
                $query->where(function ($employeeQuery) use ($search) {
                    $employeeQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhereHas('profile', function ($profileQuery) use ($search) {
                            $profileQuery
                                ->where('position', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%");
                        });
                });
            })
            ->latest()
            ->get();

        $employees->each(function (User $employee) use ($authenticatedUser) {
            $employee->setAttribute('can', [
                'update' => $authenticatedUser->isSuperAdmin() || $authenticatedUser->is($employee),
                'delete' => $authenticatedUser->isSuperAdmin(),
            ]);
        });

        return Inertia::render('Employee/Index', [
            'projects' => Project::query()
                ->select(['id', 'name', 'slug', 'logo', 'logo_icon', 'logo_color', 'archived_at', 'created_at'])
                ->latest()
                ->get(),

            'users' => User::with('profile')
                ->select('id', 'name', 'email', 'role')
                ->where('role', 'user')
                ->where('approval_status', 'approved')
                ->latest()
                ->get(),

            'employees' => $employees,
        ]);
    }

    public function show(User $user)
    {
        abort_if($user->isSuperAdmin(), 404);

        $employee = $user->load('profile');

        $projectAssignments = $user->projects()
            ->select([
                'projects.id',
                'projects.name',
                'projects.slug',
                'projects.logo',
                'projects.logo_icon',
                'projects.logo_color',
                'projects.start_date',
                'projects.end_date',
                'projects.updated_at',
            ])
            ->withCount([
                'tasks as assigned_task_count' => function ($query) use ($user) {
                    $query->whereHas(
                        'assignees',
                        function ($assigneeQuery) use ($user) {
                            $assigneeQuery->where(
                                'users.id',
                                $user->id,
                            );
                        },
                    );
                },
            ])
            ->orderByDesc('projects.updated_at')
            ->get();

        $startDate = $projectAssignments
            ->pluck('start_date')
            ->filter()
            ->min();

        $endDate = $projectAssignments
            ->pluck('end_date')
            ->filter()
            ->max();

        $assignments = $projectAssignments
            ->map(function ($project) {
                return [
                    'id' => $project->id,
                    'name' => $project->name,
                    'slug' => $project->slug,
                    'logo_url' => $project->logo_url,
                    'logo_icon' => $project->logo_icon ?: 'bi-kanban',
                    'logo_color' => $project->logo_color ?: '#fff433',
                    'role' => $project->pivot?->role,
                    'task_count' => (int) $project->assigned_task_count,
                ];
            })
            ->values();

        $activities = ActivityLog::query()
            ->where('user_id', $user->id)
            ->latest()
            ->limit(20)
            ->get()
            ->map(function (ActivityLog $activity) {
                return [
                    'id' => $activity->id,
                    'action' => $activity->action,
                    'description' => $activity->description,
                    'properties' => $activity->properties,
                    'created_at' => $activity->created_at,
                ];
            })
            ->values();

        return response()->json([
            'employee' => $employee,
            'assignments' => $assignments,
            'assignment_period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'activities' => $activities,
        ]);
    }

    public function update(UpdateEmployeeRequest $request, User $user): RedirectResponse
    {
        abort_if($user->isSuperAdmin(), 403, 'Akun super admin tidak dapat diubah dari halaman employee.');

        $validated = $request->validated();

        DB::transaction(function () use ($user, $validated) {
            $user->update([
                'name' => $validated['name'],
                'email' => $validated['email'],
            ]);

            $user->profile()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'phone' => $validated['phone'] ?? null,
                ],
            );
        });

        return back()->with('success', 'Data employee berhasil diperbarui.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        abort_if($user->isSuperAdmin(), 403, 'Akun super admin tidak dapat dinonaktifkan.');
        abort_if(
            $user->ownsProjects(),
            422,
            'Ownership project harus dipindahkan sebelum employee dinonaktifkan.',
        );

        DB::transaction(function () use ($user) {
            $user->update([
                'approval_status' => 'inactive',
                'approved_at' => null,
                'approved_by' => null,
            ]);

            DB::table('sessions')->where('user_id', $user->id)->delete();
        });

        return back()->with('success', 'Employee berhasil dinonaktifkan.');
    }
}
