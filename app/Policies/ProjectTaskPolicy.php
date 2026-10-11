<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;

class ProjectTaskPolicy
{
    public function view(User $user, ProjectTask $projectTask): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, ProjectTask $projectTask): bool
    {
        $project = Project::find($projectTask->project_id);

        if (! $project) {
            return false;
        }

        if ($user->isSuperAdmin() || $project->isOwner($user)) {
            return true;
        }

        return ! $project->archived_at && $projectTask->isAssignedTo($user);
    }

    public function delete(User $user, ProjectTask $projectTask): bool
    {
        return $user->isSuperAdmin();
    }
}
