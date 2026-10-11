<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_files', function (Blueprint $table) {
            $table->string('disk')->default('public')->after('path');
        });

        Schema::table('daily_reports', function (Blueprint $table) {
            $table->index(['report_date', 'user_id'], 'daily_reports_date_user_idx');
        });

        Schema::table('daily_report_items', function (Blueprint $table) {
            $table->index(
                ['daily_report_id', 'parent_id', 'source', 'position'],
                'daily_items_report_hierarchy_idx',
            );
        });

        Schema::table('project_members', function (Blueprint $table) {
            $table->index(['user_id', 'role'], 'project_members_user_role_idx');
        });

        Schema::table('event_assignees', function (Blueprint $table) {
            $table->index(['user_id', 'event_id'], 'event_assignees_user_event_idx');
        });

        Schema::table('task_assignees', function (Blueprint $table) {
            $table->index(['user_id', 'project_task_id'], 'task_assignees_user_task_idx');
        });
    }

    public function down(): void
    {
        Schema::table('task_assignees', fn (Blueprint $table) => $table->dropIndex('task_assignees_user_task_idx'));
        Schema::table('event_assignees', fn (Blueprint $table) => $table->dropIndex('event_assignees_user_event_idx'));
        Schema::table('project_members', fn (Blueprint $table) => $table->dropIndex('project_members_user_role_idx'));
        Schema::table('daily_report_items', fn (Blueprint $table) => $table->dropIndex('daily_items_report_hierarchy_idx'));
        Schema::table('daily_reports', fn (Blueprint $table) => $table->dropIndex('daily_reports_date_user_idx'));

        Schema::table('task_files', function (Blueprint $table) {
            $table->dropColumn('disk');
        });
    }
};
