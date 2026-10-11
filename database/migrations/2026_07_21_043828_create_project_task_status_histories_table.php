<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_task_status_histories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_task_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('changed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('from_status')->nullable();
            $table->string('to_status');

            $table->timestamp('changed_at');

            $table->timestamps();

            $table->index([
                'project_task_id',
                'changed_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_task_status_histories');
    }
};
