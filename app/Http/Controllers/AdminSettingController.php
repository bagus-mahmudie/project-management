<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class AdminSettingController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        return Inertia::render('Admin/Settings', [
            'projects' => Project::query()
                ->select(['id', 'name', 'slug', 'logo', 'logo_icon', 'logo_color', 'archived_at', 'created_at'])
                ->latest()
                ->get(),
            'users' => User::with('profile')
                ->where('id', '!=', auth()->id())
                ->where('role', '!=', 'super_admin')
                ->where('approval_status', '!=', 'inactive')
                ->latest()
                ->get(),
        ]);
    }

    public function approve(User $user)
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        $user->update([
            'approval_status' => 'approved',
            'approved_at' => now(),
            'approved_by' => auth()->id(),
        ]);

        return back();
    }

    public function reject(User $user)
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        if ($user->isSuperAdmin()) {
            abort(403, 'Super admin tidak bisa dinonaktifkan.');
        }

        abort_if(
            $user->ownsProjects(),
            422,
            'Ownership project harus dipindahkan sebelum user dinonaktifkan.',
        );

        DB::transaction(function () use ($user) {
            $user->update([
                'approval_status' => 'inactive',
                'approved_at' => null,
                'approved_by' => null,
            ]);

            DB::table('sessions')->where('user_id', $user->id)->delete();
        });

        return back();
    }
}
