<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('project_tasks')
            ->whereNull('parent_id')
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('project_tasks as subtasks')
                    ->whereColumn('subtasks.parent_id', 'project_tasks.id');
            })
            ->orderBy('id')
            ->chunkById(100, function ($parents): void {
                foreach ($parents as $parent) {
                    $assigneeIds = DB::table('task_assignees')
                        ->join('project_tasks', 'project_tasks.id', '=', 'task_assignees.project_task_id')
                        ->where('project_tasks.parent_id', $parent->id)
                        ->distinct()
                        ->pluck('task_assignees.user_id');

                    DB::table('task_assignees')
                        ->where('project_task_id', $parent->id)
                        ->delete();

                    $now = now();
                    $rows = $assigneeIds->map(fn ($userId) => [
                        'project_task_id' => $parent->id,
                        'user_id' => $userId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all();

                    if ($rows) {
                        DB::table('task_assignees')->insert($rows);
                    }
                }
            });
    }

    public function down(): void
    {
        // Data assignee parent sebelumnya tidak dapat direkonstruksi dengan aman.
    }
};
