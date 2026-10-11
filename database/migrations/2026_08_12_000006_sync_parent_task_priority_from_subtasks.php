<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('project_tasks')
            ->whereNull('parent_id')
            ->orderBy('id')
            ->chunkById(100, function ($parents): void {
                foreach ($parents as $parent) {
                    $highestPriority = DB::table('project_tasks')
                        ->where('parent_id', $parent->id)
                        ->max('priority');

                    if ($highestPriority !== null) {
                        DB::table('project_tasks')
                            ->where('id', $parent->id)
                            ->update(['priority' => (int) $highestPriority]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Priority parent lama tidak dapat direkonstruksi dengan aman.
    }
};
