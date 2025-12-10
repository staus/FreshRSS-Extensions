# TDMchecker Extension for FreshRSS

Automatically checks if publishers have opted out of TDM (Text and Data Mining) under the EU AI Act and displays the status on feed management pages.

## Features

- **Automatic Checking**: Checks TDM opt-out status when feeds are refreshed
- **24-Hour Caching**: Results are cached for 24 hours to avoid unnecessary API calls
- **Non-Blocking**: Checks are performed asynchronously to avoid slowing down feed refreshes
- **Feed Management Display**: Shows TDM opt-out status directly on the feed management page
- **Manual Check**: Option to manually trigger checks from the configuration page

## How It Works

1. When a feed is refreshed, the extension checks if the TDM status needs to be updated (cache expired or never checked)
2. If needed, it calls the Cloudflare worker API with the feed's website URL (not the feed URL)
3. The API response is parsed to extract `summary.tdm_opt_out_detected`
4. The result is stored with a timestamp and displayed on the feed management page
5. Results are cached for 24 hours to respect the API's caching mechanism

## Installation

1. Copy the `TDMchecker` folder into your FreshRSS `extensions` directory
2. Sign in to FreshRSS as an admin and open `Configuration → Extensions`
3. Enable the "TDMchecker" extension

## Usage

### Automatic Checking

The extension automatically checks TDM status when feeds are refreshed. No configuration is needed.

### Manual Checking

1. Go to `Configuration → Extensions → TDMchecker → Configure`
2. Find the feed you want to check in the table
3. Click "Check Now" to manually trigger a check (bypasses cache)

### Viewing Status

The TDM opt-out status is displayed on each feed's management page, right under the Website URL field. It shows:
- `true` - Publisher has opted out of TDM
- `false` - Publisher has not opted out of TDM
- `null (not checked)` - Status has not been checked yet

## API Details

The extension uses a Cloudflare worker API:
- URL: `https://cloudflareworker-scraper.manyone-developers-account.workers.dev/`
- Parameter: `domain` (the website URL)
- Response: JSON with `summary.tdm_opt_out_detected` boolean
- First check: Can take up to 5 seconds
- Caching: Results are cached by the API for 24 hours

## Technical Details

- **Cache Duration**: 24 hours (86,400 seconds)
- **API Timeout**: 10 seconds
- **Storage**: TDM status is stored in user configuration
- **Hooks Used**: 
  - `feed_management` - Display status on feed management page
  - `feed_before_actualize` - Trigger checks during feed refresh

## Notes

- Only feeds with a website URL will be checked
- The extension uses the website URL, not the feed URL, for checking
- Checks are performed asynchronously to avoid blocking feed refreshes
- If an API call fails, the previous cached status (if any) is retained
