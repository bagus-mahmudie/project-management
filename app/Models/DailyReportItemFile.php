<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class DailyReportItemFile extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (DailyReportItemFile $file) {
            if ($file->path) {
                Storage::disk($file->disk ?? 'public')->delete($file->path);
            }
        });
    }

    protected $fillable = [
        'daily_report_item_id',
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

    public function item(): BelongsTo
    {
        return $this->belongsTo(
            DailyReportItem::class,
            'daily_report_item_id',
        );
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getUrlAttribute(): string
    {
        return route('daily-report.items.files.preview', $this);
    }
}
