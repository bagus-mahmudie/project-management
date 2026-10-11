<?php

namespace App\Http\Controllers;

use App\Models\DailyReportItem;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;

class DailyReportCommentController extends Controller
{
    public function store(Request $request, DailyReportItem $dailyReportItem)
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
            'parent_id' => ['nullable', 'exists:task_comments,id'],
        ]);

        if (! empty($validated['parent_id'])) {
            $parentIsValid = $dailyReportItem->comments()
                ->whereKey($validated['parent_id'])
                ->exists();

            abort_unless($parentIsValid, 422, 'Komentar induk tidak berasal dari Daily Report ini.');
        }

        $comment = $dailyReportItem->comments()->create([
            'project_task_id' => $dailyReportItem->project_task_id,
            'user_id' => auth()->id(),
            'parent_id' => $validated['parent_id'] ?? null,
            'body' => $validated['body'],
        ]);

        ActivityLogger::log(
            'daily_comment_created',
            'Menambahkan komentar pada daily report '.$dailyReportItem->title,
            $comment,
        );

        return back();
    }
}
