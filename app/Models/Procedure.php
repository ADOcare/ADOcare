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

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'procedure_role');
    }

    public function patientDekurzTemplates()
    {
        return $this->hasMany(PatientProcedureDekurzTemplate::class);
    }

    public function dekurzEntries()
    {
        return $this->hasMany(DekurzEntry::class);
    }

    public function insuranceRules()
    {
        return $this->hasMany(InsuranceRule::class);
    }

    // Procedure prices based on insurance companies
    public function insuranceCompaniesPrices()
    {
        $relation = $this->belongsToMany(InsuranceCompany::class, 'procedure_company_prices')
            ->withPivot(['price', 'company_id', 'valid_from', 'valid_to'])
            ->withTimestamps();
        $this->scopePriceRelationToDate($relation);

        $user = auth()->user();
        if ($user && ! $user->hasGlobalRole('superadmin') && $user->company_id) {
            $relation->wherePivot('company_id', $user->company_id);
        }

        return $relation;
    }

    public function insuranceCompaniesPricesMinimal()
    {
        $relation = $this->belongsToMany(InsuranceCompany::class, 'procedure_company_prices')
            ->select('insurance_companies.id')
            ->withPivot(['price', 'company_id', 'valid_from', 'valid_to'])
            ->withTimestamps();
        $this->scopePriceRelationToDate($relation);

        $user = auth()->user();
        if ($user && ! $user->hasGlobalRole('superadmin') && $user->company_id) {
            $relation->wherePivot('company_id', $user->company_id);
        }

        return $relation;
    }

    private function scopePriceRelationToDate($relation): void
    {
        $today = now()->toDateString();
        $relation
            ->where(function ($query) use ($today): void {
                $query->whereNull('procedure_company_prices.valid_from')
                    ->orWhereDate('procedure_company_prices.valid_from', '<=', $today);
            })
            ->where(function ($query) use ($today): void {
                $query->whereNull('procedure_company_prices.valid_to')
                    ->orWhereDate('procedure_company_prices.valid_to', '>=', $today);
            });
    }
}
