<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonthlyExportRun extends Model
{
    protected $fillable = [
        'user_id',
        'branch_id',
        'month',
        'status',
        'current_step',
        'results',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'month' => 'date:Y-m-d',
            'results' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}