<?php

use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

/**
 * Seeds the post-payment review-drip templates in prod (deploy runs `migrate`,
 * never `db:seed`). firstOrCreate only adds missing rows, so owner-customized
 * copy is never clobbered. Bodies use {google_review_url} / {yelp_review_url}
 * from the entity (Admin → Entities).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (self::templates() as $key => $data) {
            MessageTemplate::firstOrCreate(
                ['key' => $key],
                $data + ['channel' => 'sms', 'active' => true],
            );
        }
    }

    public function down(): void
    {
        MessageTemplate::whereIn('key', array_keys(self::templates()))->delete();
    }

    private static function templates(): array
    {
        return [
            'review_request_1' => [
                'name' => 'Review — request 1 (right after payment)',
                'body' => "Hi {first_name}, thanks again for choosing {location_name} for your {vehicle} glass service! If you were happy with the work, would you mind leaving us a quick Google review? It takes 30 seconds and means a lot to our small team: {google_review_url} Reply STOP to opt out.",
            ],
            'review_request_2' => [
                'name' => 'Review — request 2 (next-day follow-up)',
                'body' => "Hi {first_name}, it's {location_name}. Just a friendly nudge — if you have a spare moment, a quick Google review would really help us out: {google_review_url} Thank you!",
            ],
            'review_after_call' => [
                'name' => 'Review — after call (connected)',
                'body' => "Hi {first_name}, great talking with you just now! Here's the link to leave {location_name} that review: {google_review_url} Thank you so much — it truly helps!",
            ],
            'review_voicemail' => [
                'name' => 'Review — template 3 (after voicemail)',
                'body' => "Hi {first_name}, sorry we missed you! This is {location_name} — we just wanted to check how everything went with your {vehicle} glass service. If you were happy with it, a quick Google review would mean the world to us: {google_review_url}",
            ],
            'review_final' => [
                'name' => 'Review — final (1 week later)',
                'body' => "Hi {first_name}, one last note from us at {location_name} — if you haven't had the chance yet, here's that review link one more time: {google_review_url} Thanks again for your business!",
            ],
            'review_thank_you' => [
                'name' => 'Review — thank you (review left)',
                'body' => "Hi {first_name}, we just saw your review — thank you SO much! It really helps our {location_name} team. If you ever need glass work again, you know where to find us.",
            ],
        ];
    }
};
