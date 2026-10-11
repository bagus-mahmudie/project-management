<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Project extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (Project $project) {
            $project->tasks()->whereNull('parent_id')->each(fn (ProjectTask $task) => $task->delete());

            if ($project->logo) {
                Storage::disk('public')->delete($project->logo);
            }
        });
    }

    protected $fillable = [
        'created_by',
        'name',
        'slug',
        'description',
        'start_date',
        'end_date',
        'archived_at',
        'logo',
        'logo_icon',
        'logo_color',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'archived_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function owner(): BelongsToMany
    {
        return $this->members()->wherePivot('role', 'owner');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(ProjectGroup::class)->orderBy('position');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function isMember(User $user): bool
    {
        return $this->members()->whereKey($user->id)->exists();
    }

    public function isOwner(User $user): bool
    {
        return $this->members()
            ->whereKey($user->id)
            ->wherePivot('role', 'owner')
            ->exists();
    }

    protected $appends = [
        'logo_url',
    ];

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo ? Storage::url($this->logo) : null;
    }
}
