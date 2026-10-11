<?php

namespace App\Http\Controllers;

use App\Models\DailyReportItem;
use App\Models\DailyReportItemFile;
use App\Services\MediaUploadQuotaService;
use App\Services\PublicUploadService;
use App\Support\MediaUploadRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Throwable;

class DailyReportItemFileController extends Controller
{
    public function store(
        Request $request,
        DailyReportItem $dailyReportItem,
        PublicUploadService $uploads,
        MediaUploadQuotaService $quota,
    ) {
        abort_if(
            $dailyReportItem->source === 'project',
            422,
            'Project work evidence harus diedit dari halaman Project.',
        );

        $this->authorizeDailyReportItemWrite(
            $dailyReportItem,
        );

        $validated = $request->validate([
            'files' => MediaUploadRules::files(),
            'files.*' => MediaUploadRules::file(),
        ]);

        Cache::lock('media-upload.user.'.auth()->id(), 300)
            ->block(10, function () use ($validated, $dailyReportItem, $uploads, $quota): void {
                $quota->ensureWithinQuota(auth()->id(), $validated['files']);
                $storedPaths = [];
                $pendingFiles = [];

                try {
                    foreach ($validated['files'] as $file) {
                        $path = $uploads->store($file, 'daily-report-evidence', 'local');
                        $storedPaths[] = $path;

                        $pendingFiles[] = [
                            'uploaded_by' => auth()->id(),
                            'name' => $file->getClientOriginalName(),
                            'path' => $path,
                            'disk' => 'local',
                            'mime_type' => str_ends_with($path, '.svg')
                                ? 'image/svg+xml'
                                : $file->getMimeType(),
                            'size' => $file->getSize(),
                        ];
                    }

                    DB::transaction(function () use ($dailyReportItem, $pendingFiles): void {
                        foreach ($pendingFiles as $attributes) {
                            $dailyReportItem->files()->create($attributes);
                        }
                    });
                } catch (Throwable $exception) {
                    foreach ($storedPaths as $path) {
                        Storage::disk('local')->delete($path);
                    }

                    throw $exception;
                }
            });

        return back();
    }

    public function download(
        DailyReportItemFile $dailyReportItemFile,
    ) {
        $dailyReportItem =
            $dailyReportItemFile->item;

        abort_unless(
            $dailyReportItem,
            404,
            'Daily Report item tidak ditemukan.',
        );

        $this->authorizeDailyReportItemRead(
            $dailyReportItem,
        );

        $disk =
            $dailyReportItemFile->disk
            ?: 'public';

        abort_unless(
            $dailyReportItemFile->path &&
            Storage::disk($disk)->exists(
                $dailyReportItemFile->path,
            ),
            404,
            'File tidak ditemukan pada storage.',
        );

        return Storage::disk($disk)->download(
            $dailyReportItemFile->path,
            $dailyReportItemFile->name,
            [
                'Content-Type' => $dailyReportItemFile->mime_type
                    ?: 'application/octet-stream',

                'Content-Disposition' => 'attachment; filename="'.
                    addslashes(
                        $dailyReportItemFile->name,
                    ).
                    '"',
            ],
        );
    }

    public function preview(DailyReportItemFile $dailyReportItemFile)
    {
        $dailyReportItem = $dailyReportItemFile->item;

        abort_unless($dailyReportItem, 404);
        $this->authorizeDailyReportItemRead($dailyReportItem);

        $disk = $dailyReportItemFile->disk ?: 'local';
        abort_unless(Storage::disk($disk)->exists($dailyReportItemFile->path), 404);

        return response()->file(
            Storage::disk($disk)->path($dailyReportItemFile->path),
            [
                'Content-Type' => $dailyReportItemFile->mime_type ?: 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
            ],
        )->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $dailyReportItemFile->name,
        );
    }

    public function destroy(
        DailyReportItemFile $dailyReportItemFile,
    ) {
        $dailyReportItem =
            $dailyReportItemFile->item;

        abort_unless(
            $dailyReportItem,
            404,
            'Daily Report item tidak ditemukan.',
        );

        abort_if(
            $dailyReportItem->source === 'project',
            422,
            'Project work evidence harus dihapus dari halaman Project.',
        );

        $this->authorizeDailyReportItemWrite(
            $dailyReportItem,
        );

        $dailyReportItemFile->delete();

        return back();
    }

    private function authorizeDailyReportItemRead(
        DailyReportItem $dailyReportItem,
    ): void {
        abort_unless(
            auth()->check() && $dailyReportItem->report()->exists(),
            403,
            'Anda tidak memiliki akses ke file ini.',
        );
    }

    private function authorizeDailyReportItemWrite(
        DailyReportItem $dailyReportItem,
    ): void {
        $dailyReportItem->loadMissing('report');

        $user = auth()->user();

        abort_unless(
            $user &&
            (
                (int) $dailyReportItem->report?->user_id ===
                    (int) $user->id ||
                $user->isSuperAdmin()
            ),
            403,
            'Anda tidak memiliki akses ke file ini.',
        );
    }
}
