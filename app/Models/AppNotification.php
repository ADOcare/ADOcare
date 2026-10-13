<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppNotification extends Model
{
    protected $fillable = [
        'user_id',
        'key',
        'type',
        'kind',
        'title',
        'description',
        'data',
        'occurred_at',
        'dismissed_at',
        'resolved_at',
    ];

    protected $casts = [
        'data' => 'array',
        'occurred_at' => 'datetime',
        'dismissed_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
