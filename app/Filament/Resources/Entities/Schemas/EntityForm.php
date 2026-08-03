<?php

namespace App\Filament\Resources\Entities\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class EntityForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->helperText('Short code: LA, SFV, DelMar')
                    ->required()
                    ->maxLength(20)
                    ->unique(ignoreRecord: true),
                TextInput::make('name')
                    ->label('Business name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('location_name')
                    ->helperText('Used as {location_name} in SMS templates')
                    ->required()
                    ->maxLength(255),
                Textarea::make('address')
                    ->label('Mailing address')
                    ->helperText('Shown on the PDF receipt letterhead. One line each, e.g. "123 Main St" then "Los Angeles, CA 90001".')
                    ->placeholder("123 Main St\nLos Angeles, CA 90001")
                    ->rows(2)
                    ->maxLength(500),
                TextInput::make('google_ads_customer_id')
                    ->label('Google Ads customer ID')
                    ->helperText('10-digit account ID for this location, digits only (no dashes). Drives the ROAS dashboard.')
                    ->placeholder('1234567890')
                    ->maxLength(20)
                    ->dehydrateStateUsing(fn (?string $state) => $state === null ? null : (preg_replace('/\D/', '', $state) ?: null)),
                TextInput::make('google_review_url')
                    ->label('Google review URL')
                    ->helperText('The "leave us a review" GBP link. Used as {google_review_url} in SMS templates — review-drip texts will not send until this is set.')
                    ->url()
                    ->maxLength(500),
                TextInput::make('yelp_review_url')
                    ->label('Yelp review URL')
                    ->helperText('Used as {yelp_review_url} in SMS templates (optional).')
                    ->url()
                    ->maxLength(500),
                TextInput::make('zelle_details')
                    ->label('Zelle payment details')
                    ->helperText('Where customers Zelle their payment (phone/email + account name). Used as {zelle_details} in SMS templates — the Zelle payment text will not send until this is set.')
                    ->placeholder('(323) 555-0000 — Your Business LLC')
                    ->maxLength(255),
                TextInput::make('venmo_details')
                    ->label('Venmo payment details')
                    ->helperText('The Venmo handle customers pay. Used as {venmo_details} in SMS templates — the Venmo payment text will not send until this is set.')
                    ->placeholder('@Your-Business')
                    ->maxLength(255),
                Toggle::make('active')->default(true),
            ]);
    }
}
