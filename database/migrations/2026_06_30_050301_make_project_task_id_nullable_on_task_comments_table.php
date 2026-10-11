<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_comments', function (Blueprint $table) {
            $table->dropForeign(['project_task_id']);
            $table->foreignId('project_task_id')->nullable()->change();
            $table->foreign('project_task_id')->references('id')->on('project_tasks')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('task_comments', function (Blueprint $table) {
            $table->dropForeign(['project_task_id']);
            $table->foreignId('project_task_id')->nullable(false)->change();
            $table->foreign('project_task_id')->references('id')->on('project_tasks')->cascadeOnDelete();
        });
    }
};
