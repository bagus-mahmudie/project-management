<?php

namespace App\Jobs;

use App\Models\DailyReport;
use App\Models\DailyReportSyncCursor;
use App\Models\User;
use App\Services\DailyReportSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class SyncDailyReportUserBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    private const DAYS_PER_BATCH = 3;

    public function __construct(
        public readonly int $userId,
        public readonly string $targetDate,
    ) {
        $this->onQueue('daily-reports');
    }

    public function handle(DailyReportSyncService $syncService): void
    {
        $lock = Cache::lock("daily-reports.batch.{$this->userId}", 300);

        if (! $lock->get()) {
            $this->release(10);

            return;
        }

        $continue = false;

        try {
            $user = User::query()
                ->whereKey($this->userId)
                ->where('role', 'user')
                ->where('approval_status', 'approved')
                ->first();

            if (! $user) {
                return;
            }

            $target = Carbon::createFromFormat('Y-m-d', $this->targetDate)->startOfDay();
            $cursor = DailyReportSyncCursor::query()->firstOrCreate(
                ['user_id' => $this->userId],
                ['last_synced_date' => $this->initialCursorDate($target)],
            );
            $lastSynced = $cursor->last_synced_date?->copy()->startOfDay();
            $start = $lastSynced ? $lastSynced->addDay() : $target->copy();

            if ($start->gt($target)) {
                return;
            }

            $batchEnd = $start->copy()
                ->addDays(self::DAYS_PER_BATCH - 1)
                ->min($target);

            while ($start->lte($batchEnd)) {
                $date = $start->toDateString();
                $syncService->syncDateForUser($date, $this->userId);
                $cursor->update(['last_synced_date' => $date]);
                $start->addDay();
            }

            $continue = $batchEnd->lt($target);
        } finally {
            $lock->release();
        }

        if ($continue) {
            self::dispatch($this->userId, $this->targetDate);
        }
    }

    private function initialCursorDate(Carbon $target): string
    {
        $latestExistingDate = DailyReport::query()
            ->where('user_id', $this->userId)
            ->whereDate('report_date', '<', $target->toDateString())
            ->max('report_date');

        return $latestExistingDate
            ? Carbon::parse($latestExistingDate)->toDateString()
            : $target->copy()->subDay()->toDateString();
    }
}
