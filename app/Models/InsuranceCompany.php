<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InsuranceCompany extends Model
{
    use HasFactory;

    protected $table = 'insurance_companies';

    protected $fillable = [
        'id',
        'name',
        'address',
        'city',
        'psc',
        'ico',
        'dic',
        'ic_dph',
        'register',
        'code',
        'branch_code'
    ];

    public function patients()
    {
        return $this->hasMany(Patient::class, 'insurance_company_id');
    }

    public function branches()
    {
        return $this->hasMany(Branch::class, 'company_id');
    }

    public function insuranceRuleSets()
    {
        return $this->hasMany(InsuranceRuleSet::class);
    }
}
