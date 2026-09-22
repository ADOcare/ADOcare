<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\CompanyBranchScopes;

class Patient extends Model
{
    use HasFactory, SoftDeletes, CompanyBranchScopes;

    protected $table = 'patients';

    protected $fillable = ['first_name', 'last_name', 'title', 'personal_number', 'sex', 'contact', 'doctor_id', 'insurance_company_id', 'address', 'city', 'zip', 'latitude', 'longitude', 'reference_date', 'dekurz_number', 'branch_id', 'nurse_id', 'death_date'];

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

    public function insuranceCompany()
    {
        return $this->belongsTo(InsuranceCompany::class, 'insurance_company_id');
    }

    public function coverages()
    {
        return $this->hasMany(PatientCoverage::class);
    }

    public function currentCoverage()
    {
        return $this->hasOne(PatientCoverage::class)
            ->where(function ($query) {
                $query->whereNull('valid_from')
                    ->orWhereDate('valid_from', '<=', today());
            })
            ->where(function ($query) {
                $query->whereNull('valid_to')
                    ->orWhereDate('valid_to', '>=', today());
            })
            ->orderByRaw("COALESCE(valid_from, DATE '0001-01-01') DESC")
            ->orderByDesc('id');
    }

    public function latestCoverage()
    {
        return $this->hasOne(PatientCoverage::class)->latestOfMany();
    }

}
