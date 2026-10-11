<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('logo_icon')->default('bi-kanban')->after('logo');
            $table->string('logo_color')->default('#fff433')->after('logo_icon');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['logo_icon', 'logo_color']);
        });
    }
};
