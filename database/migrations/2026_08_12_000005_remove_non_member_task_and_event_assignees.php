<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('task_assignees')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('project_tasks')
                    ->join('project_members', function ($join) {
                        $join->on('project_members.project_id', '=', 'project_tasks.project_id')
                            ->on('project_members.user_id', '=', 'task_assignees.user_id');
                    })
                    ->whereColumn('project_tasks.id', 'task_assignees.project_task_id');
            })
            ->delete();

        DB::table('event_assignees')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('events')
                    ->join('project_members', function ($join) {
                        $join->on('project_members.project_id', '=', 'events.project_id')
                            ->on('project_members.user_id', '=', 'event_assignees.user_id');
                    })
                    ->whereColumn('events.id', 'event_assignees.event_id');
            })
            ->delete();
    }

    public function down(): void
    {
        // Assignee invalid yang dibuang tidak boleh dipulihkan.
    }
};
