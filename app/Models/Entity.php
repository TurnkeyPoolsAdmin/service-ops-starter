<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Entity extends Model
{
    protected $fillable = [
        'code',
        'name',
        'location_name',
        'address',
        'google_ads_customer_id',
        'google_review_url',
        'yelp_review_url',
        'zelle_details',
        'venmo_details',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    // LA and SFV crews overlap — techs registered at either entity work jobs
    // for both, so they're pooled together anywhere we offer "which techs
    // cover this job's location" (tech-availability texting).
    private const TECH_POOL_CODES = ['LA', 'SFV'];

    private const TECH_POOL_LABEL = 'SFV / AGLA';

    public function jobs(): HasMany
    {
        return $this->hasMany(Job::class);
    }

    /** IDs of every entity that shares a tech pool with this one (including itself). */
    public function techPoolEntityIds(): array
    {
        if (in_array($this->code, self::TECH_POOL_CODES, true)) {
            return static::whereIn('code', self::TECH_POOL_CODES)->pluck('id')->all();
        }

        return [$this->id];
    }

    /** Display label for the tech-availability picker: pooled group name or this entity's own. */
    public function techPoolLabel(): string
    {
        return in_array($this->code, self::TECH_POOL_CODES, true)
            ? self::TECH_POOL_LABEL
            : $this->name;
    }

    public function adMetrics(): HasMany
    {
        return $this->hasMany(AdMetric::class);
    }
}
