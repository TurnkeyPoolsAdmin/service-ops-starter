<?php

use App\Services\Vendors\Mygrant\BillingCycle;

// Every expected value below is independently verified against 228 real
// invoice groups (see the class doc comment on BillingCycle) — these cases
// specifically exercise the month-end override, the Sunday-input identity
// rule, and a settle date that has to skip an already-passed Wednesday.

it('cuts on the next Sunday for an ordinary mid-month confirmed date', function () {
    // Friday 6/26 -> next Sunday 6/28 (June has plenty of days left).
    expect(BillingCycle::cutDate('2026-06-26'))->toBe('2026-06-28');
});

it('shortens the period to month-end when the next Sunday would cross into next month', function () {
    // Monday 6/29 -> next Sunday would be 7/5, but June ends 6/30 first.
    expect(BillingCycle::cutDate('2026-06-29'))->toBe('2026-06-30');
});

it('still shortens to month-end even when the confirmed date itself is the last day', function () {
    // Tuesday 6/30 -> next Sunday would be 7/5, June ends today.
    expect(BillingCycle::cutDate('2026-06-30'))->toBe('2026-06-30');
});

it('a confirmed date that already falls on a Sunday cuts on itself', function () {
    expect(BillingCycle::cutDate('2026-06-28'))->toBe('2026-06-28');
});

it('rolls a mid-week confirmed date to the following Sunday across a month boundary that has room', function () {
    // Wednesday 7/8 -> next Sunday 7/12 (well inside July, no shortening needed).
    expect(BillingCycle::cutDate('2026-07-08'))->toBe('2026-07-12');
});

it('settles on the Wednesday exactly 3 days after a Sunday cut', function () {
    // 6/28 (Sun) + 3 days = 7/1, which IS already a Wednesday.
    expect(BillingCycle::settleDate('2026-06-28'))->toBe('2026-07-01');
});

it('settles on the following Sunday-plus-3 Wednesday with room to spare', function () {
    // 7/12 (Sun) + 3 days = 7/15, which IS already a Wednesday.
    expect(BillingCycle::settleDate('2026-07-12'))->toBe('2026-07-15');
});

it('skips forward to the NEXT Wednesday when cut_date + 3 days lands past one', function () {
    // 6/30 (Tue) + 3 days = 7/3 (Friday) — 7/1's Wednesday has already
    // passed, so settlement has to wait a full week for 7/8.
    expect(BillingCycle::settleDate('2026-06-30'))->toBe('2026-07-08');
});
