{{-- "Ruby × Laravel Cloud", sized by the font size passed in the class attribute --}}
<div role="img" aria-label="Ruby × Laravel Cloud" {{ $attributes->class('flex items-center gap-[0.45em] leading-none') }}>
    <span class="inline-flex items-center gap-[0.3em] font-medium tracking-[-0.04em] text-ruby" aria-hidden="true">
        <svg class="h-[0.92em] w-[1.05em] shrink-0" viewBox="0 0 64 56">
            <polygon points="14,2 50,2 62,18 32,54 2,18" fill="currentColor" />
            <polygon points="14,2 22,18 2,18" fill="#fff" opacity="0.35" />
            <polygon points="22,18 42,18 32,54" fill="#fff" opacity="0.2" />
            <polygon points="50,2 62,18 42,18" fill="#000" opacity="0.12" />
        </svg>
        Ruby
    </span>
    <span class="text-[0.7em] text-slate-400" aria-hidden="true">×</span>
    <img src="{{ asset('images/laravel-cloud.svg') }}" alt="" class="h-[0.78em] w-auto" />
</div>
