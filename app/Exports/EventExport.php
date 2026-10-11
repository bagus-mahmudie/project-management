<?php

namespace App\Exports;

use App\Exports\Concerns\SanitizesSpreadsheetCells;
use App\Models\Event;
use Illuminate\Support\Carbon;

class EventExport
{
    use SanitizesSpreadsheetCells;

    public function __construct(
        private readonly string $filter = 'month',
        private readonly string $date = '',
        private readonly ?string $search = null,
    ) {}

    public function headings(): array
    {
        return [
            'Title',
            'Project',
            'Description',
            'Date',
            'Start Time',
            'End Time',
            'Created By',
            'Assignees',
        ];
    }

    public function array(): array
    {
        $date = Carbon::createFromFormat(
            'Y-m-d',
            $this->date ?: now()->toDateString(),
        );

        [$startDate, $endDate] = match ($this->filter) {
            'day' => [$date->copy()->startOfDay(), $date->copy()->endOfDay()],
            'year' => [$date->copy()->startOfYear(), $date->copy()->endOfYear()],
            default => [$date->copy()->startOfMonth(), $date->copy()->endOfMonth()],
        };

        return Event::query()
            ->when(! auth()->user()->isSuperAdmin(), fn ($query) => $query
                ->where(fn ($eventQuery) => $eventQuery
                    ->where('created_by', auth()->id())
                    ->orWhereHas('assignees', fn ($assigneeQuery) => $assigneeQuery->whereKey(auth()->id()))))
            ->whereBetween('event_date', [
                $startDate->toDateString(),
                $endDate->toDateString(),
            ])
            ->when($this->search, fn ($query) => $query->where('title', 'like', "%{$this->search}%"))
            ->with(['project', 'creator', 'assignees'])
            ->latest()
            ->get()
            ->map(function ($event) {
                return $this->sanitizeSpreadsheetRow([
                    $event->title,
                    $event->project?->name,
                    $event->description,
                    $event->event_date?->format('Y-m-d'),
                    $event->start_time,
                    $event->end_time,
                    $event->creator?->name,
                    $event->assignees->pluck('name')->join(', '),
                ]);
            })
            ->toArray();
    }
}
