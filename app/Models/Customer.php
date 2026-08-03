<?php

namespace App\Models;

use App\Enums\ContactPref;
use App\Enums\Language;
use App\Enums\OptOutSource;
use App\Enums\SmsConsentStatus;
use App\Support\Name;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Customer extends Model
{
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'phone_e164',
        'first_name',
        'last_name',
        'email',
        'city',
        'contact_pref',
        'preferred_language',
        'do_not_text',
        'auto_text_paused',
        'sms_consent_status',
        'opted_out_at',
        'opt_out_source',
        'opt_out_text',
        'last_inbound_at',
        'quo_conversation_url',
    ];

    protected function casts(): array
    {
        return [
            'contact_pref' => ContactPref::class,
            'preferred_language' => Language::class,
            'do_not_text' => 'boolean',
            'auto_text_paused' => 'boolean',
            'sms_consent_status' => SmsConsentStatus::class,
            'opt_out_source' => OptOutSource::class,
            'opted_out_at' => 'datetime',
            'last_inbound_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'first_name', 'last_name', 'email', 'phone_e164', 'preferred_language',
                'do_not_text', 'auto_text_paused', 'sms_consent_status', 'opted_out_at', 'opt_out_source',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}") ?: '(no name)';
    }

    /** The customer's SMS language, defaulting to English when unset. */
    public function preferredLanguage(): Language
    {
        return $this->preferred_language ?? Language::English;
    }

    public function isSpanishSpeaker(): bool
    {
        return $this->preferredLanguage() === Language::Spanish;
    }

    /** Normalize ALL-CAPS / all-lowercase pastes ("JOHN" → "John") on write. */
    protected function firstName(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => Name::normalizeCase($value));
    }

    protected function lastName(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => Name::normalizeCase($value));
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(Job::class);
    }

    public function scheduledMessages(): HasMany
    {
        return $this->hasMany(ScheduledMessage::class);
    }

    public function inboundMessages(): HasMany
    {
        return $this->hasMany(InboundMessage::class);
    }
}
