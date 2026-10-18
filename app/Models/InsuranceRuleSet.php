<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property \Illuminate\Support\Carbon $valid_from
 * @property \Illuminate\Support\Carbon|null $valid_to
 * @property \Illuminate\Support\Carbon|null $source_published_at
 */
class InsuranceRuleSet extends Model
{
    protected $fillable = [
        'insurance_company_id',
        'care_type',
        'name',
        'valid_from',
        'valid_to',
        'source_url',
        'source_version',
        'source_published_at',
        'is_active',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_to' => 'date',
        'source_published_at' => 'date',
        'is_active' => 'boolean',
    ];

    public function insuranceCompany()
    {
        return $this->belongsTo(InsuranceCompany::class);
    }

    public function rules()
    {
        return $this->hasMany(InsuranceRule::class, 'rule_set_id');
    }
}