<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DekurzEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'dekurz_day_id',
        'procedure_id',
        'patient_point_id',
        'patient_point_snapshot_id',
        'quantity',
        'text',
        'source',
        'edited_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'edited_at' => 'datetime',
    ];

    public function day()
    {
        return $this->belongsTo(DekurzDay::class, 'dekurz_day_id');
    }

    public function procedure()
    {
        return $this->belongsTo(Procedure::class);
    }

    public function patientPoint()
    {
        return $this->belongsTo(PatientPoint::class);
    }
}