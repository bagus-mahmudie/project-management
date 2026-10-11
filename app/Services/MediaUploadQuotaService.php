<?php

namespace App\Services;

use App\Models\DailyReportItemFile;
use App\Models\TaskFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class MediaUploadQuotaService
{
    /** @param iterable<UploadedFile> $files */
    public function ensureWithinQuota(int $userId, iterable $files): void
    {
        $incomingBytes = collect($files)->sum(
            fn (UploadedFile $file): int => (int) $file->getSize(),
        );

        $this->ensureAvailable($userId, $incomingBytes);
    }

    public function ensureAvailable(int $userId, int $incomingBytes): void
    {
        $quotaBytes = max(1, (int) config('media.user_storage_quota_mb', 1024))
            * 1024
            * 1024;
        $usedBytes = (int) TaskFile::query()
            ->where('uploaded_by', $userId)
            ->sum('size')
            + (int) DailyReportItemFile::query()
                ->where('uploaded_by', $userId)
                ->sum('size');

        if ($usedBytes + $incomingBytes > $quotaBytes) {
            throw ValidationException::withMessages([
                'files' => 'Kuota penyimpanan file Anda tidak mencukupi.',
            ]);
        }
    }
}
