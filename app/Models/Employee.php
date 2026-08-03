<?php

namespace App\Models;

use App\Enums\JobFunction;
use App\Enums\Language;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class Employee extends Authenticatable implements FilamentUser
{
    use HasRoles;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'zelle_payout_name',
        'entity_id',
        'priority',
        'fixed_cost_cents',
        'cash_balance_cents',
        'job_functions',
        'preferred_language',
        'calendar_color',
        'can_login',
        'password',
        'active',
        'eod_summary_texts_enabled',
        'ai_scheduling_enabled',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'job_functions' => 'array',
            'preferred_language' => Language::class,
            'priority' => 'integer',
            'fixed_cost_cents' => 'integer',
            'cash_balance_cents' => 'integer',
            'can_login' => 'boolean',
            'active' => 'boolean',
            'eod_summary_texts_enabled' => 'boolean',
            'ai_scheduling_enabled' => 'boolean',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Only active employees flagged can_login may enter the Filament panel.
     * Role-based authorization is layered on top via Spatie policies.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->can_login && $this->active;
    }

    // ---- Job-function helpers (drive intake/scheduling dropdowns) ----

    public function hasJobFunction(JobFunction|string $fn): bool
    {
        $value = $fn instanceof JobFunction ? $fn->value : $fn;

        return in_array($value, $this->job_functions ?? [], true);
    }

    public function scopeWithJobFunction(Builder $query, JobFunction|string $fn): Builder
    {
        $value = $fn instanceof JobFunction ? $fn->value : $fn;

        return $query->whereJsonContains('job_functions', $value);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /** Techs opted into the nightly end-of-day pay summary text (see TechEodSummaryScheduler). */
    public function scopeEodSummaryEnabled(Builder $query): Builder
    {
        return $query->where('eod_summary_texts_enabled', true);
    }

    // ---- Assignment priority (who to reach for first at a location) ----
    //
    // priority ranks a technician within their location's tech pool — 1 = first
    // choice, 2 = next, and so on. It's pool-wide, not per-entity: LA and SFV
    // share one crew (Entity::techPoolEntityIds), so they share one ranking;
    // Del Mar ranks its own. Null = unranked and always sorts last. Ties are
    // allowed — two #2s just fall back to alphabetical.

    /** Rank first (1, 2, 3…), unranked techs last, alphabetical within a tie. */
    public function scopeOrderByPriority(Builder $query): Builder
    {
        return $query
            ->orderByRaw('priority IS NULL')
            ->orderBy('priority')
            ->orderBy('name');
    }

    /** Only techs carrying a rank. */
    public function scopeRanked(Builder $query): Builder
    {
        return $query->whereNotNull('priority');
    }

    /** "#1", or null when this tech is unranked. */
    public function priorityLabel(): ?string
    {
        return $this->priority === null ? null : '#'.$this->priority;
    }

    /** The tech's SMS language, defaulting to Spanish when unset. */
    public function preferredLanguage(): Language
    {
        return $this->preferred_language ?? Language::Spanish;
    }

    /** How this tech's Zelle payout shows up on Chase, for bank-match name checks. */
    public function zelleMatchName(): string
    {
        return $this->zelle_payout_name ?: $this->name;
    }

    // ---- Relations ----

    /** Location a technician is attached to (optional). */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function jobsCreated(): HasMany
    {
        return $this->hasMany(Job::class, 'created_by');
    }

    public function jobsAsSalesperson(): HasMany
    {
        return $this->hasMany(Job::class, 'booking_salesperson_id');
    }

    public function jobsAsTechnician(): HasMany
    {
        return $this->hasMany(Job::class, 'technician_id');
    }

    public function techSettlements(): HasMany
    {
        return $this->hasMany(TechSettlement::class, 'technician_id');
    }

    /** SECOND review calls this employee logged (ReviewRequest::call_logged_by). */
    public function reviewCallsLogged(): HasMany
    {
        return $this->hasMany(ReviewRequest::class, 'call_logged_by');
    }

    /** FIRST review calls this employee logged (ReviewRequest::first_call_logged_by). */
    public function firstReviewCallsLogged(): HasMany
    {
        return $this->hasMany(ReviewRequest::class, 'first_call_logged_by');
    }

    // ---- Tech cash balance (rolling cash the tech OWES us) ----
    //
    // cash_balance_cents is the persistent running balance: cash the tech
    // collected from customers, which they hold and owe back. It's incremented
    // the moment a cash payment is recorded (JobLifecycle::applyCashToTechBalance),
    // auto-offset when an End-of-Day settlement would owe them pay, and zeroed on
    // Tech Balances when they hand the cash back. Positive = the tech owes us.

    /** Cash (in cents) this technician currently owes the company. */
    public function cashOwedCents(): int
    {
        return max(0, (int) ($this->cash_balance_cents ?? 0));
    }
}
