<?php

namespace App\Models;

use App\Enums\PatientCoverageRegime;
use App\Enums\PatientSpecialCoverageCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PatientCoverage extends Model
{
    use HasFactory;

    protected $fillable = [
        'patient_id',
        'insurance_company_id',
        'regime',
        'member_state_code',
        'foreign_insured_id',
        'special_category',
        'entitlement_document_type',
        'entitlement_document_number',
        'valid_from',
        'valid_to',
        'is_verified',
    ];

    protected $casts = [
        'regime' => PatientCoverageRegime::class,
        'special_category' => PatientSpecialCoverageCategory::class,
        'valid_from' => 'date',
        'valid_to' => 'date',
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
