<?php

use App\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Project::query()
            ->whereNull('slug')
            ->orWhere('slug', '')
            ->get()
            ->each(function ($project) {
                $baseSlug = Str::slug($project->name) ?: 'project';
                $slug = $baseSlug;
                $counter = 1;

                while (
                    Project::query()
                        ->where('slug', $slug)
                        ->whereKeyNot($project->id)
                        ->exists()
                ) {
                    $slug = "{$baseSlug}-{$counter}";
                    $counter++;
                }

                $project->forceFill([
                    'slug' => $slug,
                ])->save();
            });
    }

    public function down(): void
    {
        //
    }
};
