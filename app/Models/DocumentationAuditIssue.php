<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentationAuditIssue extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';
    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'company_id',
        'branch_id',
        'patient_id',
        'user_id',
        'period',
        'rule_code',
        'severity',
        'date',
        'procedure_id',
        'patient_point_id',
        'document_id',
        'fingerprint',
        'status',
        'first_detected_at',
        'last_detected_at',
        'resolved_at',
        'metadata',
    ];

    protected $casts = [
        'date' => 'date',
        'first_detected_at' => 'datetime',
        'last_detected_at' => 'datetime',
        'resolved_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function patientPoint()
    {
        return $this->belongsTo(PatientPoint::class);
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }
}