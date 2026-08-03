<?php

namespace App\Models;

use App\Enums\Audience;
use App\Enums\Language;
use App\Enums\TemplateCategory;
use App\Enums\TemplateKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;

/**
 * One editable SMS body per (key, language). The key is the lifecycle event
 * (App\Enums\TemplateKey); the language is picked at send time from the
 * recipient's preference, falling back to English (TemplateRenderer).
 */
class MessageTemplate extends Model
{
    protected $fillable = [
        'key',
        'language',
        'name',
        'body',
        'channel',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'language' => Language::class,
            'active' => 'boolean',
        ];
    }

    /** The lifecycle event this row's body belongs to, or null for a stale key. */
    public function templateKey(): ?TemplateKey
    {
        return TemplateKey::tryFrom($this->key);
    }

    /** Customer- or tech-facing. Derived from the key, never stored. */
    public function audience(): ?Audience
    {
        return $this->templateKey()?->audience();
    }

    /** Which stage of the job this row belongs to. Derived from the key. */
    public function category(): ?TemplateCategory
    {
        return $this->templateKey()?->category();
    }

    public function scopeForKey(Builder $query, TemplateKey|string $key): Builder
    {
        return $query->where('key', $key instanceof TemplateKey ? $key->value : $key);
    }

    /** Rows addressed to $audience — expressed as a key list, since audience is derived. */
    public function scopeForAudience(Builder $query, Audience $audience): Builder
    {
        return $query->whereIn('key', TemplateKey::valuesFor($audience));
    }

    /** Rows in $category — expressed as a key list, since category is derived. */
    public function scopeInCategory(Builder $query, TemplateCategory $category): Builder
    {
        return $query->whereIn('key', TemplateKey::valuesForCategory($category));
    }

    /** The sibling row for this key in the other language, if it exists. */
    public function sibling(Language $language): ?self
    {
        return static::query()->forKey($this->key)->where('language', $language->value)->first();
    }

    /**
     * Spanish rows whose English sibling was edited more recently — the
     * translation may no longer say the same thing.
     *
     * Staleness is DERIVED from the two rows' updated_at, with no stored flag:
     * saving the Spanish row clears it for free, and three English edits in a
     * row collapse into one condition instead of piling up three of them. The
     * Issues sweep materializes this onto the queue (SweepJobIssues).
     */
    public static function staleTranslations(): EloquentCollection
    {
        $rows = static::query()->get();
        $english = $rows->where('language', Language::English)->keyBy('key');

        return $rows
            ->where('language', Language::Spanish)
            ->filter(function (self $es) use ($english) {
                $en = $english->get($es->key);

                return $en !== null && $en->updated_at !== null
                    && $en->updated_at->gt($es->updated_at);
            })
            ->values();
    }

    /**
     * "The Spanish is still accurate" — bumps updated_at past the English row's
     * without editing the body, which is what clears the stale condition when
     * the English edit was cosmetic and needs no re-translation.
     */
    public function markTranslationReviewed(): void
    {
        $this->touch();
    }
}
