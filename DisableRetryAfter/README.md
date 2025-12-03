# DisableRetryAfter Extension

This FreshRSS extension allows you to bypass or completely disable the "Retry-After" mechanism for specific domains. This is particularly useful for RSSHub feeds and self-hosted services that may have frequent but short-lived failures.

## Problem

Since FreshRSS v1.27.0, the "Retry-After" mechanism enforces domain-wide cooldown periods when feeds return 429 (Too Many Requests) or 503 (Service Unavailable) errors. While this is good for respecting server limits, it can cause issues with:

- **RSSHub feeds**: RSSHub's web-scraping nature can result in frequent but short-lived 503 errors. A single failure puts the entire RSSHub domain into a long cooldown period that cannot be manually overridden.
- **Self-hosted services**: If you run your own RSS aggregators or services, temporary failures can lock you out from updating any feeds from that source.
- **Local networks**: Services running on local IP addresses (e.g., Docker containers) may have temporary failures that shouldn't block all feeds.

## Solution

This extension provides two ways to handle the Retry-After mechanism:

1. **Domain-specific bypass**: Add specific domains to a bypass list. Feeds from these domains will not be blocked by the Retry-After mechanism.
2. **Global disable**: Optionally disable the Retry-After mechanism entirely for all domains.

## Features

- Configure a list of domains to bypass Retry-After
- Optional checkbox to completely disable Retry-After for all domains
- Supports hostnames, IP addresses, and URLs (protocols and ports are automatically stripped)
- Subdomain matching (e.g., `rsshub.app` also matches `subdomain.rsshub.app`)
- Automatic clearing of retry-after state before feed fetching

## Configuration

Open *Extensions → DisableRetryAfter* in FreshRSS and configure:

### Domains to bypass Retry-After

Enter one domain per line (or comma-separated). Examples:
- `rsshub.app` - Bypass retry-after for RSSHub
- `172.17.0.1` - Bypass for local Docker services
- `rsshub.example.com` - Bypass for self-hosted RSSHub instance
- `192.168.1.100:8080` - Ports are automatically stripped

You can enter:
- Hostnames: `rsshub.app`
- IP addresses: `172.17.0.1`
- Full URLs: `https://rsshub.example.com:8080` (protocol and port will be stripped)

### Completely disable Retry-After mechanism

When enabled, the Retry-After mechanism is completely disabled for all domains. This overrides the domain list. Use with caution as this may cause excessive requests to servers.

## How It Works

The extension hooks into the `feed_before_actualize` event and:

1. Checks if the feed's domain is in the bypass list (or if global disable is enabled)
2. If so, attempts to clear the retry-after state for that domain
3. Allows the feed to be fetched normally

This happens before FreshRSS checks the retry-after state, so feeds from bypassed domains can be refreshed even when they would normally be blocked.

## Use Cases

### RSSHub Feeds

If you use RSSHub (either the public instance or self-hosted), add your RSSHub domain(s) to the bypass list:

```
rsshub.app
rsshub.example.com
172.17.0.1:1200
```

This ensures that temporary 503 errors from RSSHub don't block all your RSSHub feeds.

### Self-Hosted Services

If you run your own RSS aggregators or services, add their domains:

```
rss.example.com
192.168.1.100
```

### Local Networks

For services running in Docker or on local networks:

```
172.17.0.1
192.168.1.1
localhost
```

## Notes

- This extension works by clearing the retry-after state before feed fetching. If a domain is already in a retry-after cooldown period, you may need to wait for the next feed refresh cycle for the bypass to take effect.
- The extension attempts to clear retry-after state through available FreshRSS APIs. If the state is stored in a way that's not accessible via extensions, the bypass may not work in all cases.
- Use the global disable option with caution, as it may cause excessive requests to servers that are genuinely rate-limiting you.

## Related Issues

This extension addresses the problems described in:
- [FreshRSS Issue #7880](https://github.com/FreshRSS/FreshRSS/issues/7880) - Feature request to improve Retry-After control
- [FreshRSS Issue #7870](https://github.com/FreshRSS/FreshRSS/issues/7870) - Bug report about domain-wide retry-after blocking all feeds

## License

AGPL-3.0

