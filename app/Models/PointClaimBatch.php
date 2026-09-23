<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PointClaimBatch extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'document_id',
        'company_id',
        'branch_id',
        'healthcare_worker_id',
        'created_by',
        'insurance_company_id',
        'batch_type',
        'accounting_period',
        'batch_number',
        'invoice_number',
        'special_category',
        'status',
        'total_amount',
        'finalized_at',
        'exported_at',
    ];

    protected $casts = [
        'accounting_period' => 'date',
        'total_amount' => 'decimal:2',
        'finalized_at' => 'datetime',
        'exported_at' => 'datetime',
    ];

    public function lines()
    {
        return $this->hasMany(PointClaimLine::class, 'batch_id');
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }
}
