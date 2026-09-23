<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'patient_id',
        'user_id',
        'type',
        'mime_type',
        'name',
        'path',
        'branch_id',
        'company_id',
        'period',
        'subtype',
        'insurance_company_id',
    ];

    /**
     * Get the patient that owns the document.
     */
    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * Get the user that uploaded the document.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the branch associated with the document.
     */
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function insuranceCompany()
    {
        return $this->belongsTo(\App\Models\InsuranceCompany::class, 'insurance_company_id');
    }
}
