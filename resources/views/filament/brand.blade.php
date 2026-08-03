{{--
    Glass Ops brand lockup: a windshield mark with a glare streak + wordmark.
    Rendered by Filament's logo component inside a fixed-height .fi-logo div
    (sidebar header, topbar on mobile, and the login page). Colors for the
    wordmark are context-driven in glass-ops.css (.go-brand-word).
--}}
<span class="go-brand">
    <svg viewBox="0 0 34 34" fill="none" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Glass Ops">
        <defs>
            <linearGradient id="go-glass" x1="6" y1="6" x2="28" y2="28" gradientUnits="userSpaceOnUse">
                <stop offset="0" stop-color="#7dd6ea" />
                <stop offset="0.55" stop-color="#159cc4" />
                <stop offset="1" stop-color="#0d5e82" />
            </linearGradient>
        </defs>
        {{-- Windshield: wider at the base, rounded corners. --}}
        <path
            d="M8.6 7.5 Q9.2 5.5 11.3 5.5 L22.7 5.5 Q24.8 5.5 25.4 7.5 L28.9 22.6 Q29.5 25.5 26.5 25.5 L7.5 25.5 Q4.5 25.5 5.1 22.6 Z"
            fill="url(#go-glass)"
        />
        {{-- Glare streaks. --}}
        <path d="M12.2 8.2 L15.8 8.2 L11.6 22.8 L8.4 22.8 Z" fill="#ffffff" opacity="0.34" />
        <path d="M18.0 8.2 L19.7 8.2 L15.9 22.8 L14.4 22.8 Z" fill="#ffffff" opacity="0.22" />
        {{-- Wiper hint at the base. --}}
        <path d="M6.4 29.2 L20.4 29.2" stroke="#7dd6ea" stroke-width="1.6" stroke-linecap="round" opacity="0.55" />
        <circle cx="23.4" cy="29.2" r="1.1" fill="#7dd6ea" opacity="0.55" />
    </svg>
    <span class="go-brand-word">
        Glass Ops
        <small>Field Service Edition</small>
    </span>
</span>
