<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class ProjectTask extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('dashboard.analytics.v2'));
        static::deleted(fn () => Cache::forget('dashboard.analytics.v2'));

        static::deleting(function (ProjectTask $task) {
            $task->subtasks()->each(fn (ProjectTask $subtask) => $subtask->delete());
            $task->files()->each(fn ($file) => $file->delete());
        });
    }

    public const STATUS_TO_DO = 'to_do';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_PENDING = 'pending';

    public const STATUS_READY_TO_CHECK = 'ready_to_check';

    public const STATUS_NEED_REVISION = 'need_revision';

    public const STATUS_DONE = 'done';

    protected $fillable = [
        'project_id',
        'project_group_id',
        'parent_id',
        'created_by',
        'title',
        'description',
        'status',
        'start_date',
        'due_date',
        'priority',
        'position',
        'status_changed_at',
    ];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'due_date' => 'date:Y-m-d',
        'status_changed_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ProjectGroup::class, 'project_group_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'parent_id');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class, 'parent_id')->orderBy('position');
    }

    public function hasSubtasks(): bool
    {
        /*
        * Jika relasi sudah diload, jangan query ulang.
        */
        if ($this->relationLoaded('subtasks')) {
            return $this->subtasks->isNotEmpty();
        }

        return $this->subtasks()->exists();
    }

    public function isParentTask(): bool
    {
        return is_null($this->parent_id);
    }

    public function isSubtask(): bool
    {
        return ! is_null($this->parent_id);
    }

    public function hasDerivedStatus(): bool
    {
        return $this->hasSubtasks();
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_assignees')
            ->withTimestamps();
    }

    public function files(): HasMany
    {
        return $this->hasMany(TaskFile::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(TaskLink::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class);
    }

    public function dailyReportItems(): HasMany
    {
        return $this->hasMany(DailyReportItem::class);
    }

    public function isAssignedTo(User $user): bool
    {
        return $this->assignees()->whereKey($user->id)->exists();
    }

    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS
            && $this->due_date
            && $this->due_date->isPast();
    }

    public function shouldAppearInDailyReportToday(): bool
    {
        if ($this->start_date?->isFuture()) {
            return false;
        }

        if ($this->status === self::STATUS_IN_PROGRESS) {
            return true;
        }

        if (in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_READY_TO_CHECK,
            self::STATUS_NEED_REVISION,
            self::STATUS_DONE,
        ], true)) {
            return $this->status_changed_at?->isToday() ?? false;
        }

        return false;
    }

    public function isFinalDailySnapshotStatus(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_READY_TO_CHECK,
            self::STATUS_NEED_REVISION,
            self::STATUS_DONE,
        ], true);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(ProjectTaskStatusHistory::class)
            ->orderBy('changed_at');
    }

    public function statusOnDate(
        string $date,
    ): ?string {
        $dateEnd = Carbon::parse(
            $date,
        )->endOfDay();

        /*
        * Gunakan collection yang sudah di-load
        * oleh DailyReportSyncService.
        */
        $histories = $this->relationLoaded(
            'statusHistories',
        )
            ? $this->statusHistories
            : $this->statusHistories()
                ->orderBy('changed_at')
                ->get();

        $history = $histories
            ->filter(function ($history) use ($dateEnd) {
                return $history->changed_at &&
                    $history->changed_at->lte($dateEnd);
            })
            ->sortByDesc('changed_at')
            ->first();

        if ($history) {
            return $history->to_status;
        }

        /*
        * Fallback untuk data lama yang belum
        * mempunyai status history.
        */
        if (
            $this->created_at &&
            $this->created_at->lte($dateEnd)
        ) {
            return self::STATUS_TO_DO;
        }

        return null;
    }

    public function shouldAppearInDailyReportOn(
        string $date,
    ): bool {
        $day = Carbon::parse($date)
            ->startOfDay();

        $dayEnd = $day->copy()->endOfDay();

        /*
        * Task belum dibuat pada tanggal tersebut.
        */
        if (
            ! $this->created_at ||
            $this->created_at->gt($dayEnd)
        ) {
            return false;
        }

        /*
         * Timeline task menjadi batas awal kemunculan.
         * Task yang dibuat lebih awal tidak boleh masuk ke Daily Report
         * sebelum Start Date-nya tiba.
         */
        if (
            $this->start_date &&
            $day->lt($this->start_date->copy()->startOfDay())
        ) {
            return false;
        }

        $statusOnDate = $this->statusOnDate(
            $day->toDateString(),
        );

        /*
        * Semua task dan subtask selalu muncul
        * pada tanggal pertama kali dibuat,
        * apa pun status awalnya.
        */
        if ($this->created_at->isSameDay($day)) {
            return true;
        }

        /*
        * Selama status pada tanggal tersebut masih
        * in_progress, task harus terus muncul.
        */
        if (
            $statusOnDate ===
            self::STATUS_IN_PROGRESS
        ) {
            return true;
        }

        /*
        * Saat status berubah dari in_progress
        * menjadi status lain, task tetap muncul
        * pada tanggal perubahan tersebut.
        *
        * Contoh:
        * 28 Juli = in_progress
        * 29 Juli = done
        *
        * Maka:
        * - 28 Juli tetap in_progress
        * - 29 Juli tampil done
        * - 30 Juli tidak muncul otomatis
        */
        return $this->statusHistories
            ->contains(function ($history) use ($day) {
                return $history->changed_at
                    ?->isSameDay($day) ?? false;
            });
    }
}
