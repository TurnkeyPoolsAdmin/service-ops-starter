<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Zelle = 'zelle';
    case Venmo = 'venmo';
    case Cash = 'cash';
    case Stripe = 'stripe';
    case Check = 'check';
    // Xpro-collected: the Xpro team took the customer's money on their own
    // terminal/app, so we still have to collect it back from them. These show up
    // on the "Owed from Xpro" finance page until marked collected.
    case KlarnaXpro = 'klarna_xpro';
    case SwiftSwipeXpro = 'swiftswipe_xpro';
    case OtherXpro = 'other_xpro';

    public function label(): string
    {
        return match ($this) {
            self::Zelle => 'Zelle',
            self::Venmo => 'Venmo',
            self::Cash => 'Cash',
            self::Stripe => 'Stripe/CC',
            self::Check => 'Check',
            self::KlarnaXpro => 'Klarna - Xpro Team',
            self::SwiftSwipeXpro => 'SwiftSwipe (CC) - Xpro Team',
            self::OtherXpro => 'Other - Xpro Team',
        };
    }

    /**
     * The payment methods where the Xpro team collected the money on our behalf,
     * so we still need to collect it back from them (tracked on the Owed from
     * Xpro finance page).
     */
    public static function xproCollected(): array
    {
        return [self::KlarnaXpro, self::SwiftSwipeXpro, self::OtherXpro];
    }

    /** String values of the Xpro-collected methods, for whereIn() queries. */
    public static function xproValues(): array
    {
        return array_map(fn (self $m) => $m->value, self::xproCollected());
    }

    public function isXproCollected(): bool
    {
        return in_array($this, self::xproCollected(), true);
    }

    /** Map for Filament select options. */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all();
    }
}
