<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Records a half-open [valid_from, valid_to) date range for an episode type.
 */
class AdosEpisodeTypeHistory extends Model
{
    protected $table = 'ados_episode_type_history';

    protected $fillable = [
        'ados_episode_id',
        'episode_type_id',
        'valid_from',
        'valid_to',
        'changed_by',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_to' => 'date',
    ];

    public function episode(): BelongsTo
    {
        return $this->belongsTo(AdosEpisode::class, 'ados_episode_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(AdosEpisodeType::class, 'episode_type_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
