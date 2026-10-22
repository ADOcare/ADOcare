<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PatientDekurzCustomTrack extends Model
{
    use HasFactory;

    protected $fillable = [
        'patient_id',
        'legacy_template_id',
        'name',
        'current_text',
    ];

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function legacyTemplate()
    {
        return $this->belongsTo(PatientProcedureDekurzTemplate::class, 'legacy_template_id');
    }

    public function periods()
    {
        return $this->hasMany(PatientDekurzCustomTrackPeriod::class, 'custom_track_id');
    }

    public function entries()
    {
        return $this->hasMany(DekurzEntry::class, 'custom_track_id');
    }
}
