<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectTask;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class TaskTimelineValidationService
{
    public function validate(
        Project $project,
        ?ProjectTask $parent,
        ?string $startDate,
        ?string $dueDate,
        ?ProjectTask $task = null,
    ): void {
        $start = $startDate ? Carbon::parse($startDate)->startOfDay() : null;
        $due = $dueDate ? Carbon::parse($dueDate)->startOfDay() : null;

        $this->validateBounds($start, $due, $project->start_date, $project->end_date, 'project');

        if ($parent) {
            $this->validateBounds($start, $due, $parent->start_date, $parent->due_date, 'parent task');
        }

        if ($task?->subtasks()->exists()) {
            $earliestSubtaskStart = $task->subtasks()->whereNotNull('start_date')->min('start_date');
            $latestSubtaskDue = $task->subtasks()->whereNotNull('due_date')->max('due_date');

            if ($start && $earliestSubtaskStart && $start->gt(Carbon::parse($earliestSubtaskStart))) {
                $this->fail('start_date', 'Start Date parent tidak boleh melewati Start Date subtask.');
            }

            if ($due && $latestSubtaskDue && $due->lt(Carbon::parse($latestSubtaskDue))) {
                $this->fail('due_date', 'Due Date parent tidak boleh lebih awal dari Due Date subtask.');
            }
        }
    }

    public function validateProject(Project $project, ?string $startDate, ?string $endDate): void
    {
        $start = $startDate ? Carbon::parse($startDate)->startOfDay() : null;
        $end = $endDate ? Carbon::parse($endDate)->startOfDay() : null;

        if ($start && $project->tasks()->whereNotNull('start_date')->whereDate('start_date', '<', $start)->exists()) {
            $this->fail('start_date', 'Start Date project tidak boleh melewati Start Date task yang sudah ada.');
        }

        if ($end && $project->tasks()->whereNotNull('due_date')->whereDate('due_date', '>', $end)->exists()) {
            $this->fail('end_date', 'End Date project tidak boleh lebih awal dari Due Date task yang sudah ada.');
        }
    }

    private function validateBounds(
        ?Carbon $start,
        ?Carbon $due,
        mixed $minimum,
        mixed $maximum,
        string $scope,
    ): void {
        if ($start && $minimum && $start->lt(Carbon::parse($minimum))) {
            $this->fail('start_date', "Start Date harus berada di dalam timeline {$scope}.");
        }

        if ($due && $maximum && $due->gt(Carbon::parse($maximum))) {
            $this->fail('due_date', "Due Date harus berada di dalam timeline {$scope}.");
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
