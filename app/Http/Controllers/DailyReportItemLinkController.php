<?php

namespace App\Http\Controllers;

use App\Models\DailyReportItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DailyReportItemLinkController extends Controller
{
    public function sync(
        Request $request,
        DailyReportItem $dailyReportItem,
    ) {
        abort_if(
            $dailyReportItem->source === 'project',
            422,
            'Project links must be edited from Project.',
        );

        $dailyReportItem->loadMissing('report');

        abort_unless(
            $dailyReportItem->report->user_id === auth()->id() ||
            auth()->user()->isSuperAdmin(),
            403,
        );

        $validated = $request->validate([
            'links' => ['present', 'array', 'max:50'],
            'links.*.label' => [
                'nullable',
                'string',
                'max:255',
            ],
            'links.*.url' => [
                'required',
                'url:http,https',
                'max:2048',
            ],
        ]);

        DB::transaction(function () use (
            $dailyReportItem,
            $validated,
        ) {
            $dailyReportItem->links()->delete();

            foreach ($validated['links'] as $link) {
                $dailyReportItem->links()->create([
                    'created_by' => auth()->id(),
                    'label' => $link['label'] ?? null,
                    'url' => $link['url'],
                ]);
            }
        });

        return back();
    }
}
