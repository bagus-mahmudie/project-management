<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_report_items', function (Blueprint $table) {
            if (! Schema::hasColumn('daily_report_items', 'parent_id')) {
                $table->foreignId('parent_id')
                    ->nullable()
                    ->after('project_task_id')
                    ->constrained('daily_report_items')
                    ->cascadeOnDelete();
            }

            if (! Schema::hasColumn('daily_report_items', 'position')) {
                $table->unsignedInteger('position')
                    ->default(0)
                    ->after('source');
            }

            if (! Schema::hasColumn('daily_report_items', 'project_name')) {
                $table->string('project_name')
                    ->nullable()
                    ->after('title');
            }

            if (! Schema::hasColumn('daily_report_items', 'is_subtask')) {
                $table->boolean('is_subtask')
                    ->default(false)
                    ->after('project_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('daily_report_items', function (Blueprint $table) {
            if (Schema::hasColumn('daily_report_items', 'parent_id')) {
                $table->dropForeign(['parent_id']);
                $table->dropColumn('parent_id');
            }

            $table->dropColumn([
                'position',
                'project_name',
                'is_subtask',
            ]);
        });
    }
};
