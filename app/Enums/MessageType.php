<?php

namespace App\Enums;

enum MessageType: string
{
    case Welcome = 'welcome';
    case Confirmation = 'confirmation';
    case Reminder = 'reminder';
    case Completion = 'completion';
    case Manual = 'manual';
    /** On-demand Stripe card payment link sent to the customer. */
    case Payment = 'payment';
    /** Post-payment review-request drip texts. */
    case Review = 'review';
    /** Outreach addressed to a technician (no customer), e.g. availability requests. */
    case TechOutreach = 'tech_outreach';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
