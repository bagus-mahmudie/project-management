<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('role', 'admin')->update(['role' => 'user']);

        if (! app()->environment(['local', 'testing'])) {
            DB::table('users')
                ->where('email', 'superadmin@example.com')
                ->where('role', 'super_admin')
                ->update(['password' => Hash::make(Str::random(64))]);
        }

        $reports = DB::table('daily_reports')
            ->select('id', 'user_id', 'report_date')
            ->orderBy('id')
            ->get()
            ->groupBy(fn ($report) => $report->user_id.'|'.Carbon::parse($report->report_date)->toDateString());

        foreach ($reports as $group) {
            $keeper = $group->first();
            $normalizedDate = Carbon::parse($keeper->report_date)->toDateString();

            foreach ($group->skip(1) as $duplicate) {
                DB::table('daily_report_items')
                    ->where('daily_report_id', $duplicate->id)
                    ->update(['daily_report_id' => $keeper->id]);
                DB::table('daily_reports')->where('id', $duplicate->id)->delete();
            }

            DB::table('daily_reports')->where('id', $keeper->id)->update([
                'report_date' => $normalizedDate,
            ]);
        }
    }

    public function down(): void
    {
        // Role lama tidak dikembalikan karena tidak dapat dibedakan dari user biasa.
    }
};
