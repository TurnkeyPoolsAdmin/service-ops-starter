<?php

namespace App\Enums;

/**
 * A person's SMS language. Every message template has one row per language
 * (message_templates is unique on key+language), and the recipient's preference
 * picks the row: Customer::preferredLanguage() for customer texts,
 * Employee::preferredLanguage() for tech texts. A missing or inactive row falls
 * back to English (see TemplateRenderer::resolveTemplate).
 *
 * Customers default to English; techs default to Spanish — the crews are
 * Spanish-speaking (as the availability outreach has always assumed).
 */
enum Language: string
{
    case English = 'en';
    case Spanish = 'es';

    public function label(): string
    {
        return match ($this) {
            self::English => 'English',
            self::Spanish => 'Spanish',
        };
    }

    /** Short badge text for tables ("EN" / "ES"). */
    public function code(): string
    {
        return strtoupper($this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::English => 'gray',
            self::Spanish => 'success',
        };
    }

    /** Options map for a Filament Select/Radio (value => label). */
    public static function options(): array
    {
        return [
            self::English->value => self::English->label(),
            self::Spanish->value => self::Spanish->label(),
        ];
    }
}
