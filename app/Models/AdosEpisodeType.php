<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company-configurable type used to classify ADOS episodes.
 */
class AdosEpisodeType extends Model
{
    protected $fillable = [
        'company_id',
        'name',
        'code',
        'description',
        'default_duration_days',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'default_duration_days' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function episodes(): HasMany
    {
        return $this->hasMany(AdosEpisode::class, 'episode_type_id');
    }

    public function outgoingTransitions(): HasMany
    {
        return $this->hasMany(AdosEpisodeTypeTransition::class, 'from_episode_type_id');
    }

    public function incomingTransitions(): HasMany
    {
        return $this->hasMany(AdosEpisodeTypeTransition::class, 'to_episode_type_id');
    }
}
