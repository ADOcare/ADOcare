<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'company';

    protected $fillable = [
        'name',
        'ico',
        'dic',
        'ic_dph',
        'iban',
        'bic',
        'register',
        'address',
        'city',
        'psc',
        'phone',
        'email',
        'invoice_number',
        'latitude',
        'longitude',
        'representative_id',
        'stamp_path',
        'send_notifications',
        'notification_settings',
        'visit_locations',
        'subscription_tier_id',
        'subscription_price_monthly',
        'subscription_users_limit_override',
        'subscription_status',
        'subscription_started_at',
        'subscription_ends_at',
        'subscription_notes',
        'studiokristian_customer_token',
        'status',
        'selected_plan_price_id',
    ];

    protected $casts = [
        'send_notifications' => 'boolean',
        'notification_settings' => 'array',
        'visit_locations' => 'array',
        'subscription_price_monthly' => 'float',
        'subscription_started_at' => 'date',
        'subscription_ends_at' => 'date',
        'studiokristian_customer_token' => 'encrypted',
    ];

    // Server-side only credential - must never be serialized into an API response.
    protected $hidden = [
        'studiokristian_customer_token',
    ];

    // Safe boolean projection of the hidden token, so UIs can show billing provider state.
    protected $appends = [
        'billing_provisioned',
    ];

    public function getBillingProvisionedAttribute(): bool
    {
        return $this->hasBillingCustomerToken();
    }

    /**
     * A missing/null status means the Company predates the onboarding flow - treat it as
     * already active so existing Companies never get forced back through onboarding.
     */
    public function getStatusAttribute(?string $value): string
    {
        return $value ?: 'active';
    }

    public function isOnboarding(): bool
    {
        return $this->status === 'onboarding';
    }

    public function subscriptionTier()
    {
        return $this->belongsTo(SubscriptionTier::class, 'subscription_tier_id', 'id');
    }

    public function branches()
    {
        return $this->hasMany(Branch::class);
    }

    public function episodeTypes()
    {
        return $this->hasMany(AdosEpisodeType::class);
    }

    public function episodeTypeTransitions()
    {
        return $this->hasMany(AdosEpisodeTypeTransition::class);
    }

    public function cars()
    {
        return $this->hasMany(Car::class);
    }

    public function patients()
    {
        return $this->hasManyThrough(Patient::class, Branch::class, 'company_id', 'id', 'id', 'id');
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function representative()
    {
        return $this->belongsTo(User::class, 'representative_id');
    }

    public function subscriptionPayments()
    {
        return $this->hasMany(CompanySubscriptionPayment::class, 'company_id');
    }

    public function subscriptionPaidMonths()
    {
        return $this->hasMany(CompanySubscriptionPaidMonth::class, 'company_id');
    }

    /**
     * Whether this Company has a StudioKristian customer credential provisioned.
     * Until onboarding provisions one, billing features degrade gracefully.
     */
    public function hasBillingCustomerToken(): bool
    {
        return filled($this->studiokristian_customer_token);
    }
}
