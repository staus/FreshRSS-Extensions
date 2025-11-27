# FreshRSS Extensions Collection

Small, focused add-ons I use to make FreshRSS easier to manage at scale. Each lives in its own folder, comes with a `metadata.json`, and can be dropped straight into `./extensions`.

## What's Included
- **AutoTagger** – scans new articles for regex patterns (deal terms, discount markers, urgency words, etc.) and applies matching tags automatically so I can triage shopping feeds quickly.
- **DailySpread** – staggers feed refreshes across the day and gives RSSHub-backed feeds a second pass so they can cache after cold start.

## Installation
1. Copy each extension folder (e.g. `AutoTagger`, `DailySpread`) into your FreshRSS `extensions` directory, or clone this repo directly there.
2. Sign in to FreshRSS as an admin and open `Configuration → Extensions`.
3. Enable the extension you copied and hit **Configure** to adjust its settings.