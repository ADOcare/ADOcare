<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A patient episode with a durable type history.
 */
class AdosEpisode extends Model
{
    protected $fillable = [
        'patient_id',
        'branch_id',
        'company_id',
        'episode_type_id',
        'status',
        'started_at',
        'expected_end_at',
        'ended_at',
        'created_by',
        'ended_by',
    ];

    protected $casts = [
        'started_at' => 'date',
        'expected_end_at' => 'date',
        'ended_at' => 'date',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(AdosEpisodeType::class, 'episode_type_id');
    }

    public function typeHistory(): HasMany
    {
        return $this->hasMany(AdosEpisodeTypeHistory::class, 'ados_episode_id')
            ->orderBy('valid_from')
            ->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }
}
