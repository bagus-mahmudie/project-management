<?php

namespace App\Console\Commands;

use App\Services\DailyReportCatchUpService;
use App\Services\DailyReportSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class GenerateDailyReports extends Command
{
    protected $signature = 'daily-reports:generate
        {--date= : Date in Y-m-d format}
        {--start= : First date for an explicit backfill}
        {--end= : Last date for an explicit backfill}';

    protected $description =
        'Synchronize automatic project tasks into daily reports.';

    public function handle(
        DailyReportSyncService $syncService,
        DailyReportCatchUpService $catchUpService,
    ): int {
        $start = $this->option('start');
        $end = $this->option('end');

        if (($start && ! $end) || ($end && ! $start)) {
            $this->error('Options --start dan --end harus digunakan bersama.');

            return self::FAILURE;
        }

        if ($start && $end) {
            try {
                $cursor = Carbon::createFromFormat('Y-m-d', $start)->startOfDay();
                $lastDate = Carbon::createFromFormat('Y-m-d', $end)->startOfDay();
            } catch (\Throwable) {
                $this->error('Format tanggal harus Y-m-d.');

                return self::FAILURE;
            }

            if ($cursor->gt($lastDate)) {
                $this->error('--end tidak boleh lebih awal dari --start.');

                return self::FAILURE;
            }

            while ($cursor->lte($lastDate)) {
                $syncedDate = $cursor->toDateString();
                $syncService->syncDateForAllUsers($syncedDate);
                $catchUpService->markSynced($syncedDate);
                $cursor->addDay();
            }

            $this->info("Daily reports synchronized from {$start} through {$end}.");

            return self::SUCCESS;
        }

        $date = $this->option('date');

        if (! $date) {
            $today = now(config('app.timezone'))->toDateString();
            $queuedUsers = $catchUpService->queueAllThrough($today);

            $this->info("Daily report catch-up queued through {$today} for {$queuedUsers} users.");

            return self::SUCCESS;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $date)->toDateString();
        } catch (\Throwable) {
            $this->error('Format tanggal harus Y-m-d.');

            return self::FAILURE;
        }

        $queuedUsers = $catchUpService->queueAllThrough($date);

        $this->info(
            "Daily report synchronization queued for {$date} for {$queuedUsers} users.",
        );

        return self::SUCCESS;
    }
}
