<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProposalAiFeedback extends Model
{
    protected $table = 'proposal_ai_feedback';

    protected $fillable = [
        'document_id',
        'patient_id',
        'user_id',
        'scan_document_id',
        'source',
        'ocr_text',
        'suggestion',
    ];

    protected $casts = [
        'suggestion' => 'array',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
