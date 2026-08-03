<?php

namespace App\Filament\Resources\MessageTemplates\Schemas;

use App\Enums\Language;
use App\Enums\TemplateKey;
use App\Services\Messaging\TemplateRenderer;
use App\Support\GsmText;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class MessageTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        $keyOptions = collect(TemplateKey::cases())
            ->mapWithKeys(fn (TemplateKey $k) => [$k->value => $k->label()])
            ->all();

        return $schema
            ->components([
                // Rows are fixed by the lifecycle: the resource can't create or
                // delete them, so key+language are locked once a row exists.
                Select::make('key')
                    ->options($keyOptions)
                    ->required()
                    ->live()
                    ->disabledOn('edit')
                    ->helperText('The lifecycle event this template fires on.'),
                Select::make('language')
                    ->options(Language::options())
                    ->required()
                    ->default(Language::English->value)
                    ->disabledOn('edit')
                    ->helperText('Sent to recipients who prefer this language. A missing or switched-off translation falls back to English.'),
                TextInput::make('name')->required()->maxLength(255),
                Textarea::make('body')
                    ->required()
                    ->rows(4)
                    ->rules([
                        fn (Get $get): Closure => static::validateBody($get('key')),
                    ])
                    ->helperText(fn (Get $get): string => static::varHint($get('key')))
                    ->columnSpanFull(),
                Toggle::make('active')->default(true),
            ]);
    }

    /**
     * Body validation. Three things the editor used to accept silently, all of
     * which shipped to customers:
     *
     *  1. An unknown {token}. The renderer blanks anything it doesn't know, so
     *     a typo'd var name is invisible until a customer reads the gap — this
     *     is exactly how {technician_name} sent "One small favor:  worked on
     *     your vehicle today" on every completed job.
     *  2. Opt-out language in the wrong place. Review texts must offer STOP;
     *     transactional ones must not (see TemplateKey::requiresOptOutLanguage).
     *  3. Smart quotes/em-dashes (2026-07-17). One curly ' or — anywhere in the
     *     body forces the ENTIRE message out of GSM-7 into UCS-2 encoding —
     *     dropping the per-segment limit from 153 to 67 chars, so a ~760-char
     *     template goes from ~5 segments to ~12. Long multi-part UCS-2 texts
     *     are exactly the kind carriers/A2P filters silently drop even though
     *     the provider API already returned success — this is what caused the
     *     "Job Complete" text to a customer (Paul, 626-390-7227) to never
     *     arrive automatically, yet send fine on a manual resend (which
     *     retyped/auto-corrected the punctuation). Straight quotes/hyphens
     *     only — always, in every language, for every future template.
     */
    private static function validateBody(?string $key): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($key): void {
            $body = (string) $value;

            preg_match_all('/\{(\w+)\}/', $body, $matches);
            $unknown = array_diff(array_unique($matches[1]), self::knownVars());

            if ($unknown !== []) {
                $fail('Unknown merge variable(s): '.collect($unknown)->map(fn ($v) => '{'.$v.'}')->implode(' ')
                    .'. These render as empty text. Use only the variables listed below.');
            }

            $badChars = GsmText::offendingChars($body);

            if ($badChars !== []) {
                $suggestions = collect($badChars)
                    ->map(fn (string $c) => "\"{$c}\" \u{2192} \"".GsmText::REPLACEMENTS[$c].'"')
                    ->implode(', ');
                $fail("Smart quotes/dashes found ({$suggestions}). These force the whole text into UCS-2 SMS "
                    .'encoding (67 chars/segment instead of 153), which can make long messages silently fail to '
                    .'deliver. Use straight quotes and hyphens instead.');
            }

            $tplKey = $key ? TemplateKey::tryFrom($key) : null;
            if ($tplKey === null) {
                return;
            }

            $offersOptOut = stripos($body, 'STOP') !== false;

            if ($tplKey->requiresOptOutLanguage() && ! $offersOptOut) {
                $fail('Review texts must tell the customer how to opt out — include "Reply STOP to opt out." '
                    .'(Spanish: "Responde STOP para dejar de recibir mensajes.").');
            }

            if (! $tplKey->requiresOptOutLanguage() && $offersOptOut) {
                $fail('Only review texts may offer an opt-out. Opting out of this message would also stop the '
                    .'customer\'s appointment and payment texts, so remove the STOP line.');
            }
        };
    }

    /** Every var the renderer resolves, including undocumented back-compat aliases. */
    private static function knownVars(): array
    {
        return array_merge(TemplateRenderer::SUPPORTED_VARS, ['date_time']);
    }

    private static function varHint(?string $key): string
    {
        $all = 'Available: '.collect(TemplateRenderer::SUPPORTED_VARS)
            ->map(fn ($v) => '{'.$v.'}')->implode(' ');

        $tplKey = $key ? TemplateKey::tryFrom($key) : null;
        if ($tplKey && ! empty($tplKey->requiredVars())) {
            $required = collect($tplKey->requiredVars())->map(fn ($v) => '{'.$v.'}')->implode(' ');
            $all .= "  •  Required: {$required}";
        }
        if ($tplKey && $tplKey->requiresOptOutLanguage()) {
            $all .= '  •  Must include opt-out language (e.g. "Reply STOP to opt out.").';
        } elseif ($tplKey) {
            $all .= '  •  Must NOT offer an opt-out — only review texts may.';
        }

        return $all;
    }
}
