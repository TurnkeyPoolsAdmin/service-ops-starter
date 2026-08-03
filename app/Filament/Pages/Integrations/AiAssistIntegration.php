<?php

namespace App\Filament\Pages\Integrations;

use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;

/**
 * The Anthropic key shared by every Claude-backed assist in the app: the
 * opt-in "Ask AI" bank-reconciliation suggester, and AI scheduling's reply
 * classifier (see docs/ai-scheduling-spec.md). One key, one toggle per
 * feature — a key with only reconciliation approved doesn't have to also
 * turn on AI scheduling, and vice versa.
 */
class AiAssistIntegration extends IntegrationSettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $title = 'AI assist';

    protected static function settingsMap(): array
    {
        return [
            'llm_matching_enabled' => ['type' => 'bool', 'default' => false],
            'anthropic_api_key' => ['sensitive' => true],
            'ai_scheduling_enabled' => ['type' => 'bool', 'default' => false],
            'ai_scheduling_default_mode' => ['default' => 'simulate'],
        ];
    }

    protected function sections(): array
    {
        return [
            Section::make('Anthropic API key')
                ->description('Shared by every AI assist feature below. From console.anthropic.com. Stored encrypted at rest.')
                ->schema([
                    TextInput::make('anthropic_api_key')
                        ->label('Anthropic API key')
                        ->password()
                        ->revealable()
                        ->placeholder('sk-ant-…')
                        ->helperText('Uses the low-cost Haiku model everywhere it\'s called.'),
                ]),
            Section::make('AI reconciliation assist (optional)')
                ->description('Adds an "Ask AI" button to the Bank Transactions ledger for the leftover transactions the deterministic matcher couldn\'t resolve. It suggests a classification you then confirm — it never auto-confirms, and the rule-based matcher stays the source of truth. Off by default. ⚠️ Each "Ask AI" click makes one Anthropic API call (a small per-call cost); the rest of reconciliation is free.')
                ->schema([
                    Toggle::make('llm_matching_enabled')
                        ->label('Enable "Ask AI" on the ledger')
                        ->helperText('When on (and a key is set), the ledger shows an Ask AI button on unmatched rows.'),
                ]),
            Section::make('AI scheduling (optional)')
                ->description('Global switch for "Schedule w/ AI" — texting technicians one at a time to negotiate a job time over SMS. Off by default; a technician is also never contacted unless their own "AI scheduling texts" toggle is on. See docs/ai-scheduling-spec.md.')
                ->schema([
                    Toggle::make('ai_scheduling_enabled')
                        ->label('Enable "Schedule w/ AI"')
                        ->helperText('When on (and a key is set), the "Schedule w/ AI" action appears on unscheduled jobs.'),
                    Select::make('ai_scheduling_default_mode')
                        ->label('Default mode for new sessions')
                        ->options([
                            'simulate' => 'Simulate — sends nothing, for testing the negotiation logic',
                            'approve' => 'Approve each — every outbound text waits for a dispatcher click',
                            'live' => 'Live — fully autonomous',
                        ])
                        ->default('simulate')
                        ->helperText('The dispatcher can still override the mode per session when they launch it.'),
                ]),
        ];
    }
}
