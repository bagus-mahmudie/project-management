<?php

namespace App\Http\Controllers;

use App\Models\ProjectTask;
use App\Models\TaskFile;
use App\Services\PublicUploadService;
use App\Services\MediaUploadQuotaService;
use App\Support\ActivityLogger;
use App\Support\MediaUploadRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Throwable;

class TaskFileController extends Controller
{
    public function store(
        Request $request,
        ProjectTask $projectTask,
        PublicUploadService $uploads,
        MediaUploadQuotaService $quota,
    ) {
        $this->authorize('update', $projectTask);

        $validated = $request->validate([
            'files' => MediaUploadRules::files(),
            'files.*' => MediaUploadRules::file(),
        ]);

        $createdFiles = Cache::lock('media-upload.user.'.auth()->id(), 300)
            ->block(10, function () use ($validated, $projectTask, $uploads, $quota) {
                $quota->ensureWithinQuota(auth()->id(), $validated['files']);
                $storedPaths = [];
                $pendingFiles = [];

                try {
                    foreach ($validated['files'] as $file) {
                        $path = $uploads->store($file, 'task-files', 'local');
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

                    $createdFiles = DB::transaction(fn () => collect($pendingFiles)
                        ->map(fn (array $attributes) => $projectTask->files()->create($attributes)));
                } catch (Throwable $exception) {
                    foreach ($storedPaths as $path) {
                        Storage::disk('local')->delete($path);
                    }

                    throw $exception;
                }

                return $createdFiles;
            });

        foreach ($createdFiles as $taskFile) {
            ActivityLogger::log(
                'file_uploaded',
                'Upload '.$taskFile->name,
                $taskFile,
                [
                    'task_id' => $projectTask->id,
                    'task_title' => $projectTask->title,
                    'file_name' => $taskFile->name,
                ],
            );
        }

        return back();
    }

    public function download(TaskFile $taskFile)
    {
        /*
        * Sesuaikan authorization dengan aturan project Anda.
        * Pengguna yang boleh melihat task dapat mengunduh file.
        */
        $this->authorize('view', $taskFile->task);

        abort_unless(
            Storage::disk($taskFile->disk ?? 'local')->exists($taskFile->path),
            404,
            'File tidak ditemukan.',
        );

        return Storage::disk($taskFile->disk ?? 'local')->download(
            $taskFile->path,
            $taskFile->name,
            [
                'Content-Type' => $taskFile->mime_type
                    ?: 'application/octet-stream',
            ],
        );
    }

    public function preview(TaskFile $taskFile)
    {
        $this->authorize('view', $taskFile->task);

        $disk = $taskFile->disk ?? 'local';

        abort_unless(Storage::disk($disk)->exists($taskFile->path), 404);

        return response()->file(
            Storage::disk($disk)->path($taskFile->path),
            [
                'Content-Type' => $taskFile->mime_type ?: 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
            ],
        )->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $taskFile->name);
    }

    public function destroy(TaskFile $taskFile)
    {
        $this->authorize(
            'update',
            $taskFile->task,
        );

        $taskFile->delete();

        return back();
    }
}
