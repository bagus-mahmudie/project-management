<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyReportItem extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (DailyReportItem $item) {
            $item->subtasks()->each(fn (DailyReportItem $subtask) => $subtask->delete());
            $item->files()->each(fn ($file) => $file->delete());

            if ($item->isFromProject()) {
                $item->comments()->update(['daily_report_item_id' => null]);
            } else {
                $item->comments()->each(fn ($comment) => $comment->delete());
            }
        });
    }

    protected $fillable = [
        'daily_report_id',
        'project_task_id',
        'parent_id',
        'source',
        'position',
        'title',
        'project_name',
        'is_subtask',
        'note',
        'work_evidence_note',
        'snapshot_status',
        'snapshot_start_date',
        'snapshot_due_date',
        'is_final_status_snapshot',
        'is_overdue',
    ];

    protected $casts = [
        'snapshot_start_date' => 'date:Y-m-d',
        'snapshot_due_date' => 'date:Y-m-d',
        'is_subtask' => 'boolean',
        'is_final_status_snapshot' => 'boolean',
        'is_overdue' => 'boolean',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(
            DailyReportItem::class,
            'parent_id',
        );
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(
            DailyReportItem::class,
            'parent_id',
        )->orderBy('position');
    }

    public function files(): HasMany
    {
        return $this->hasMany(DailyReportItemFile::class)
            ->latest();
    }

    public function links(): HasMany
    {
        return $this->hasMany(DailyReportItemLink::class)
            ->orderBy('id');
    }

    public function getEffectiveTitleAttribute(): string
    {
        return $this->isFromProject() && $this->task
            ? $this->task->title
            : $this->title;
    }

    public function getEffectiveStatusAttribute(): ?string
    {
        return $this->isFromProject() && $this->task
            ? $this->task->status
            : $this->snapshot_status;
    }

    public function getEffectiveStartDateAttribute()
    {
        return $this->isFromProject() && $this->task
            ? $this->task->start_date
            : $this->snapshot_start_date;
    }

    public function getEffectiveDueDateAttribute()
    {
        return $this->isFromProject() && $this->task
            ? $this->task->due_date
            : $this->snapshot_due_date;
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(DailyReport::class, 'daily_report_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'project_task_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class);
    }

    public function isFromProject(): bool
    {
        return $this->source === 'project';
    }

    public function isManual(): bool
    {
        return $this->source === 'manual';
    }
}
