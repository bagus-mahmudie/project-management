<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyReportItemLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'daily_report_item_id',
        'created_by',
        'label',
        'url',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(
            DailyReportItem::class,
            'daily_report_item_id',
        );
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
