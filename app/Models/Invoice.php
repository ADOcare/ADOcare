<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'path',
        'insurance_company_id',
        'period',
        'type',
        'total',
        'invoice_number',
        'issued_at',
        'sent_at',
        'due_date',
        'related_invoice_id',
        'mime_type',
    ];

    protected $casts = [
        'issued_at' => 'date',
        'sent_at' => 'date',
        'due_date' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function insuranceCompany()
    {
        return $this->belongsTo(InsuranceCompany::class, 'insurance_company_id');
    }

    public function relatedInvoice()
    {
        return $this->belongsTo(self::class, 'related_invoice_id');
    }
}
