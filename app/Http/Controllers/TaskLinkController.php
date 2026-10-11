<?php

namespace App\Http\Controllers;

use App\Models\ProjectTask;
use App\Models\TaskLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskLinkController extends Controller
{
    public function store(Request $request, ProjectTask $projectTask)
    {
        $this->authorize('update', $projectTask);
        $this->ensureLinksAreEditable($projectTask);

        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:255'],
            'url' => ['required', 'url:http,https', 'max:2048'],
        ]);

        $projectTask->links()->create([
            'created_by' => auth()->id(),
            'label' => $validated['label'] ?? null,
            'url' => $validated['url'],
        ]);

        return back();
    }

    public function update(Request $request, TaskLink $taskLink)
    {
        $this->authorize('update', $taskLink->task);
        $this->ensureLinksAreEditable($taskLink->task);

        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:255'],
            'url' => ['required', 'url:http,https', 'max:2048'],
        ]);

        $taskLink->update([
            'label' => $validated['label'] ?? null,
            'url' => $validated['url'],
        ]);

        return back();
    }

    public function sync(Request $request, ProjectTask $projectTask)
    {
        $this->authorize('update', $projectTask);
        $this->ensureLinksAreEditable($projectTask);

        $validated = $request->validate([
            'links' => ['present', 'array', 'max:50'],
            'links.*.label' => ['nullable', 'string', 'max:255'],
            'links.*.url' => ['required', 'url:http,https', 'max:2048'],
        ]);

        DB::transaction(function () use ($projectTask, $validated, $request) {
            $projectTask->links()->delete();

            foreach ($validated['links'] as $link) {
                $projectTask->links()->create([
                    'label' => $link['label'] ?? null,
                    'url' => $link['url'],
                    'created_by' => $request->user()->id,
                ]);
            }
        });

        return back();
    }

    public function destroy(TaskLink $taskLink)
    {
        $this->authorize('update', $taskLink->task);
        $this->ensureLinksAreEditable($taskLink->task);

        $taskLink->delete();

        return back();
    }

    private function ensureLinksAreEditable(ProjectTask $projectTask): void
    {
        if (! $projectTask->subtasks()->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'links' => [
                'Link parent dihitung otomatis dari seluruh link subtask.',
            ],
        ]);
    }
}
