# Guess the Scale to Zero

A conference booth game for Laravel Cloud. Visitors enter their name and email, guess how many milliseconds a sleeping Laravel Cloud app takes to wake, and then the game wakes one and times it. The closest guess wins.

## Pages

| Path | Purpose |
| --- | --- |
| `/` | The game. Phones get one guess each. Booth screens reset for the next player 10 seconds after a result. |
| `/board` | The booth TV: the top 10 leaderboard and a feed of recent guesses. |
| `/organizers/attendees` | The attendee list and CSV export. The URL is signed; generate it with `php artisan attendees:url`. |

## How a round works

1. The game picks the first target app that has been idle for at least the wake cooldown (90 seconds by default, `GAME_WAKE_COOLDOWN`), and records the time it was woken.
2. The browser sends a `HEAD` request to the target's `/wake` route. This is the first request to the sleeping app, so its response time includes the wake.
3. The browser reads the time from sending the request to receiving the first response byte from the Resource Timing API. This leaves out DNS, TCP, and TLS setup. The result is the **cold** time.
4. The browser then sends three warm `HEAD` requests to `/wake` in parallel and keeps the fastest. This is the **baseline**: the network round trip plus the app's steady-state request time.
5. The server records the **wake time** as cold minus baseline. This is the number players guess against.
6. After the probe returns, the game loads the target's home page in the background as a preview. It is not timed.

If the target does not respond within 30 seconds, the round is voided.

The Laravel Cloud API does not reliably report when an environment has scaled to zero, so the game tracks the cooldown itself instead of asking the API. The API status check is off by default (`GAME_CLOUD_STATUS_ENABLED`).

## Targets

The targets are five Laravel Cloud apps in the **Laravel GTM** organization, region **ap-southeast-2 (Sydney)**. They are configured in [`config/game.php`](config/game.php).

| Name | Cloud application | URL |
| --- | --- | --- |
| Sydney 1 | `scale-to-zero-target-1` | https://scale-to-zero-target-1-production-lwhlwv.laravel-demo.cloud |
| Sydney 2 | `scale-to-zero-target-2` | https://scale-to-zero-target-2-production-v6dyta.laravel-demo.cloud |
| Sydney 3 | `scale-to-zero-target-3` | https://scale-to-zero-target-3-production-cv987p.laravel-demo.cloud |
| Sydney 4 | `scale-to-zero-target-4` | https://scale-to-zero-target-4-production-da8lup.laravel-demo.cloud |
| Sydney 5 | `scale-to-zero-target-5` | https://scale-to-zero-target-5-production-reuzoo.laravel-demo.cloud |

All five deploy from [`dhicking/scale-to-zero-target`](https://github.com/dhicking/scale-to-zero-target), a plain Laravel app with one added `/wake` route. Each target has these settings:

- Flex compute with Scale-to-Zero after **1 minute** without requests. This must be shorter than the game's 90-second cooldown, or players race apps that are still awake.
- `SESSION_DRIVER=cookie` and `CACHE_STORE=file`, so the app needs no database.
- No `X-Frame-Options` header, so the preview can load in an iframe.

The `/wake` route skips the `web` middleware so that sessions and cookies do not add to the timed response. It returns these headers:

- `Access-Control-Allow-Origin: *`
- `Timing-Allow-Origin: *`, without which the browser hides the timing data and the measurement falls back to a slower, less accurate clock.
- `Cache-Control: no-store`

### Keep the targets near the booth

Put the targets in the region closest to the event. A cold request crosses the network more times than a warm one, and subtracting the warm baseline does not remove that extra time. When this game ran from Brisbane against targets in Frankfurt, wake times measured 1.3–1.5 seconds. With targets in Sydney, they are well under a second.

To move the game to another event:

1. Create new target apps in a region near the event from `dhicking/scale-to-zero-target`, with the settings above.
2. Update `targets` in `config/game.php` with each app's URL, application ID, and environment ID.

## The game app

The game itself runs as `scale-to-zero-game` in Laravel GTM, ap-southeast-2. Scale-to-Zero is off for the game app, so visitors never wait for it to wake.

To clear the leaderboard and the attendee list before an event, run this command. It deletes all rounds **and** all attendees.

```bash
php artisan leaderboard:reset
```

## Local development

```bash
composer run setup
composer run dev
```

Run the tests with `php artisan test --compact`.
