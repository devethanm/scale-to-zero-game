<?php

use App\Models\Attendee;
use App\Models\Round;
use App\Services\LaravelCloud;
use App\Services\WakeTracker;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Guess the Scale to Zero')] class extends Component
{
    public string $playerName = '';

    public string $playerEmail = '';

    #[Locked]
    public ?int $attendeeId = null;

    public ?int $guessMs = null;

    public bool $roundActive = false;

    public ?int $roundToken = null;

    public ?string $activeEnvId = null;

    /** @var array{player_name: string, target_name: string, guess_ms: int, actual_ms: int, cold_ms: int|null, latency_ms: int|null, delta_ms: int, verdict: string}|null */
    public ?array $lastResult = null;

    public ?string $notice = null;

    /** @return array<string, array<int, string>> */
    protected function registrationRules(): array
    {
        return [
            'playerName' => ['required', 'string', 'max:50'],
            'playerEmail' => ['required', 'string', 'email:rfc', 'max:254'],
        ];
    }

    /** @return array<string, array<int, string>> */
    protected function guessRules(): array
    {
        return [
            'guessMs' => ['required', 'integer', 'min:1', 'max:120000'],
        ];
    }

    /**
     * Get every configured target merged with its Cloud status and cooldown state.
     *
     * The Cloud API does not reliably report scale-to-zero sleep, so playability
     * is driven by the wake cooldown that the game tracks itself. The API status
     * is only used to block targets that are deploying or stopped.
     */
    #[Computed]
    public function targets(): Collection
    {
        $statuses = app(LaravelCloud::class)->statuses();
        $tracker = app(WakeTracker::class);

        return collect(config('game.targets'))->map(function (array $target) use ($statuses, $tracker): array {
            $status = $statuses[$target['environment_id']] ?? LaravelCloud::STATUS_UNKNOWN;
            $cooldown = $tracker->secondsUntilReady($target['environment_id']);

            return [
                ...$target,
                'status' => $status,
                'cooldown' => $cooldown,
                'playable' => $cooldown === 0 && ! in_array($status, ['deploying', 'stopped']),
            ];
        });
    }

    #[Computed]
    public function readyCount(): int
    {
        return $this->targets->where('playable', true)->count();
    }

    /**
     * Get the seconds until the next cooling target becomes playable, if any.
     */
    #[Computed]
    public function nextReadySeconds(): ?int
    {
        return $this->targets
            ->reject(fn (array $target): bool => in_array($target['status'], ['deploying', 'stopped']))
            ->min('cooldown');
    }

    #[Computed]
    public function leaderboard(): Collection
    {
        return Round::closest()->limit(10)->get();
    }

    public function register(): void
    {
        $this->playerName = Str::squish($this->playerName);
        $this->playerEmail = Str::lower(trim($this->playerEmail));

        $validated = $this->validate($this->registrationRules());

        $attendee = Attendee::query()->updateOrCreate(
            ['email' => $validated['playerEmail']],
            ['name' => $validated['playerName']],
        );

        $this->playerName = $attendee->name;
        $this->playerEmail = $attendee->email;
        $this->attendeeId = $attendee->id;
        $this->resetErrorBag();
    }

    public function startRound(): void
    {
        if ($this->roundActive) {
            return;
        }

        if ($this->attendeeId === null) {
            $this->addError('registration', 'Enter your name and email before making a guess.');

            return;
        }

        $attendee = Attendee::query()->find($this->attendeeId);

        if ($attendee === null) {
            $this->reset(['attendeeId', 'playerName', 'playerEmail']);
            $this->addError('registration', 'Please enter your details again.');

            return;
        }

        $this->validate($this->guessRules());
        $this->playerName = $attendee->name;

        $target = $this->targets->firstWhere('playable', true);

        if ($target === null) {
            $seconds = $this->nextReadySeconds;

            $this->addError('round', $seconds !== null && $seconds > 0
                ? "Every app is still recovering — the next one is ready in about {$seconds} seconds."
                : 'No apps are available right now — try again in a moment.');

            return;
        }

        app(WakeTracker::class)->markWoken($target['environment_id']);

        $this->resetErrorBag('round');
        $this->lastResult = null;
        $this->notice = null;
        $this->activeEnvId = $target['environment_id'];
        $this->roundActive = true;
        $this->roundToken = random_int(100000, 99999999);

        $this->dispatch(
            'round-started',
            token: $this->roundToken,
            url: $target['url'],
            timeoutMs: config('game.round_timeout_ms'),
        );
    }

    /**
     * Record a finished round.
     *
     * The browser reports the cold first-response time and a warm baseline
     * measured straight afterwards. Subtracting the baseline strips out the
     * network round-trip, Cloud edge hop, and the app's steady-state request
     * cost, leaving the wake-from-sleep time itself — the number players guess.
     */
    public function recordResult(int $token, int $coldMs, ?int $latencyMs = null): void
    {
        if (! $this->roundActive || $token !== $this->roundToken) {
            return;
        }

        $target = $this->findTarget($this->activeEnvId);

        $baselineMs = $latencyMs !== null ? min(max($latencyMs, 0), $coldMs) : null;
        $wakeMs = $baselineMs !== null ? $coldMs - $baselineMs : $coldMs;

        $round = Round::create([
            'attendee_id' => $this->attendeeId,
            'player_name' => $this->playerName,
            'target_name' => $target['name'],
            'target_url' => $target['url'],
            'guess_ms' => $this->guessMs,
            'actual_ms' => $wakeMs,
            'cold_ms' => $coldMs,
            'latency_ms' => $baselineMs,
            'delta_ms' => abs($this->guessMs - $wakeMs),
        ]);

        $this->lastResult = [
            ...$round->only(['player_name', 'target_name', 'guess_ms', 'actual_ms', 'cold_ms', 'latency_ms', 'delta_ms']),
            'verdict' => $round->verdict,
        ];
        $this->finishRound();

        $this->dispatch('round-complete');
    }

    /**
     * Clear the result and form so the next person starts from a blank slate.
     */
    public function newGuess(): void
    {
        $this->reset(['lastResult', 'playerName', 'playerEmail', 'attendeeId', 'guessMs', 'notice']);
        $this->resetErrorBag();
    }

    public function voidRound(int $token): void
    {
        if (! $this->roundActive || $token !== $this->roundToken) {
            return;
        }

        $this->notice = 'Round voided — the app never finished loading.';
        $this->finishRound();
    }

    protected function finishRound(): void
    {
        $this->roundActive = false;
        $this->roundToken = null;
        $this->activeEnvId = null;
        $this->guessMs = null;
    }

    /**
     * Find the configured target for the given environment ID.
     *
     * @return array{name: string, url: string, application_id: string, environment_id: string}|null
     */
    protected function findTarget(?string $environmentId): ?array
    {
        return collect(config('game.targets'))->firstWhere('environment_id', $environmentId);
    }
};
?>

