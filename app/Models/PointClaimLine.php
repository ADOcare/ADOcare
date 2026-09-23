<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PointClaimLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'batch_id',
        'patient_point_id',
        'coverage_id',
        'previous_claim_line_id',
        'selection_source',
        'status',
        'quantity',
        'unit_price',
        'amount',
        'snapshot',
    ];

    protected $casts = [
        'unit_price' => 'decimal:4',
        'amount' => 'decimal:2',
        'snapshot' => 'array',
    ];

    public function batch()
    {
        return $this->belongsTo(PointClaimBatch::class, 'batch_id');
    }

    public function results()
    {
        return $this->hasMany(PointClaimLineResult::class, 'claim_line_id');
    }
}
