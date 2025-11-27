# Daily spread refresh extension

This extension keeps large FreshRSS instances from hitting sources all at once.
Every feed that uses the default FreshRSS TTL is assigned a deterministic slot within a configurable interval (24 hours by default).
That slot controls when the feed is allowed to refresh, so hundreds of subscriptions are evenly distributed during the day.

For RSSHub-powered feeds you can configure one or more host names.
Those feeds receive an extra refresh a few minutes after their slot so RSSHub has time to build its cache (first request warms, second request reads).

## Configuration

Open *Extensions → Daily Spread* in FreshRSS and adjust:

- **Refresh interval (hours)** – minimum spacing between two primary refreshes for a feed. 24 h spreads traffic over a full day.
- **RSSHub hosts** – one host per line (e.g. `rsshub.app` or your self-hosted domain). Only matching feeds receive the second request.
- **RSSHub follow-up delay (minutes)** – gap between the priming request and the cached request. Set to `0` to disable the follow-up entirely.

Notes:

- Only feeds set to “By default” in *Do not automatically refresh more often than* are managed by this extension.
- The actual follow-up delay cannot be shorter than your cron schedule. Picapod, for example, launches `actualize_script.php` at `xx:07` and `xx:37`, so a “10 min” follow-up will run at the next cron slot (≈30 min later).
- New feeds are refreshed immediately once so you do not have to wait for their slot.
`
