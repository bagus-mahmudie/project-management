<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Project;
use App\Models\User;
use App\Support\ActivityLogger;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class EventController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'filter' => ['nullable', Rule::in(['day', 'month', 'year'])],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);
        $filter = $validated['filter'] ?? 'month';
        $date = $validated['date'] ?? now()->toDateString();
        $search = trim($validated['search'] ?? '');

        $baseDate = Carbon::parse($date);

        $events = Event::query()
            ->when(! auth()->user()->isSuperAdmin(), fn ($query) => $query
                ->where(fn ($eventQuery) => $eventQuery
                    ->where('created_by', auth()->id())
                    ->orWhereHas('assignees', fn ($assigneeQuery) => $assigneeQuery->whereKey(auth()->id()))))
            ->when($search, fn ($query) => $query->where('title', 'like', "%{$search}%"))
            ->when($filter === 'day', fn ($query) => $query->whereBetween('event_date', [
                $baseDate->copy()->startOfDay()->toDateString(),
                $baseDate->copy()->endOfDay()->toDateString(),
            ]))
            ->when($filter === 'month', fn ($query) => $query->whereBetween('event_date', [
                $baseDate->copy()->startOfMonth()->toDateString(),
                $baseDate->copy()->endOfMonth()->toDateString(),
            ]))
            ->when($filter === 'year', fn ($query) => $query->whereBetween('event_date', [
                $baseDate->copy()->startOfYear()->toDateString(),
                $baseDate->copy()->endOfYear()->toDateString(),
            ]))
            ->with(['project', 'creator', 'assignees'])
            ->latest()
            ->get();

        $events->each(function (Event $event) {
            $event->setAttribute('can', [
                'update' => auth()->user()->can('update', $event),
                'delete' => auth()->user()->can('delete', $event),
            ]);
        });

        $projects = Project::query()
            ->when(! auth()->user()->isSuperAdmin(), fn ($query) => $query
                ->whereHas('members', fn ($memberQuery) => $memberQuery->whereKey(auth()->id())))
            ->with(['members' => fn ($query) => $query
                ->select('users.id', 'users.name', 'users.email')
                ->orderBy('users.name')])
            ->orderBy('name')
            ->get();

        return Inertia::render('Event/Index', [
            'events' => $events,
            'projects' => $projects,
            'users' => User::where('approval_status', 'approved')
                ->where('role', 'user')
                ->select('id', 'name', 'email')
                ->orderBy('name')
                ->get(),
            'filters' => [
                'filter' => $filter,
                'date' => $date,
                'search' => $search,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Event::class);

        $validated = $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'event_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
            'assignee_ids' => ['required', 'array', 'min:1', 'max:100'],
            'assignee_ids.*' => ['distinct', Rule::exists('users', 'id')->where('approval_status', 'approved')->where('role', 'user')],
        ]);

        $project = Project::with('members:id')->findOrFail($validated['project_id']);

        abort_unless(
            auth()->user()->isSuperAdmin() || $project->isMember(auth()->user()),
            403,
            'Hanya member project yang dapat membuat event.',
        );
        abort_if(
            $project->archived_at && ! auth()->user()->isSuperAdmin() && ! $project->isOwner(auth()->user()),
            403,
        );

        $projectMemberIds = $project->members
            ->pluck('id')
            ->unique();

        $assigneeIds = collect($validated['assignee_ids'])
            ->map(fn ($id) => (int) $id)
            ->intersect($projectMemberIds)
            ->unique()
            ->values()
            ->all();

        if (count($assigneeIds) !== count(array_unique($validated['assignee_ids']))) {
            throw ValidationException::withMessages([
                'assignee_ids' => ['Semua assignee harus merupakan member project.'],
            ]);
        }

        $event = DB::transaction(function () use ($validated, $assigneeIds) {
            $event = Event::create([
                'project_id' => $validated['project_id'],
                'created_by' => auth()->id(),
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'event_date' => $validated['event_date'],
                'start_time' => $validated['start_time'],
                'end_time' => $validated['end_time'] ?? null,
            ]);

            $event->assignees()->sync($assigneeIds);

            return $event;
        });

        ActivityLogger::log(
            'event_created',
            'Membuat event '.$event->title,
            $event,
        );

        return redirect()->route('events.index');
    }

    public function show(Event $event)
    {
        $this->authorize('view', $event);

        return response()->json([
            'event' => $event->load(['project', 'assignees.profile', 'creator.profile']),
            'can' => [
                'update' => auth()->user()->can('update', $event),
                'delete' => auth()->user()->can('delete', $event),
            ],
        ]);
    }

    public function update(Request $request, Event $event)
    {
        $this->authorize('update', $event);

        $validated = $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'event_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
            'assignee_ids' => ['required', 'array', 'min:1', 'max:100'],
            'assignee_ids.*' => ['distinct', Rule::exists('users', 'id')->where('approval_status', 'approved')->where('role', 'user')],
        ]);

        $project = Project::with('members:id')->findOrFail($validated['project_id']);

        abort_unless(
            auth()->user()->isSuperAdmin() || $project->isMember(auth()->user()),
            403,
            'Event hanya dapat dipindahkan ke project yang Anda ikuti.',
        );
        abort_if(
            $project->archived_at &&
            ! auth()->user()->isSuperAdmin() &&
            ! $project->isOwner(auth()->user()),
            403,
            'Event pada archived project hanya dapat diubah oleh owner atau super admin.',
        );

        $projectMemberIds = $project->members
            ->pluck('id')
            ->unique();

        $assigneeIds = collect($validated['assignee_ids'])
            ->map(fn ($id) => (int) $id)
            ->intersect($projectMemberIds)
            ->unique()
            ->values()
            ->all();

        if (count($assigneeIds) !== count(array_unique($validated['assignee_ids']))) {
            throw ValidationException::withMessages([
                'assignee_ids' => ['Semua assignee harus merupakan member project.'],
            ]);
        }

        DB::transaction(function () use ($event, $validated, $assigneeIds) {
            $event->update([
                'project_id' => $validated['project_id'],
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'event_date' => $validated['event_date'],
                'start_time' => $validated['start_time'],
                'end_time' => $validated['end_time'] ?? null,
            ]);

            $event->assignees()->sync($assigneeIds);
        });

        ActivityLogger::log(
            'event_updated',
            'Mengupdate event '.$event->title,
            $event,
        );

        return redirect()->route('events.index');
    }

    public function destroy(Event $event)
    {
        $this->authorize('delete', $event);

        ActivityLogger::log(
            'event_deleted',
            'Menghapus event '.$event->title,
            $event,
        );

        $event->delete();

        return back();
    }
}
