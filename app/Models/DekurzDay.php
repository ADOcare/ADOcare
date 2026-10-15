<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DekurzDay extends Model
{
    use HasFactory;

    protected $fillable = [
        'dekurz_draft_id',
        'date',
        'general_note',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function draft()
    {
        return $this->belongsTo(DekurzDraft::class, 'dekurz_draft_id');
    }

    public function entries()
    {
        return $this->hasMany(DekurzEntry::class);
    }
}