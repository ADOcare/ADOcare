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
        'custom_track_id',
        'source_entry_id',
        'patient_point_id',
        'patient_point_ids',
        'patient_point_snapshot_id',
        'patient_point_snapshot_ids',
        'quantity',
        'track_position',
        'is_excluded',
        'text',
        'source',
        'edited_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'track_position' => 'integer',
        'is_excluded' => 'boolean',
        'patient_point_ids' => 'array',
        'patient_point_snapshot_ids' => 'array',
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

    public function customTrack()
    {
        return $this->belongsTo(PatientDekurzCustomTrack::class, 'custom_track_id');
    }

    public function sourceEntry()
    {
        return $this->belongsTo(self::class, 'source_entry_id');
    }

    public function patientPoint()
    {
        return $this->belongsTo(PatientPoint::class);
    }

    public function isCustomTrack(): bool
    {
        return $this->custom_track_id !== null;
    }

    public function trackKey(): string
    {
        return $this->isCustomTrack()
            ? 'custom:'.$this->custom_track_id
            : 'procedure:'.$this->procedure_id;
    }
}
