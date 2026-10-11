<?php

namespace App\Console\Commands;

use App\Models\DailyReportItemFile;
use App\Models\TaskFile;
use App\Services\PublicUploadService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class MigrateProjectFilesToPrivateStorage extends Command
{
    protected $signature = 'project-files:migrate-private {--dry-run : Report files without moving them}';

    protected $description = 'Move task files and Daily Report evidence from public to private storage.';

    public function handle(PublicUploadService $uploads): int
    {
        $this->migrateModel(TaskFile::class, $uploads);
        $this->migrateModel(DailyReportItemFile::class, $uploads);

        $this->info('Project files are stored privately.');

        return self::SUCCESS;
    }

    private function migrateModel(string $modelClass, PublicUploadService $uploads): void
    {
        $modelClass::query()
            ->where('disk', 'public')
            ->chunkById(100, function ($files) use ($uploads) {
                foreach ($files as $file) {
                    if (! $file->path) {
                        continue;
                    }

                    if (! Storage::disk('public')->exists($file->path)) {
                        if (Storage::disk('local')->exists($file->path)) {
                            $file->forceFill(['disk' => 'local'])->save();
                        }

                        continue;
                    }

                    $contents = Storage::disk('public')->get($file->path);

                    if ($this->option('dry-run')) {
                        $this->line("Would move {$file->path}.");

                        continue;
                    }

                    if (strtolower(pathinfo($file->path, PATHINFO_EXTENSION)) === 'svg') {
                        try {
                            $contents = $uploads->sanitizeSvg($contents);
                        } catch (Throwable) {
                            $this->error("Skipped unsafe or invalid SVG {$file->path}.");

                            continue;
                        }
                    }

                    if (! Storage::disk('local')->put($file->path, $contents)) {
                        $this->error("Failed to copy {$file->path}.");

                        continue;
                    }

                    $file->forceFill(['disk' => 'local'])->save();
                    Storage::disk('public')->delete($file->path);
                }
            });
    }
}
