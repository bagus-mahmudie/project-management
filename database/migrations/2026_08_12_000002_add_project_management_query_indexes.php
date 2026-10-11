<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_tasks', function (Blueprint $table) {
            $table->index(['project_id', 'project_group_id', 'parent_id', 'position'], 'tasks_project_hierarchy_position_idx');
        });
        Schema::table('events', function (Blueprint $table) {
            $table->index(['event_date', 'start_time'], 'events_date_time_idx');
        });
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->index(['user_id', 'created_at'], 'activity_user_created_idx');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->index(['role', 'approval_status'], 'users_role_approval_idx');
        });
    }

    public function down(): void
    {
        Schema::table('project_tasks', fn (Blueprint $table) => $table->dropIndex('tasks_project_hierarchy_position_idx'));
        Schema::table('events', fn (Blueprint $table) => $table->dropIndex('events_date_time_idx'));
        Schema::table('activity_logs', fn (Blueprint $table) => $table->dropIndex('activity_user_created_idx'));
        Schema::table('users', fn (Blueprint $table) => $table->dropIndex('users_role_approval_idx'));
    }
};
