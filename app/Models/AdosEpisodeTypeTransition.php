<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Defines an allowed episode-type change for one company.
 */
class AdosEpisodeTypeTransition extends Model
{
    protected $fillable = [
        'company_id',
        'from_episode_type_id',
        'to_episode_type_id',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function fromType(): BelongsTo
    {
        return $this->belongsTo(AdosEpisodeType::class, 'from_episode_type_id');
    }

    public function toType(): BelongsTo
    {
        return $this->belongsTo(AdosEpisodeType::class, 'to_episode_type_id');
    }
}
