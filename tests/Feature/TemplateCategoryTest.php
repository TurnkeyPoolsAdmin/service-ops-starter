<?php

namespace Tests\Feature;

use App\Enums\TemplateCategory;
use App\Enums\TemplateKey;
use App\Models\MessageTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplateCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_template_key_lands_in_exactly_one_category(): void
    {
        $categorized = [];

        foreach (TemplateCategory::cases() as $category) {
            foreach (TemplateKey::valuesForCategory($category) as $value) {
                $categorized[] = $value;
            }
        }

        $keys = array_map(fn (TemplateKey $key) => $key->value, TemplateKey::cases());

        sort($categorized);
        sort($keys);

        $this->assertSame($keys, $categorized);
    }

    public function test_the_category_scope_filters_rows_by_derived_category(): void
    {
        // The templates themselves are seeded by migration.
        $keys = MessageTemplate::query()
            ->inCategory(TemplateCategory::Completion)
            ->pluck('key')
            ->unique();

        $this->assertContains(TemplateKey::JobComplete->value, $keys);
        $this->assertNotContains(TemplateKey::ReminderNightBefore->value, $keys);

        $zelle = MessageTemplate::query()->forKey(TemplateKey::JobComplete)->first();
        $this->assertSame('Job completion', $zelle->category()->label());
    }
}
