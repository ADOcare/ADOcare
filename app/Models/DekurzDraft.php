<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DekurzDraft extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'patient_id',
        'branch_id',
        'period',
        'status',
        'created_by',
        'updated_by',
        'final_document_id',
        'completed_at',
    ];

    protected $casts = [
        'period' => 'date',
        'completed_at' => 'datetime',
    ];

    protected function period(): Attribute
    {
        return Attribute::make(
            set: fn ($value): string => Carbon::parse($value)->startOfMonth()->toDateString(),
        );
    }

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function finalDocument()
    {
        return $this->belongsTo(Document::class, 'final_document_id');
    }

    public function days()
    {
        return $this->hasMany(DekurzDay::class);
    }
}