<?php

namespace App\Models;

use App\Enums\PatientCoverageCategory;
use App\Enums\PatientIdentificationMethod;
use App\Enums\PatientSpecialCoverageCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PatientCoverage extends Model
{
    use HasFactory;

    protected $fillable = [
        'patient_id',
        'insurance_company_id',
        'category',
        'identification_method',
        'member_state_code',
        'foreign_insured_id',
        'special_category',
        'is_verified',
    ];

    protected $casts = [
        'category' => PatientCoverageCategory::class,
        'identification_method' => PatientIdentificationMethod::class,
        'special_category' => PatientSpecialCoverageCategory::class,
        'is_verified' => 'boolean',
    ];

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function insuranceCompany()
    {
        return $this->belongsTo(InsuranceCompany::class);
    }
}
