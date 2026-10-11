<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyReportSyncCursor extends Model
{
    protected $fillable = [
        'user_id',
        'last_synced_date',
    ];

    protected $casts = [
        'last_synced_date' => 'date:Y-m-d',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
