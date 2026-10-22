<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PatientDekurzCustomTrackPeriod extends Model
{
    use HasFactory;

    protected $fillable = [
        'custom_track_id',
        'period',
        'distribution',
        'selected_dates',
    ];

    protected $casts = [
        'period' => 'date',
        'selected_dates' => 'array',
    ];

    protected function period(): Attribute
    {
        return Attribute::make(
            set: fn ($value): string => Carbon::parse($value)->startOfMonth()->toDateString(),
        );
    }

    public function track()
    {
        return $this->belongsTo(PatientDekurzCustomTrack::class, 'custom_track_id');
    }
}
