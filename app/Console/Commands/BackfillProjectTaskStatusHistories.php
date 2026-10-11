<?php

namespace App\Console\Commands;

use App\Models\ProjectTask;
use Illuminate\Console\Command;

class BackfillProjectTaskStatusHistories extends Command
{
    protected $signature =
        'project-tasks:backfill-status-history';

    protected $description =
        'Backfill status history for existing project tasks';

    public function handle(): int
    {
        $created = 0;

        ProjectTask::query()
            ->whereDoesntHave('statusHistories')
            ->chunkById(100, function ($tasks) use (&$created) {
                foreach ($tasks as $task) {
                    $task->statusHistories()->create([
                        'changed_by' => $task->created_by,
                        'from_status' => null,
                        'to_status' => $task->status,
                        'changed_at' => $task->status_changed_at
                            ?? $task->created_at
                            ?? now(),
                    ]);

                    $created++;
                }
            });

        $this->info(
            "{$created} project task status histories created.",
        );

        return self::SUCCESS;
    }
}
