<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\Project;
use App\Models\User;

class EventPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Event $event): bool
    {
        if ($user->isSuperAdmin() || $event->created_by === $user->id) {
            return true;
        }

        if ($this->isAssignee($event, $user)) {
            return true;
        }

        $project = $this->project($event);

        return $project?->isMember($user) ?? false;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Event $event): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $project = $this->project($event);

        if (! $project || $project->archived_at) {
            return false;
        }

        return $event->created_by === $user->id
            || $this->isAssignee($event, $user);
    }

    public function delete(User $user, Event $event): bool
    {
        return $user->isSuperAdmin();
    }

    private function isAssignee(Event $event, User $user): bool
    {
        return $event->relationLoaded('assignees')
            ? $event->assignees->contains('id', $user->id)
            : $event->assignees()->whereKey($user->id)->exists();
    }

    private function project(Event $event): ?Project
    {
        if (! $event->relationLoaded('project')) {
            return Project::query()->find($event->project_id);
        }

        $project = $event->project;

        return $project instanceof Project ? $project : null;
    }
}
