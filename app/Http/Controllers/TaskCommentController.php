<?php

namespace App\Http\Controllers;

use App\Models\DailyReportItem;
use App\Models\ProjectTask;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;

class TaskCommentController extends Controller
{
    public function store(Request $request, ProjectTask $projectTask)
    {
        $this->authorize('view', $projectTask);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
            'daily_report_item_id' => ['nullable', 'exists:daily_report_items,id'],
            'parent_id' => ['nullable', 'exists:task_comments,id'],
        ]);

        $dailyReportItemId = $validated['daily_report_item_id'] ?? null;

        if (! empty($validated['parent_id'])) {
            $parentIsValid = $projectTask->comments()
                ->whereKey($validated['parent_id'])
                ->exists();

            abort_unless($parentIsValid, 422, 'Komentar induk tidak berasal dari task ini.');
        }

        if ($dailyReportItemId) {
            $item = DailyReportItem::findOrFail($dailyReportItemId);

            if ($item->project_task_id !== $projectTask->id) {
                abort(422, 'Daily report item does not match this task.');
            }
        }

        $comment = $projectTask->comments()->create([
            'daily_report_item_id' => $dailyReportItemId,
            'user_id' => auth()->id(),
            'parent_id' => $validated['parent_id'] ?? null,
            'body' => $validated['body'],
        ]);

        ActivityLogger::log(
            'comment_created',
            'Menambahkan komentar pada task '.$projectTask->title,
            $comment,
        );

        return back();
    }
}
