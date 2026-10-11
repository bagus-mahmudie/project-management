<?php

use App\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique()->after('name');
        });

        Project::query()->get()->each(function ($project) {
            $baseSlug = Str::slug($project->name);
            $slug = $baseSlug;
            $counter = 1;

            while (Project::where('slug', $slug)->whereKeyNot($project->id)->exists()) {
                $slug = "{$baseSlug}-{$counter}";
                $counter++;
            }

            $project->update(['slug' => $slug]);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('slug');
        });
    }
};
