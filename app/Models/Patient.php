<?php

namespace App\Models;

use App\Models\Concerns\CompanyBranchScopes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Patient extends Model
{
    use CompanyBranchScopes, HasFactory, SoftDeletes;

    protected $table = 'patients';

    protected $fillable = ['first_name', 'last_name', 'title', 'personal_number', 'sex', 'contact', 'country_code_phone', 'phone', 'doctor_id', 'insurance_company_id', 'address', 'city', 'zip', 'latitude', 'longitude', 'reference_date', 'dekurz_number', 'branch_id', 'nurse_id', 'death_date'];

    /**
     * Limit the query to patients without a recorded death date.
     *
     * @param  Builder<Patient>  $query
     * @return Builder<Patient>
     */
    public function scopeLiving(Builder $query): Builder
    {
        return $query->whereNull('death_date');
    }

    public function nurse()
    {
        return $this->belongsTo(User::class, 'nurse_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function doctor()
    {
        return $this->belongsTo(Doctor::class);
    }

    public function visits()
    {
        return $this->hasMany(Visit::class);
    }

    public function dekurzProcedureTemplates()
    {
        return $this->hasMany(PatientProcedureDekurzTemplate::class);
    }

    public function dekurzDrafts()
    {
        return $this->hasMany(DekurzDraft::class);
    }

    public function insuranceCompany()
    {
        return $this->belongsTo(InsuranceCompany::class, 'insurance_company_id');
    }

    public function coverages()
    {
        return $this->hasMany(PatientCoverage::class);
    }

    /**
     * Get the quality-audit issues recorded for the patient.
     *
     * @return HasMany<PatientAuditIssue, $this>
     */
    public function patientAuditIssues(): HasMany
    {
        return $this->hasMany(PatientAuditIssue::class);
    }

    /**
     * Get unresolved quality-audit findings for the patient.
     *
     * @return HasMany<PatientAuditIssue, $this>
     */
    public function openPatientAuditIssues(): HasMany
    {
        return $this->patientAuditIssues()->where('status', PatientAuditIssue::STATUS_OPEN);
    }

    public function adosEpisodes(): HasMany
    {
        return $this->hasMany(AdosEpisode::class)
            ->whereHas('patient.branch', fn ($query) => $query->whereColumn('branches.company_id', 'ados_episodes.company_id'))
            ->orderByDesc('started_at')
            ->orderByDesc('id');
    }

    public function activeAdosEpisode()
    {
        return $this->hasOne(AdosEpisode::class)
            ->where('status', 'active')
            ->whereHas('patient.branch', fn ($query) => $query->whereColumn('branches.company_id', 'ados_episodes.company_id'));
    }

    public function currentCoverage()
    {
        return $this->latestCoverage();
    }

    public function latestCoverage()
    {
        return $this->hasOne(PatientCoverage::class)->latestOfMany();
    }
}
