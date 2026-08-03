<?php

namespace App\Enums;

enum OptOutSource: string
{
    case InboundSms = 'inbound_sms';
    case Manual = 'manual';
    case Provider = 'provider';
    case PhoneCall = 'phone_call';

    public function label(): string
    {
        return match ($this) {
            self::InboundSms => 'Inbound SMS',
            self::Manual => 'Manual',
            self::Provider => 'Provider',
            self::PhoneCall => 'Phone call',
        };
    }
}
