<?php

namespace App\Filament\Resources\MessageTemplates\Pages;

use App\Enums\TemplateCategory;
use App\Filament\Resources\MessageTemplates\MessageTemplateResource;
use App\Models\MessageTemplate;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListMessageTemplates extends ListRecords
{
    protected static string $resource = MessageTemplateResource::class;

    // No create button: templates are fixed by the app's lifecycle keys.
    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * One tab per stage of the job (App\Enums\TemplateCategory) — the primary
     * way to find a text among ~50 rows. Category is derived from the key, so a
     * tab is a key-list filter; the badge counts both language rows.
     */
    public function getTabs(): array
    {
        $tabs = [
            'all' => Tab::make('All')
                ->badge(MessageTemplate::query()->count()),
        ];

        foreach (TemplateCategory::cases() as $category) {
            $tabs[$category->value] = Tab::make($category->label())
                ->modifyQueryUsing(fn (Builder $query) => $query->inCategory($category))
                ->badge(MessageTemplate::query()->inCategory($category)->count())
                ->badgeColor($category->color());
        }

        return $tabs;
    }
}
