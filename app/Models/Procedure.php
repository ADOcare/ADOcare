<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Procedure extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'description',
        'default_dekurz_text',
    ];

    public function patientDekurzTemplates()
    {
        return $this->hasMany(PatientProcedureDekurzTemplate::class);
    }

    public function dekurzEntries()
    {
        return $this->hasMany(DekurzEntry::class);
    }


    // Procedure prices based on insurance companies
    public function insuranceCompaniesPrices()
    {
        $relation = $this->belongsToMany(InsuranceCompany::class, 'procedure_company_prices')
            ->withPivot(['price', 'company_id'])
            ->withTimestamps();

        $user = auth()->user();
        if ($user && !$user->hasGlobalRole('superadmin') && $user->company_id) {
            $relation->wherePivot('company_id', $user->company_id);
        }

        return $relation;
    }

    public function insuranceCompaniesPricesMinimal()
    {
        $relation = $this->belongsToMany(InsuranceCompany::class, 'procedure_company_prices')
            ->select('insurance_companies.id')
            ->withPivot(['price', 'company_id'])
            ->withTimestamps();

        $user = auth()->user();
        if ($user && !$user->hasGlobalRole('superadmin') && $user->company_id) {
            $relation->wherePivot('company_id', $user->company_id);
        }

        return $relation;
    }

}
