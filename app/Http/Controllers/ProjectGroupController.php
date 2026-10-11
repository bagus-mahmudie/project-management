<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectGroupController extends Controller
{
    public function store(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($project, $validated) {
            Project::query()->whereKey($project->id)->lockForUpdate()->first();

            $project->groups()->create([
                'name' => $validated['name'],
                'position' => ((int) $project->groups()->max('position')) + 1,
            ]);
        });

        return redirect()->route('projects.show', $project);
    }

    public function update(Request $request, ProjectGroup $projectGroup)
    {
        $this->authorize('update', $projectGroup->project);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $projectGroup->update([
            'name' => $validated['name'],
        ]);

        return back();
    }
}