<div class="relative isolate flex h-dvh flex-col overflow-hidden bg-white">
    <header class="relative z-30 flex shrink-0 justify-center border-b border-line bg-white/85 px-4 py-3 backdrop-blur-md sm:py-4 xl:py-5">
        <x-brand-lockup class="text-xl sm:text-2xl xl:text-4xl" />
    </header>

    <div class="relative min-h-0 flex-1">
        {{-- Dot grid that fades toward the edges, with a soft Cloud-blue glow from below --}}
        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(circle,var(--color-dot)_1px,transparent_1.2px)] [background-size:18px_18px] [mask-image:radial-gradient(ellipse_70%_75%_at_50%_45%,#000_30%,transparent_100%)]"></div>
        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(40vw_22vw_at_50%_100%,rgb(0_87_255/0.14),transparent_70%)]"></div>

        {{-- The waking app fills the whole backdrop, so phones see it behind the card --}}
        <div wire:ignore wire:key="wake-stage" class="absolute inset-0 z-0">
            <div class="pointer-events-none absolute inset-x-0 bottom-4 flex justify-center lg:bottom-6">
                <p class="rounded-full border border-line bg-white px-4 py-2 text-sm font-medium text-slate-400 shadow-xs md:text-base">😴 I'm sleeping…</p>
            </div>
            <iframe
                id="wake-frame"
                title="Scale to zero preview"
                class="peer absolute inset-0 size-full bg-white opacity-0 transition-opacity duration-300"
            ></iframe>
            {{-- Soft scrim so the woken page reads as a backdrop, not the focus --}}
            <div class="pointer-events-none absolute inset-0 bg-white/40 transition-opacity duration-300 peer-[.opacity-0]:opacity-0"></div>
        </div>

        {{-- Leaderboard | game | QR code. The side columns are equal width so the game sits dead centre; phones only get the game --}}
        <div class="absolute inset-0 z-20 overflow-y-auto">
            {{-- Sizes scale with both viewport width and height so the whole layout fits any screen without scrolling.
                 The bottom padding keeps the "I'm sleeping" pill clear of the card. --}}
            <div class="grid min-h-full content-center items-start gap-x-[clamp(1rem,2.2vw,3rem)] gap-y-[clamp(0.75rem,2.5vh,1.75rem)] px-4 pt-[clamp(1rem,3vh,2.5rem)] pb-[clamp(4.5rem,9vh,6rem)] sm:px-6 lg:grid-cols-[minmax(15rem,1fr)_minmax(0,clamp(28rem,40vw,52rem))_minmax(15rem,1fr)] lg:px-[clamp(1.5rem,2.5vw,3.5rem)]">
                <aside class="hidden w-full max-w-[28rem] flex-col justify-self-end lg:col-start-1 lg:row-start-2 rounded-2xl border border-line bg-white/90 shadow-glow backdrop-blur-md lg:flex">
                    <h2 class="border-b border-line px-6 py-4 font-mono text-xs font-medium tracking-[0.08em] text-slate-400 uppercase xl:px-7 xl:py-5 xl:text-sm">Leaderboard</h2>

                    @if ($this->leaderboard->isEmpty())
                        <p class="px-6 py-8 text-center text-sm text-slate-400 xl:text-base">No rounds yet — be the first!</p>
                    @else
                        <ol role="list" class="divide-y divide-line">
                            @foreach ($this->leaderboard as $round)
                                <li wire:key="leader-{{ $round->id }}" @class([
                                    'flex items-center gap-3 px-6 py-3 text-sm xl:gap-4 xl:px-7 xl:py-3.5 xl:text-lg',
                                    'bg-cloud/5' => $loop->first,
                                ])>
                                    <span class="w-6 font-mono text-xs text-slate-400 tabular-nums xl:w-8 xl:text-sm">{{ $loop->iteration }}</span>
                                    <span class="flex-1 truncate font-medium tracking-tight text-slate-800">{{ $round->player_name }}</span>
                                    <span class="font-mono text-xs text-cloud tabular-nums xl:text-base">±{{ number_format($round->delta_ms) }} ms</span>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </aside>

                {{-- On large screens the stopwatch gets its own row, so the card and both side panels line up at the top --}}
                <main class="mx-auto flex w-full max-w-md flex-col items-center gap-[clamp(0.75rem,2.5vh,1.75rem)] sm:max-w-lg lg:contents">
                    <div wire:ignore wire:key="wake-stopwatch-wrap" class="lg:col-start-2 lg:row-start-1 lg:justify-self-center">
                        <div id="wake-stopwatch" class="rounded-full border border-line bg-white px-[1.4em] py-[0.3em] font-mono text-[clamp(2rem,min(4.5vw,7vh),4.5rem)] leading-tight font-medium tracking-tight tabular-nums shadow-glow transition-colors duration-300">0 ms</div>
                    </div>

                    {{-- Backdrop blur is too heavy for phone GPUs while typing, so it's desktop-only --}}
                    <div class="@container flex w-full flex-col gap-[clamp(1rem,2.5vh,1.75rem)] rounded-2xl lg:col-start-2 lg:row-start-2 border border-line bg-white p-[clamp(1.25rem,min(2.5vw,4vh),2.75rem)] shadow-glow lg:bg-white/95 lg:backdrop-blur-sm">
                        <div class="flex flex-col items-start gap-[clamp(0.5rem,1.5vh,1rem)]">
                            <p wire:poll.5s class="inline-flex items-center gap-2 rounded-full border border-line bg-white py-1 pr-3.5 pl-3 text-xs text-slate-500 shadow-xs md:text-sm">
                                @if ($this->readyCount > 0)
                                    <span class="relative flex size-2">
                                        <span class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                                        <span class="relative inline-flex size-2 rounded-full bg-emerald-500"></span>
                                    </span>
                                    An app is scaled to zero and ready to wake
                                @elseif ($this->nextReadySeconds !== null && $this->nextReadySeconds > 0)
                                    <span class="inline-flex size-2 rounded-full bg-amber-400"></span>
                                    Every app is recovering — next one ready in ~{{ $this->nextReadySeconds }}s
                                @else
                                    <span class="inline-flex size-2 rounded-full bg-slate-300"></span>
                                    Waiting for an app to become available…
                                @endif
                            </p>

                            <h1 class="text-[clamp(1.875rem,min(3.6vw,6vh),4rem)] leading-[1.05] font-medium tracking-[-0.045em] text-balance">Guess the <span class="text-cloud">scale to zero</span> 💤</h1>
                            @unless ($lastResult)
                                @if ($attendeeId === null)
                                    <p class="text-sm leading-relaxed text-pretty text-slate-500 md:text-base xl:text-lg">Enter your details to play, then guess how many milliseconds a Laravel Cloud app takes to wake. Closest guess wins.</p>
                                @else
                                    <p class="text-sm leading-relaxed text-pretty text-slate-500 md:text-base xl:text-lg">A Laravel Cloud app has scaled to zero. Make your guess — we subtract network and steady-state latency so it's purely the cold start.</p>
                                @endif
                            @endunless
                        </div>

                        @if ($notice)
                            <div wire:key="round-notice" class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-700 ring-1 ring-amber-600/20 motion-safe:animate-rise-in md:text-base">{{ $notice }}</div>
                        @endif

                        @if ($lastResult)
                            <div wire:key="round-result" class="flex flex-col gap-4 motion-safe:animate-rise-in">
                                <div class="flex flex-col gap-2 rounded-xl border border-cloud/20 bg-cloud/5 px-4 py-3.5 md:px-5 md:py-4">
                                    <p class="font-mono text-xs font-medium tracking-[0.08em] text-cloud uppercase md:text-sm">{{ $lastResult['verdict'] }}</p>
                                    <p class="font-medium tracking-tight text-balance md:text-lg xl:text-2xl">Laravel Cloud woke from sleep in {{ number_format($lastResult['actual_ms']) }} ms</p>
                                    <p class="text-sm text-slate-500 md:text-base">{{ $lastResult['player_name'] }} guessed {{ number_format($lastResult['guess_ms']) }} ms — off by {{ number_format($lastResult['delta_ms']) }} ms.</p>
                                    @if (($lastResult['cold_ms'] ?? null) !== null && ($lastResult['latency_ms'] ?? null) !== null)
                                        <p class="text-sm text-slate-400">Full cold response was {{ number_format($lastResult['cold_ms']) }} ms — we stripped ~{{ number_format($lastResult['latency_ms']) }} ms of round-trip and steady-state server time so this is just the wake.</p>
                                    @endif
                                </div>

                                {{-- Phones are one guess per player, so only the shared booth screens offer a reset --}}
                                <button
                                    type="button"
                                    wire:click="newGuess"
                                    x-on:click="window.cancelGuessReset?.(); window.resetWakeStage?.()"
                                    class="h-11 rounded-lg bg-cloud px-4 text-sm font-medium text-white transition-[background-color,transform] duration-150 max-lg:hidden hover:bg-cloud/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cloud active:scale-[0.98] md:h-12 md:text-base xl:h-14 xl:text-lg"
                                >
                                    New guess
                                </button>
                                <p class="text-center text-xs text-slate-400 max-lg:hidden md:text-sm">Resetting for the next player in 10 seconds…</p>
                                <p class="text-center text-xs text-slate-400 md:text-sm lg:hidden">How'd you do? Find your name on the leaderboard.</p>
                            </div>
                        @elseif ($attendeeId === null)
                            <form wire:key="register-step" wire:submit="register" class="grid gap-4 @sm:grid-cols-2">
                                <div class="flex flex-col gap-1.5">
                                    <label for="player-name" class="text-sm font-medium text-slate-700 xl:text-base">Your name</label>
                                    <input
                                        id="player-name"
                                        name="player_name"
                                        type="text"
                                        autocomplete="name"
                                        autocapitalize="words"
                                        enterkeyhint="next"
                                        wire:model="playerName"
                                        class="h-11 w-full rounded-lg border border-line bg-white px-3 text-sm transition-shadow hover:border-slate-300 focus:border-cloud focus:ring-[3px] focus:ring-cloud/15 focus:outline-hidden max-sm:text-base md:h-12 md:text-base xl:h-14 xl:px-4 xl:text-lg"
                                    />
                                    @error('playerName')
                                        <p class="text-sm text-red-600 motion-safe:animate-rise-in">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div class="flex flex-col gap-1.5">
                                    <label for="player-email" class="text-sm font-medium text-slate-700 xl:text-base">Your email</label>
                                    <input
                                        id="player-email"
                                        name="player_email"
                                        type="email"
                                        inputmode="email"
                                        autocomplete="email"
                                        autocapitalize="none"
                                        autocorrect="off"
                                        spellcheck="false"
                                        enterkeyhint="go"
                                        wire:model="playerEmail"
                                        class="h-11 w-full rounded-lg border border-line bg-white px-3 text-sm transition-shadow hover:border-slate-300 focus:border-cloud focus:ring-[3px] focus:ring-cloud/15 focus:outline-hidden max-sm:text-base md:h-12 md:text-base xl:h-14 xl:px-4 xl:text-lg"
                                    />
                                    @error('playerEmail')
                                        <p class="text-sm text-red-600 motion-safe:animate-rise-in">{{ $message }}</p>
                                    @enderror
                                </div>

                                <button
                                    type="submit"
                                    wire:loading.attr="disabled"
                                    wire:target="register"
                                    class="inline-flex h-11 items-center justify-center gap-2 rounded-lg bg-cloud px-4 text-sm font-medium text-white transition-[background-color,transform,opacity] duration-150 hover:bg-cloud/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cloud active:scale-[0.98] disabled:cursor-wait disabled:opacity-60 md:h-12 md:text-base @sm:col-span-2 xl:h-14 xl:text-lg"
                                >
                                    <span wire:loading.remove wire:target="register">Continue to your guess</span>
                                    <span wire:loading.inline-flex wire:target="register" class="items-center gap-2">
                                        <svg class="size-4 animate-spin md:size-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                        </svg>
                                        Saving…
                                    </span>
                                </button>

                                <p class="text-xs leading-relaxed text-pretty text-slate-400 @sm:col-span-2 xl:text-sm">By continuing, you agree that the event organizers may use these details to follow up with you after the event.</p>

                                @error('registration')
                                    <p class="text-sm text-red-600 motion-safe:animate-rise-in @sm:col-span-2">{{ $message }}</p>
                                @enderror
                            </form>
                        @else
                            <form wire:key="guess-step" wire:submit="startRound" class="grid gap-4 motion-safe:animate-rise-in">
                                <div class="flex items-center justify-between gap-4 rounded-xl border border-cloud/20 bg-cloud/5 px-4 py-3">
                                    <div class="min-w-0">
                                        <p class="font-mono text-xs font-medium tracking-[0.08em] text-cloud uppercase">You're in</p>
                                        <p class="truncate text-sm font-medium text-slate-700 md:text-base">{{ $playerName }}</p>
                                    </div>
                                    <span class="grid size-7 shrink-0 place-items-center rounded-full bg-cloud text-sm text-white motion-safe:animate-pop" aria-hidden="true">✓</span>
                                </div>

                                <div class="flex flex-col gap-1.5">
                                    <label for="guess-ms" class="text-sm font-medium text-slate-700 xl:text-base">Your guess (ms)</label>
                                    <input
                                        id="guess-ms"
                                        name="guess_ms"
                                        type="number"
                                        inputmode="numeric"
                                        enterkeyhint="go"
                                        min="1"
                                        max="120000"
                                        wire:model="guessMs"
                                        class="h-11 w-full rounded-lg border border-line bg-white px-3 font-mono text-sm tabular-nums transition-shadow hover:border-slate-300 focus:border-cloud focus:ring-[3px] focus:ring-cloud/15 focus:outline-hidden max-sm:text-base md:h-12 md:text-base xl:h-14 xl:px-4 xl:text-lg"
                                    />
                                    @error('guessMs')
                                        <p class="text-sm text-red-600 motion-safe:animate-rise-in">{{ $message }}</p>
                                    @enderror
                                </div>

                                <button
                                    type="submit"
                                    @disabled($roundActive || $this->readyCount === 0)
                                    class="inline-flex h-11 items-center justify-center gap-2 rounded-lg bg-cloud px-4 text-sm font-medium text-white transition-[background-color,transform,opacity] duration-150 hover:bg-cloud/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cloud active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50 md:h-12 md:text-base xl:h-14 xl:text-lg"
                                >
                                    @if ($roundActive)
                                        {{-- The round stays active until the wake probe reports back, so the
                                             spinner keeps going from button click until the stopwatch moves. --}}
                                        <svg class="size-4 animate-spin md:size-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                        </svg>
                                        Waking…
                                    @else
                                        <span wire:loading.remove wire:target="startRound">Wake an app</span>
                                        <span wire:loading.inline-flex wire:target="startRound" class="items-center gap-2">
                                            <svg class="size-4 animate-spin md:size-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                            </svg>
                                            Waking…
                                        </span>
                                    @endif
                                </button>

                                @error('round')
                                    <p class="text-sm text-red-600 motion-safe:animate-rise-in">{{ $message }}</p>
                                @enderror
                            </form>
                        @endif
                    </div>
                </main>

                <aside class="hidden w-full max-w-[28rem] flex-col items-center lg:col-start-3 lg:row-start-2 gap-[clamp(1rem,2.5vh,1.5rem)] justify-self-start rounded-2xl border border-line bg-white/90 p-[clamp(1.25rem,2vw,2rem)] text-center shadow-glow backdrop-blur-md lg:flex">
                    <p class="inline-flex items-center gap-2 self-start font-mono text-xs font-medium tracking-[0.08em] whitespace-nowrap text-cloud uppercase xl:text-sm">
                        <span class="size-2 rounded-full bg-emerald-500"></span>
                        Play on your phone
                    </p>
                    <img
                        src="https://api.qrserver.com/v1/create-qr-code/?size=480x480&margin=0&data={{ urlencode(url('/')) }}"
                        alt="QR code linking to the game"
                        class="aspect-square w-full max-w-[min(22rem,34vh)] rounded-xl border border-line bg-white p-3"
                    />
                    <div class="flex w-full min-w-0 flex-col gap-1.5">
                        <p class="text-2xl font-medium tracking-[-0.03em] xl:text-3xl">Scan to play</p>
                        <p class="text-sm text-pretty text-slate-500 xl:text-base">Make your guess on your own phone.</p>
                        <code class="mt-1 truncate font-mono text-xs text-slate-400 xl:text-sm">{{ request()->getHost() }}</code>
                    </div>
                </aside>
            </div>
        </div>
    </div>
</div>

@assets
<style>
    /* Keep the blank iframe out of the compositor entirely — a full-screen
       opacity-0 layer still costs paint time on phone GPUs while typing. */
    #wake-frame.opacity-0 {
        visibility: hidden;
    }

    /* While an app wakes, the stopwatch pulses so the player knows it's working. */
    #wake-stopwatch.is-waking {
        animation: stopwatch-waking 1.4s ease-out infinite;
    }

    /* When the wake time lands, the stopwatch turns blue and pops. */
    #wake-stopwatch.is-landed {
        color: var(--color-cloud);
        border-color: rgb(0 87 255 / 0.35);
    }

    @media (prefers-reduced-motion: no-preference) {
        #wake-stopwatch.is-landed {
            animation: var(--animate-pop);
        }
    }

    @keyframes stopwatch-waking {
        0% { box-shadow: 0 0 0 0 rgb(0 87 255 / 0.4); }
        70% { box-shadow: 0 0 0 1.25rem rgb(0 87 255 / 0); }
        100% { box-shadow: 0 0 0 0 rgb(0 87 255 / 0); }
    }
