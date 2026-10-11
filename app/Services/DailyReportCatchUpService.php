<?php

namespace App\Services;

use App\Jobs\SyncDailyReportUserBatch;
use App\Models\DailyReportSyncCursor;
use App\Models\DailyReport;
use App\Models\User;
use Illuminate\Support\Carbon;

class DailyReportCatchUpService
{
    public function queueAllThrough(string $date): int
    {
        $queued = 0;

        User::query()
            ->select('id')
            ->where('role', 'user')
            ->where('approval_status', 'approved')
            ->chunkById(100, function ($users) use ($date, &$queued) {
                foreach ($users as $user) {
                    $this->queueUserThrough($date, (int) $user->id);
                    $queued++;
                }
            });

        return $queued;
    }

    public function queueUserThrough(string $date, int $userId): void
    {
        SyncDailyReportUserBatch::dispatch($userId, $date);
    }

    public function initializeUserCursor(string $targetDate, int $userId): void
    {
        $target = Carbon::createFromFormat('Y-m-d', $targetDate)->startOfDay();
        $latestPriorDate = DailyReport::query()
            ->where('user_id', $userId)
            ->whereDate('report_date', '<', $target->toDateString())
            ->max('report_date');

        DailyReportSyncCursor::query()->firstOrCreate(
            ['user_id' => $userId],
            [
                'last_synced_date' => $latestPriorDate
                    ? Carbon::parse($latestPriorDate)->toDateString()
                    : $target->copy()->subDay()->toDateString(),
            ],
        );
    }

    public function markSynced(string $date): void
    {
        $date = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();

        if ($date->isFuture()) {
            return;
        }

        User::query()
            ->select('id')
            ->where('role', 'user')
            ->where('approval_status', 'approved')
            ->chunkById(100, function ($users) use ($date): void {
                foreach ($users as $user) {
                    $cursor = DailyReportSyncCursor::query()->firstOrCreate([
                        'user_id' => $user->id,
                    ]);

                    if (! $cursor->last_synced_date || $cursor->last_synced_date->lt($date)) {
                        $cursor->update(['last_synced_date' => $date->toDateString()]);
                    }
                }
            });
    }
}
