<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InsuranceRule extends Model
{
    protected $fillable = [
        'rule_set_id',
        'code',
        'type',
        'severity',
        'procedure_id',
        'parameters',
        'message',
        'source_reference',
        'enabled',
    ];

    protected $casts = [
        'parameters' => 'array',
        'enabled' => 'boolean',
    ];

    public function ruleSet()
    {
        return $this->belongsTo(InsuranceRuleSet::class, 'rule_set_id');
    }

    public function procedure()
    {
        return $this->belongsTo(Procedure::class);
    }
}