<?php

use App\Models\Round;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Leaderboard — Guess the Scale to Zero')] class extends Component
{
    /**
     * Seconds a guess stays in the recent feed before it fades away entirely.
     */
    public const int RECENT_WINDOW_SECONDS = 90;

    #[Computed]
    public function leaderboard(): Collection
    {
        return Round::closest()->limit(10)->get();
    }

    #[Computed]
    public function recentGuesses(): Collection
    {
        return Round::query()
            ->where('created_at', '>=', now()->subSeconds(self::RECENT_WINDOW_SECONDS))
            ->latest()
            ->limit(8)
            ->get();
    }
};
?>

<div wire:poll.3s class="relative isolate h-dvh overflow-hidden">
    {{-- Full-bleed dot-grid backdrop --}}
    <div class="absolute inset-0 bg-[radial-gradient(var(--color-slate-200)_1px,transparent_1px)] [background-size:8px_8px]"></div>

    <div class="relative z-10 flex h-full gap-8 p-8 xl:gap-10 xl:p-12">
        {{-- Left: the big leaderboard --}}
        <main class="flex min-w-0 flex-[3] flex-col gap-8">
            <div class="flex items-end justify-between gap-8">
                <h1 class="text-4xl leading-[1.05] font-medium tracking-[-0.045em] xl:text-7xl">Guess the <span class="text-cloud">scale to zero</span> 💤</h1>
                <x-brand-lockup class="shrink-0 text-2xl xl:text-4xl" />
            </div>

            <div class="flex min-h-0 flex-1 flex-col rounded-3xl bg-white/85 p-8 shadow-2xl ring-1 ring-black/5 backdrop-blur-md xl:p-10">
                <h2 class="font-mono text-sm font-medium tracking-wide text-slate-400 uppercase xl:text-base">Leaderboard</h2>

                @if ($this->leaderboard->isEmpty())
                    <p class="mt-6 text-2xl text-slate-400 xl:text-3xl">No rounds yet — be the first!</p>
                @else
                    <ol role="list" class="mt-6 flex min-h-0 flex-1 flex-col gap-4 overflow-hidden xl:gap-5">
                        @foreach ($this->leaderboard as $round)
                            <li wire:key="board-leader-{{ $round->id }}" class="flex items-baseline gap-6 text-2xl xl:text-4xl">
                                <div class="w-10 shrink-0 font-mono text-xl text-slate-400 tabular-nums xl:w-14 xl:text-3xl">{{ $loop->iteration }}</div>
                                <div class="flex-1 truncate font-semibold text-slate-700">{{ $round->player_name }}</div>
                                <div class="shrink-0 font-mono text-cloud tabular-nums">±{{ number_format($round->delta_ms) }} ms</div>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </div>
        </main>

        {{-- Right: the recent-guess feed and the QR code to join --}}
        <aside class="flex w-96 shrink-0 flex-col gap-8 xl:w-[28rem]">
            <div class="flex min-h-0 flex-1 flex-col rounded-3xl bg-white/85 p-7 shadow-2xl ring-1 ring-black/5 backdrop-blur-md xl:p-8">
                <h2 class="font-mono text-sm font-medium tracking-wide text-slate-400 uppercase xl:text-base">Recent guesses</h2>

                @if ($this->recentGuesses->isEmpty())
                    <p class="mt-5 text-lg text-slate-400 xl:text-xl">Waiting for the next brave guess…</p>
                @else
                    <ul role="list" class="mt-5 flex min-h-0 flex-1 flex-col gap-4 overflow-hidden xl:gap-5">
                        @foreach ($this->recentGuesses as $round)
                            @php $ageSeconds = min((int) $round->created_at->diffInSeconds(), $this::RECENT_WINDOW_SECONDS); @endphp
                            <li
                                wire:key="recent-guess-{{ $round->id }}"
                                class="flex flex-col gap-1 rounded-xl bg-cloud/4 px-5 py-4 ring-1 ring-cloud/15 transition-opacity duration-1000 motion-safe:animate-[board-enter_0.5s_ease-out]"
                                style="opacity: {{ round(max(0.15, 1 - $ageSeconds / $this::RECENT_WINDOW_SECONDS), 2) }}"
                            >
                                <p class="text-sm font-medium text-cloud xl:text-base">{{ $round->verdict }}</p>
                                <p class="text-lg font-semibold text-slate-700 xl:text-xl">{{ $round->player_name }} <span class="font-normal text-slate-400">was off by</span> {{ number_format($round->delta_ms) }} ms</p>
                                <p class="text-sm text-slate-500 xl:text-base">Guessed {{ number_format($round->guess_ms) }} ms — the wake took {{ number_format($round->actual_ms) }} ms</p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="flex shrink-0 items-center gap-6 rounded-3xl bg-white/85 p-6 shadow-2xl ring-1 ring-black/5 backdrop-blur-md xl:p-7">
                <img
                    src="https://api.qrserver.com/v1/create-qr-code/?size=240x240&data={{ urlencode(url('/')) }}"
                    alt="QR code linking to the game"
                    class="size-28 shrink-0 rounded-xl xl:size-36"
                />
                <div class="flex flex-col gap-1">
                    <p class="text-xl font-semibold tracking-tight xl:text-2xl">Scan to play</p>
                    <p class="text-sm text-pretty text-slate-500 xl:text-base">Guess the wake time on your phone — closest guess wins.</p>
                </div>
            </div>

        </aside>
    </div>
</div>

@assets
<style>
    @keyframes board-enter {
        from {
            opacity: 0;
            transform: translateY(-0.5rem);
        }
    }
</style>
@endassets
