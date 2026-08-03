<?php

namespace App\Enums;

enum SmsConsentStatus: string
{
    case Unknown = 'unknown';
    case TransactionalOk = 'transactional_ok';
    case MarketingOk = 'marketing_ok';
    case OptedOut = 'opted_out';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Unknown',
            self::TransactionalOk => 'Transactional OK',
            self::MarketingOk => 'Marketing OK',
            self::OptedOut => 'Opted out',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Unknown => 'gray',
            self::TransactionalOk => 'info',
            self::MarketingOk => 'success',
            self::OptedOut => 'danger',
        };
    }
}