</style>
@endassets

<script>
    const frame = document.getElementById('wake-frame');
    const stopwatch = document.getElementById('wake-stopwatch');

    let raf = null;
    let abortActiveRound = null;
    let resetTimer = null;
    let tweenRaf = null;

    // Stop any in-flight stopwatch count-down animation.
    const cancelTween = () => {
        if (tweenRaf) {
            cancelAnimationFrame(tweenRaf);
            tweenRaf = null;
        }
    };

    // Cancel a pending auto-reset so it can't wipe the next player's input.
    window.cancelGuessReset = () => {
        clearTimeout(resetTimer);
        resetTimer = null;
    };

    // Blank the stopwatch and stage so the next player starts clean.
    window.resetWakeStage = () => {
        cancelTween();
        stopwatch.classList.remove('is-waking', 'is-landed');
        stopwatch.textContent = '0 ms';
        frame.classList.add('opacity-0');
        frame.src = 'about:blank';
    };

    // After a result is shown, reset to a blank form for the next player —
    // but only on shared booth screens. Phones keep the result up, since each
    // player gets one guess and compares with their group.
    $wire.on('round-complete', () => {
        window.cancelGuessReset();

        if (! window.matchMedia('(min-width: 64rem)').matches) {
            return;
        }

        resetTimer = setTimeout(() => {
            resetTimer = null;
            window.resetWakeStage();
            $wire.newGuess();
        }, 10000);
    });

    $wire.on('round-started', ({ token, url, timeoutMs }) => {
        window.cancelGuessReset();
        cancelTween();

        if (abortActiveRound) {
            abortActiveRound();
        }

        // Blank the stage immediately so the previous round's page disappears.
        // The frame is revealed again once the page finishes rendering.
        frame.classList.add('opacity-0');

        const cacheBust = url.includes('?') ? '&' : '?';
        const previewUrl = url + cacheBust + 'cs=' + token + '-preview';
        const wakeProbeUrl = new URL('/wake', url);

        wakeProbeUrl.search = 'cs=' + token + '-probe';

        let start = null;
        let stopped = false;
        let previewRequested = false;

        const render = (ms) => {
            stopwatch.textContent = `${Math.round(ms).toLocaleString()} ms`;
        };

        // Resource Timing gives us the browser's actual request/first-response
        // boundary when the target exposes Timing-Allow-Origin. Fall back to
        // the fetch promise timing for older deployed targets.
        const responseHeaderMs = (resourceUrl, fallbackStart) => {
            const entries = performance.getEntriesByName(resourceUrl);
            const entry = entries[entries.length - 1];

            if (entry?.responseStart > 0) {
                const requestStart = entry.requestStart > 0 ? entry.requestStart : entry.startTime;

                return entry.responseStart - requestStart;
            }

            return performance.now() - fallbackStart;
        };

        const loadPreview = () => {
            if (previewRequested) {
                return;
            }

            previewRequested = true;
            frame.src = previewUrl;
        };

        // Stop the clock when the wake probe receives response headers. The
        // iframe is only loaded after this point so it cannot be the request
        // that wakes the app.
        const stopClock = (ms) => {
            if (stopped) return null;
            stopped = true;
            clearTimeout(timeout);
            window.removeEventListener('message', onMessage);
            return Math.round(ms);
        };

        // The app is awake once it has responded, so fire a few warm probes in
        // parallel and report the fastest as the round-trip share of the wake.
        // Running them concurrently keeps the stopwatch from sitting frozen.
        const measureLatency = async () => {
            const probe = async (i) => {
                const pingUrl = new URL('/wake', url);
                pingUrl.search = 'ping=' + token + '-' + i;
                const t0 = performance.now();

                try {
                    await fetch(pingUrl, { method: 'HEAD', mode: 'no-cors', cache: 'no-store' });
                } catch (error) {
                    return null;
                }

                return responseHeaderMs(pingUrl.href, t0);
            };

            const results = (await Promise.all([0, 1, 2].map(probe))).filter((ms) => ms !== null);

            return results.length > 0 ? Math.round(Math.min(...results)) : null;
        };

        // Count the stopwatch up from zero and land it on the final wake time.
        // The number only ever climbs — it never overshoots and corrects back.
        const animateCountUp = (target) => {
            cancelTween();

            const duration = Math.min(1100, Math.max(450, target));
            const startedAt = performance.now();

            const step = () => {
                const t = Math.min(1, (performance.now() - startedAt) / duration);
                const eased = 1 - Math.pow(1 - t, 3);
                render(target * eased);

                if (t < 1) {
                    tweenRaf = requestAnimationFrame(step);
                } else {
                    tweenRaf = null;
                    render(target);
                    stopwatch.classList.add('is-landed');
                    navigator.vibrate?.(40);
                }
            };

            tweenRaf = requestAnimationFrame(step);
        };

        const record = async (ms) => {
            const coldMs = stopClock(ms);

            if (coldMs === null) {
                return;
            }

            loadPreview();

            // The cold response includes the network round-trip and the app's
            // steady-state request cost. Measuring a warm baseline straight
            // after lets the server subtract it, leaving just the wake time.
            const latency = await measureLatency();
            const wakeMs = latency !== null ? Math.max(0, coldMs - Math.min(latency, coldMs)) : coldMs;

            stopwatch.classList.remove('is-waking');
            animateCountUp(wakeMs);

            $wire.recordResult(token, coldMs, latency);
        };

        const teardown = () => {
            stopped = true;
            stopwatch.classList.remove('is-waking');
            cancelAnimationFrame(raf);
            cancelTween();
            clearTimeout(timeout);
            frame.removeEventListener('load', onLoad);
            window.removeEventListener('message', onMessage);
            abortActiveRound = null;
        };

        // The iframe load only reveals the rendered page — and acts as the
        // fallback clock stop if the probe request failed.
        const onLoad = () => {
            frame.removeEventListener('load', onLoad);
            frame.classList.remove('opacity-0');

            if (! stopped && start !== null) {
                record(performance.now() - start);
            }
        };

        // Optional earlier stop: the app posts first-paint to its parent.
        const onMessage = (event) => {
            if (event.data?.type === 'first-paint') {
                record(performance.now() - start);
            }
        };

        const timeout = setTimeout(() => {
            if (stopped) return;
            teardown();
            frame.src = 'about:blank';
            $wire.voidRound(token);
        }, timeoutMs);

        abortActiveRound = teardown;

        frame.addEventListener('load', onLoad);
        window.addEventListener('message', onMessage);

        render(0);
        stopwatch.classList.remove('is-landed');
        stopwatch.classList.add('is-waking');
        start = performance.now();

        // Primary clock stop: this HEAD probe is the first request to the
        // sleeping app and resolves when response headers arrive.
        fetch(wakeProbeUrl.href, { method: 'HEAD', mode: 'no-cors', cache: 'no-store' })
            .then(() => record(responseHeaderMs(wakeProbeUrl.href, start)))
            .catch(() => {
                if (! stopped) {
                    loadPreview();
                }
            });
    });
</script>
