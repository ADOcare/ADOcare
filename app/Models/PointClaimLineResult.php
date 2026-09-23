<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PointClaimLineResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'claim_line_id',
        'status',
        'error_code',
        'message',
        'accepted_quantity',
        'accepted_amount',
        'received_at',
        'raw_data',
    ];

    protected $casts = [
        'accepted_amount' => 'decimal:2',
        'received_at' => 'datetime',
        'raw_data' => 'array',
    ];
}
