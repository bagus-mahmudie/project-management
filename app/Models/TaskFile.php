<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class TaskFile extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (TaskFile $file) {
            if ($file->path) {
                Storage::disk($file->disk ?? 'local')->delete($file->path);
            }
        });
    }

    protected $fillable = [
        'project_task_id',
        'uploaded_by',
        'name',
        'path',
        'disk',
        'mime_type',
        'size',
    ];

    protected $appends = [
        'url',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(
            ProjectTask::class,
            'project_task_id',
        );
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'uploaded_by',
        );
    }

    public function getUrlAttribute(): ?string
    {
        if (! $this->path) {
            return null;
        }

        return route('task-files.preview', $this);
    }
}
