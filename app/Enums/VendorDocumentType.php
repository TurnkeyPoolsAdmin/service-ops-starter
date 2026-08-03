<?php

namespace App\Enums;

enum VendorDocumentType: string
{
    case Invoice = 'invoice';
    case CreditMemo = 'credit_memo';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Invoice',
            self::CreditMemo => 'Credit memo',
        };
    }
}
