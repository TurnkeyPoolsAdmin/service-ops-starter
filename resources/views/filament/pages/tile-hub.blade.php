<x-filament-panels::page>
    <style>
        .tile-hub-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 0.75rem;
        }

        @media (max-width: 1024px) {
            .tile-hub-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }

        @media (max-width: 640px) {
            .tile-hub-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }

        .tile-hub-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.55rem;
            aspect-ratio: 1 / 1;
            padding: 0.85rem;
            text-align: center;
            border-radius: 0.95rem;
            background-color: #ffffff;
            background-image: radial-gradient(9rem 6rem at 100% 0%, rgba(63, 185, 216, 0.07), transparent 65%);
            border: 1px solid rgba(11, 42, 66, 0.09);
            box-shadow: 0 1px 2px rgba(8, 24, 36, 0.05), 0 10px 28px -18px rgba(8, 24, 36, 0.25);
            color: #0e2434;
            text-decoration: none;
            transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
        }

        .tile-hub-card:hover {
            transform: translateY(-3px);
            border-color: var(--primary-500, #159cc4);
            box-shadow: 0 12px 26px -8px rgba(21, 156, 196, 0.3);
        }

        .tile-hub-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 2.75rem;
            height: 2.75rem;
            border-radius: 9999px;
            background: linear-gradient(160deg, rgba(125, 214, 234, 0.22), rgba(21, 156, 196, 0.12));
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.5);
            color: var(--primary-600, #0d7fa6);
        }

        .tile-hub-icon svg { width: 1.4rem; height: 1.4rem; }

        .tile-hub-label {
            font-size: 0.8rem;
            font-weight: 600;
            line-height: 1.25;
        }

        .dark .tile-hub-card {
            background-color: #0f1c2a;
            background-image: radial-gradient(9rem 6rem at 100% 0%, rgba(63, 185, 216, 0.1), transparent 65%);
            border-color: rgba(125, 214, 234, 0.1);
            color: #ffffff;
        }

        .dark .tile-hub-card:hover { border-color: var(--primary-400, #3fb9d8); }

        .dark .tile-hub-icon {
            background: linear-gradient(160deg, rgba(125, 214, 234, 0.16), rgba(21, 156, 196, 0.1));
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.08);
            color: #7dd6ea;
        }
    </style>

    <div class="tile-hub-grid">
        @foreach ($this->getTiles() as $tile)
            <a href="{{ $tile['url'] }}" class="tile-hub-card">
                <span class="tile-hub-icon">
                    <x-filament::icon :icon="$tile['icon']" />
                </span>
                <span class="tile-hub-label">{{ $tile['label'] }}</span>
            </a>
        @endforeach
    </div>
</x-filament-panels::page>
