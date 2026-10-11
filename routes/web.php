<?php

use App\Http\Controllers\AdminSettingController;
use App\Http\Controllers\DailyReportCommentController;
use App\Http\Controllers\DailyReportController;
use App\Http\Controllers\DailyReportItemFileController;
use App\Http\Controllers\DailyReportItemLinkController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectGroupController;
use App\Http\Controllers\ProjectTaskController;
use App\Http\Controllers\TaskCommentController;
use App\Http\Controllers\TaskFileController;
use App\Http\Controllers\TaskLinkController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::middleware(['auth'])->group(function () {
    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/dashboard',
        [DashboardController::class, 'index'],
    )->name('dashboard');

    /*
    |--------------------------------------------------------------------------
    | Admin Settings
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/admin/settings',
        [AdminSettingController::class, 'index'],
    )->name('admin.settings');

    Route::patch(
        '/admin/users/{user}/approve',
        [AdminSettingController::class, 'approve'],
    )->name('admin.users.approve');

    Route::delete(
        '/admin/users/{user}/reject',
        [AdminSettingController::class, 'reject'],
    )->name('admin.users.reject');

    /*
    |--------------------------------------------------------------------------
    | Daily Report
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/daily-report',
        [DailyReportController::class, 'index'],
    )->name('daily-report.index');

    Route::get(
        '/daily-report/more',
        [DailyReportController::class, 'more'],
    )->name('daily-report.more');

    Route::post(
        '/daily-report/items',
        [DailyReportController::class, 'storeItem'],
    )->name('daily-report.items.store');

    Route::patch(
        '/daily-report/items/{dailyReportItem}',
        [DailyReportController::class, 'updateItem'],
    )->name('daily-report.items.update');

    Route::delete(
        '/daily-report/items/{dailyReportItem}',
        [DailyReportController::class, 'destroyItem'],
    )->name('daily-report.items.destroy');

    Route::post(
        '/daily-report/items/{dailyReportItem}/comments',
        [DailyReportCommentController::class, 'store'],
    )->name('daily-report.items.comments.store');

    /*
    |--------------------------------------------------------------------------
    | Daily Report Files
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/daily-report/items/{dailyReportItem}/files',
        [DailyReportItemFileController::class, 'store'],
    )->middleware('throttle:6,1')->name('daily-report.items.files.store');

    Route::get(
        '/daily-report-item-files/{dailyReportItemFile}/download',
        [DailyReportItemFileController::class, 'download'],
    )->name('daily-report.items.files.download');

    Route::get(
        '/daily-report-item-files/{dailyReportItemFile}/preview',
        [DailyReportItemFileController::class, 'preview'],
    )->name('daily-report.items.files.preview');

    Route::delete(
        '/daily-report-item-files/{dailyReportItemFile}',
        [DailyReportItemFileController::class, 'destroy'],
    )->name('daily-report.items.files.destroy');

    /*
    |--------------------------------------------------------------------------
    | Daily Report Links
    |--------------------------------------------------------------------------
    */

    Route::put(
        '/daily-report/items/{dailyReportItem}/links/sync',
        [DailyReportItemLinkController::class, 'sync'],
    )->name('daily-report.items.links.sync');

    /*
    |--------------------------------------------------------------------------
    | Employees
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/employees',
        [EmployeeController::class, 'index'],
    )->name('employees.index');

    Route::get(
        '/employees/{user}',
        [EmployeeController::class, 'show'],
    )->name('employees.show');

    Route::patch(
        '/employees/{user}',
        [EmployeeController::class, 'update'],
    )->name('employees.update');

    Route::delete(
        '/employees/{user}',
        [EmployeeController::class, 'destroy'],
    )->name('employees.destroy');

    /*
    |--------------------------------------------------------------------------
    | Projects
    |--------------------------------------------------------------------------
    */

    Route::patch(
        '/projects/{project}/archive',
        [ProjectController::class, 'archive'],
    )->name('projects.archive');

    Route::patch(
        '/projects/{project}/unarchive',
        [ProjectController::class, 'unarchive'],
    )->name('projects.unarchive');

    Route::resource(
        'projects',
        ProjectController::class,
    )->only(['index', 'store', 'show', 'update', 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | Project Groups
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/projects/{project}/groups',
        [ProjectGroupController::class, 'store'],
    )->name('projects.groups.store');

    Route::get(
        '/projects/{project}/groups/more',
        [ProjectController::class, 'moreGroups'],
    )->name('projects.groups.more');

    Route::patch(
        '/project-groups/{projectGroup}',
        [ProjectGroupController::class, 'update'],
    )->name('project-groups.update');

    Route::get(
        '/projects/{project}/groups/{projectGroup}/tasks',
        [ProjectController::class, 'groupTasks'],
    )->name('projects.groups.tasks');

    /*
    |--------------------------------------------------------------------------
    | Project Tasks
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/project-tasks',
        [ProjectTaskController::class, 'store'],
    )->name('project-tasks.store');

    Route::get(
        '/project-tasks/{projectTask}',
        [ProjectTaskController::class, 'show'],
    )->name('project-tasks.show');

    Route::patch(
        '/project-tasks/{projectTask}',
        [ProjectTaskController::class, 'update'],
    )->name('project-tasks.update');

    Route::delete(
        '/project-tasks/{projectTask}',
        [ProjectTaskController::class, 'destroy'],
    )->name('project-tasks.destroy');

    Route::patch(
        '/project-tasks/{projectTask}/assignees/toggle',
        [ProjectTaskController::class, 'toggleAssignee'],
    )->name('project-tasks.assignees.toggle');

    /*
    |--------------------------------------------------------------------------
    | Project Task Links
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/project-tasks/{projectTask}/links',
        [TaskLinkController::class, 'store'],
    )->name('project-tasks.links.store');

    Route::put(
        '/project-tasks/{projectTask}/links/sync',
        [TaskLinkController::class, 'sync'],
    )->name('project-tasks.links.sync');

    Route::patch(
        '/task-links/{taskLink}',
        [TaskLinkController::class, 'update'],
    )->name('task-links.update');

    Route::delete(
        '/task-links/{taskLink}',
        [TaskLinkController::class, 'destroy'],
    )->name('task-links.destroy');

    /*
    |--------------------------------------------------------------------------
    | Project Task Files
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/project-tasks/{projectTask}/files',
        [TaskFileController::class, 'store'],
    )->middleware('throttle:6,1')->name('project-tasks.files.store');

    Route::get(
        '/task-files/{taskFile}/download',
        [TaskFileController::class, 'download'],
    )->name('task-files.download');

    Route::get(
        '/task-files/{taskFile}/preview',
        [TaskFileController::class, 'preview'],
    )->name('task-files.preview');

    Route::delete(
        '/task-files/{taskFile}',
        [TaskFileController::class, 'destroy'],
    )->name('task-files.destroy');

    /*
    |--------------------------------------------------------------------------
    | Project Task Comments
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/project-tasks/{projectTask}/comments',
        [TaskCommentController::class, 'store'],
    )->name('project-tasks.comments.store');

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    */

    Route::resource(
        'events',
        EventController::class,
    )->only(['index', 'store', 'show', 'update', 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | Profile
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/profile',
        [ProfileController::class, 'edit'],
    )->name('profile.edit');

    Route::patch(
        '/profile',
        [ProfileController::class, 'update'],
    )->name('profile.update');

    Route::delete(
        '/profile',
        [ProfileController::class, 'destroy'],
    )->name('profile.destroy');

    /*
    |--------------------------------------------------------------------------
    | Exports
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/exports/dashboard',
        [ExportController::class, 'dashboard'],
    )->middleware('throttle:10,1')->name('exports.dashboard');

    Route::get(
        '/exports/daily-report',
        [ExportController::class, 'dailyReport'],
    )->middleware('throttle:10,1')->name('exports.daily-report');

    Route::get(
        '/exports/events',
        [ExportController::class, 'events'],
    )->middleware('throttle:10,1')->name('exports.events');
});

require __DIR__.'/auth.php';
