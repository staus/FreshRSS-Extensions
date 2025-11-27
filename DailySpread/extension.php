<?php

class DailySpreadExtension extends Minz_Extension
{
    private const DEFAULT_INTERVAL_SECONDS = 86_400; // 24h
    private const DEFAULT_FOLLOWUP_DELAY_SECONDS = 600; // 10min

    private const INTERVAL_CONF_KEY = 'daily_spread_interval_seconds';
    private const HOSTS_CONF_KEY = 'daily_spread_rsshub_hosts';
    private const FOLLOWUP_DELAY_CONF_KEY = 'daily_spread_rsshub_followup_seconds';
    private const FOLLOWUP_QUEUE_CONF_KEY = 'daily_spread_pending_followups';
    private const LAST_DISCOVERY_KEY = 'daily_spread_last_discovery';

    public int $refreshIntervalHours = 24;

    public int $rsshubFollowupMinutes = 10;

    public string $rsshubHostInput = 'rsshub.app';

    private int $intervalSeconds = self::DEFAULT_INTERVAL_SECONDS;

    private int $rsshubFollowupDelaySeconds = self::DEFAULT_FOLLOWUP_DELAY_SECONDS;

    /**
     * @var array<int,string>
     */
    private array $rsshubHosts = [];

    /**
     * @var array<int,int>
     */
    private array $pendingFollowups = [];

    /**
     * Track if pendingFollowups has been modified to avoid unnecessary saves
     */
    private bool $pendingFollowupsDirty = false;

    /**
     * Aggregated counters for logging (static to persist across hook calls)
     * @var array{regular_skipped: int, regular_refreshed: int, rsshub_skipped: int, rsshub_refreshed: int, rsshub_followups: int}
     */
    private static array $feedStats = [
        'regular_skipped' => 0,
        'regular_refreshed' => 0,
        'rsshub_skipped' => 0,
        'rsshub_refreshed' => 0,
        'rsshub_followups' => 0,
        'rsshub_queued' => 0,
    ];

    /**
     * Last time we logged aggregated stats
     */
    private static int $lastStatsLogTime = 0;

    public function init()
    {
        parent::init();

        $this->registerHook('feed_before_actualize', [
            $this,
            'feedBeforeActualizeHook',
        ]);
        $this->registerTranslates();
        $this->initConfig();

        // Register shutdown function to batch save configuration at the end of script execution
        register_shutdown_function([$this, 'shutdownSave']);
        
        // Register shutdown function to log aggregated stats
        register_shutdown_function([$this, 'logAggregatedStats']);

        // Run discovery periodically (once per hour) to catch new RSSHub feeds
        $this->runPeriodicDiscovery();
    }

    private function initConfig(): void
    {
        $conf = FreshRSS_Context::userConf();
        $needsSave = false;

        if (!$conf->hasParam(self::INTERVAL_CONF_KEY)) {
            $conf->_attribute(self::INTERVAL_CONF_KEY, self::DEFAULT_INTERVAL_SECONDS);
            $needsSave = true;
        }

        if (!$conf->hasParam(self::HOSTS_CONF_KEY)) {
            $conf->_attribute(self::HOSTS_CONF_KEY, 'rsshub.app');
            $needsSave = true;
        }

        if (!$conf->hasParam(self::FOLLOWUP_DELAY_CONF_KEY)) {
            $conf->_attribute(self::FOLLOWUP_DELAY_CONF_KEY, self::DEFAULT_FOLLOWUP_DELAY_SECONDS);
            $needsSave = true;
        }

        if (!$conf->hasParam(self::FOLLOWUP_QUEUE_CONF_KEY)) {
            $conf->_attribute(self::FOLLOWUP_QUEUE_CONF_KEY, []);
            $needsSave = true;
        }

        if ($needsSave) {
            $conf->save();
        }

        $interval = (int) $conf->attributeInt(self::INTERVAL_CONF_KEY);
        $followupDelay = (int) $conf->attributeInt(self::FOLLOWUP_DELAY_CONF_KEY);

        $this->intervalSeconds = max(3600, $interval);
        $this->rsshubFollowupDelaySeconds = max(0, $followupDelay);
        $this->rsshubHostInput = $conf->attributeString(self::HOSTS_CONF_KEY) ?? 'rsshub.app';
        $this->rsshubHosts = $this->parseHosts($this->rsshubHostInput);

        $this->pendingFollowups = $this->sanitizePendingFollowups($conf->attributeArray(self::FOLLOWUP_QUEUE_CONF_KEY));
        $this->pendingFollowupsDirty = false;
        $this->pruneOutdatedFollowups();

        $this->refreshIntervalHours = (int) max(1, round($this->intervalSeconds / 3600));

        if ($this->rsshubFollowupDelaySeconds === 0) {
            $this->rsshubFollowupMinutes = 0;
        } else {
            $this->rsshubFollowupMinutes = (int) max(1, round($this->rsshubFollowupDelaySeconds / 60));
        }
    }

    /**
     * Called when the configuration page is loaded or saved.
     */
    public function handleConfigureAction(): void
    {
        $this->registerTranslates();

        if (!Minz_Request::isPost()) {
            return;
        }

        $intervalHours = max(1, Minz_Request::paramInt('daily_spread_interval_hours'));
        $followupMinutes = max(1, Minz_Request::paramInt('daily_spread_rsshub_followup_minutes'));
        $hosts = trim((string) Minz_Request::param('daily_spread_rsshub_hosts', ''));

        $conf = FreshRSS_Context::userConf();
        $conf->_attribute(self::INTERVAL_CONF_KEY, $intervalHours * 3600);
        $conf->_attribute(self::FOLLOWUP_DELAY_CONF_KEY, $followupMinutes * 60);
        $conf->_attribute(self::HOSTS_CONF_KEY, $hosts);
        $conf->save();

        $this->initConfig();

        $hostSummary = empty($this->rsshubHosts) ? 'none' : implode(', ', $this->rsshubHosts);
        $this->logNotice(
            'Configuration updated: interval %dh, RSSHub follow-up %d min, hosts: %s',
            $this->refreshIntervalHours,
            $this->rsshubFollowupMinutes,
            $hostSummary,
        );

        // Discover and schedule follow-ups for all RSSHub feeds
        if ($this->rsshubFollowupDelaySeconds > 0 && !empty($this->rsshubHosts)) {
            $this->discoverAndScheduleRssHubFollowups(true);
        }
    }

    /**
     * Run discovery periodically (throttled to once per hour).
     */
    private function runPeriodicDiscovery(): void
    {
        if ($this->rsshubFollowupDelaySeconds <= 0 || empty($this->rsshubHosts)) {
            return;
        }

        $conf = FreshRSS_Context::userConf();
        $lastDiscovery = $conf->attributeInt(self::LAST_DISCOVERY_KEY) ?? 0;
        $now = time();
        
        // Run discovery at most once per hour
        if (($now - $lastDiscovery) >= 3600) {
            $this->discoverAndScheduleRssHubFollowups(false);
            $conf->_attribute(self::LAST_DISCOVERY_KEY, $now);
            $conf->save();
        }
    }

    public function feedBeforeActualizeHook(FreshRSS_Feed $feed)
    {
        $feedId = $feed->id();
        $feedName = $feed->name();

        if ($feed->lastUpdate() === 0) {
            $this->logDebug(
                'feed %d (%s) never updated, updating now',
                $feedId,
                $feedName,
            );

            return $feed;
        }

        if ($feed->ttl() !== FreshRSS_Feed::TTL_DEFAULT) {
            $this->logDebug(
                'feed %d (%s) bypassed because it has a custom TTL',
                $feedId,
                $feedName,
            );

            return $feed;
        }

        $now = time();

        if (isset($this->pendingFollowups[$feedId])) {
            $dueAt = $this->pendingFollowups[$feedId];

            if ($now >= $dueAt) {
                $this->clearFollowup($feedId, $feedName, false);
                self::$feedStats['rsshub_followups']++;
                $this->logPeriodicStats();

                return $feed;
            }

            // RSSHub feed waiting for follow-up - skip it
            self::$feedStats['rsshub_skipped']++;
            $this->logPeriodicStats();

            // Return null to skip this feed until the follow-up is due
            return null;
        }

        if (!$this->shouldRunPrimary($feed, $now)) {
            // Track skipped feed (regular or RSSHub)
            $isRssHub = $this->isRssHubFeed($feed);
            if ($isRssHub) {
                self::$feedStats['rsshub_skipped']++;
            } else {
                self::$feedStats['regular_skipped']++;
            }
            $this->logPeriodicStats();

            // Return null to skip this feed - this overrides FreshRSS's default refresh schedule
            // FreshRSS will keep trying to refresh based on its cron, but our hook will keep
            // returning null until the feed's daily slot is reached, effectively enforcing 24hr intervals
            return null;
        }

        // Track refreshed feed (regular or RSSHub)
        $isRssHub = $this->isRssHubFeed($feed);
        if ($isRssHub) {
            self::$feedStats['rsshub_refreshed']++;
        } else {
            self::$feedStats['regular_refreshed']++;
        }

        if ($this->rsshubFollowupDelaySeconds > 0 && $isRssHub) {
            // Only schedule if not already scheduled
            if (!isset($this->pendingFollowups[$feedId])) {
                $this->scheduleFollowup($feedId, $now + $this->rsshubFollowupDelaySeconds, $feedName, false);
            }
        }

        $this->logPeriodicStats();

        return $feed;
    }

    /**
     * Discover all RSSHub feeds and schedule follow-ups for them.
     * This ensures all RSSHub feeds get follow-ups scheduled automatically,
     * not just ones that have already run their primary refresh.
     * 
     * @param bool $forceLog Whether to always log the results (true) or only log if changes were made (false)
     */
    private function discoverAndScheduleRssHubFollowups(bool $forceLog = false): void
    {
        if ($this->rsshubFollowupDelaySeconds <= 0 || empty($this->rsshubHosts)) {
            return;
        }

        try {
            $feedDao = FreshRSS_Factory::createFeedDao();
            $feeds = $feedDao->listFeeds();
            $now = time();
            $scheduled = 0;
            $skipped = 0;

            foreach ($feeds as $feed) {
                // Only process feeds with default TTL (managed by this extension)
                if ($feed->ttl() !== FreshRSS_Feed::TTL_DEFAULT) {
                    continue;
                }

                // Only process RSSHub feeds
                if (!$this->isRssHubFeed($feed)) {
                    continue;
                }

                $feedId = $feed->id();
                $feedName = $feed->name();

                // Skip if already has a follow-up scheduled
                if (isset($this->pendingFollowups[$feedId])) {
                    $skipped++;
                    continue;
                }

                // Calculate when this feed's next refresh should occur
                $nextRefreshTime = $this->calculateNextRefreshTime($feed, $now);
                
                // Schedule follow-up for: next refresh time + followup delay
                $followupTime = $nextRefreshTime + $this->rsshubFollowupDelaySeconds;
                
                $this->scheduleFollowup($feedId, $followupTime, $feedName, false);
                $scheduled++;

                $this->logDebug(
                    'Auto-scheduled RSSHub follow-up for feed %d (%s): next refresh at %s, follow-up at %s',
                    $feedId,
                    $feedName,
                    date('r', $nextRefreshTime),
                    date('r', $followupTime),
                );
            }

            if ($forceLog || $scheduled > 0) {
                $this->logNotice(
                    'Discovered RSSHub feeds: scheduled %d new follow-up(s), skipped %d (already scheduled)',
                    $scheduled,
                    $skipped,
                );
            }
        } catch (Exception $e) {
            $this->logWarning('Error discovering RSSHub feeds: %s', $e->getMessage());
        }
    }

    /**
     * Calculate when a feed's next scheduled refresh should occur.
     */
    private function calculateNextRefreshTime(FreshRSS_Feed $feed, int $now): int
    {
        $lastUpdate = $feed->lastUpdate();
        
        // If never updated, schedule for now
        if ($lastUpdate === 0) {
            return $now;
        }

        // If it's been more than 2 intervals since last update, schedule for now
        if (($now - $lastUpdate) >= (2 * $this->intervalSeconds)) {
            return $now;
        }

        // Calculate the feed's slot
        $slot = $this->slotForFeed($feed);
        
        // Calculate current window
        $currentWindow = $this->windowIndex($now, $slot);
        $lastWindow = $this->windowIndex($lastUpdate, $slot);

        // If we're in a new window, the next refresh should be now
        if ($currentWindow > $lastWindow) {
            return $now;
        }

        // Otherwise, calculate when the next window starts
        // Next window starts at: slot + (currentWindow + 1) * intervalSeconds
        $nextWindowStart = $slot + (($currentWindow + 1) * $this->intervalSeconds);
        
        return $nextWindowStart;
    }

    private function describeRssHubStatus(int $feedId, int $now): string
    {
        if (!isset($this->pendingFollowups[$feedId])) {
            return 'Follow-up completed';
        }

        $dueAt = $this->pendingFollowups[$feedId];

        if ($dueAt <= $now) {
            return 'Follow-up ready now';
        }

        return sprintf('Follow-up queued for %s', date('Y-m-d H:i', $dueAt));
    }

    /**
     * Called at script shutdown to persist any pending changes.
     * This allows batching configuration saves instead of saving on every feed.
     */
    public function shutdownSave(): void
    {
        if ($this->pendingFollowupsDirty) {
            $this->persistPendingFollowups(true);
        }
    }

    /**
     * Log aggregated stats periodically (every 30 seconds or on shutdown).
     */
    public function logAggregatedStats(): void
    {
        $this->logPeriodicStats(true);
    }

    /**
     * Log aggregated feed statistics periodically to avoid log pollution.
     * 
     * @param bool $force Force logging even if not enough time has passed
     */
    private function logPeriodicStats(bool $force = false): void
    {
        $now = time();
        $timeSinceLastLog = $now - self::$lastStatsLogTime;

        $logInterval = 3600; // one hour

        // Log once per hour (or when forced)
        if (!$force && $timeSinceLastLog < $logInterval) {
            return;
        }

        $total = array_sum(self::$feedStats);
        if ($total === 0) {
            return;
        }

        $regularSkipped = self::$feedStats['regular_skipped'];
        $regularRefreshed = self::$feedStats['regular_refreshed'];
        $rsshubSkipped = self::$feedStats['rsshub_skipped'];
        $rsshubRefreshed = self::$feedStats['rsshub_refreshed'];
        $rsshubFollowups = self::$feedStats['rsshub_followups'];
        $rsshubQueued = self::$feedStats['rsshub_queued'];

        $messages = [];
        
        if ($regularSkipped > 0) {
            $messages[] = sprintf('%d regular feed(s) skipped (not their slot)', $regularSkipped);
        }
        if ($regularRefreshed > 0) {
            $messages[] = sprintf('%d regular feed(s) refreshed', $regularRefreshed);
        }
        if ($rsshubSkipped > 0) {
            $messages[] = sprintf('%d RSSHub feed(s) skipped (not their slot)', $rsshubSkipped);
        }
        if ($rsshubRefreshed > 0) {
            $messages[] = sprintf('%d RSSHub feed(s) refreshed', $rsshubRefreshed);
        }
        if ($rsshubQueued > 0) {
            $messages[] = sprintf('%d RSSHub follow-up(s) queued', $rsshubQueued);
        }
        if ($rsshubFollowups > 0) {
            $messages[] = sprintf('%d RSSHub follow-up(s) executed', $rsshubFollowups);
        }

        if (!empty($messages)) {
            $periodLabel = $timeSinceLastLog > 0
                ? sprintf('last %d minute(s)', (int) max(1, round($timeSinceLastLog / 60)))
                : 'current period';

            $this->logNotice('Feed refresh summary (%s): %s', $periodLabel, implode(', ', $messages));
        }

        // Reset counters
        self::$feedStats = [
            'regular_skipped' => 0,
            'regular_refreshed' => 0,
            'rsshub_skipped' => 0,
            'rsshub_refreshed' => 0,
            'rsshub_followups' => 0,
            'rsshub_queued' => 0,
        ];
        self::$lastStatsLogTime = $now;
    }

    /**
     * @return array<int,array<string,int|string>>
     */
    /**
     * @return array{
     *     rsshub: array<int,array<string,int|string>>,
     *     regular: array<int,array<string,int|string>>
     * }
     */
    public function getTimingPreview(): array
    {
        $feedDao = FreshRSS_Factory::createFeedDao();
        $feeds = $feedDao->listFeeds();
        $now = time();
        $rsshub = [];
        $regular = [];

        foreach ($feeds as $feed) {
            if (!$feed instanceof FreshRSS_Feed || $feed->ttl() !== FreshRSS_Feed::TTL_DEFAULT) {
                continue;
            }

            $feedId = $feed->id();
            $row = [
                'feedId' => $feedId,
                'feedName' => $feed->name(),
                'fetchTime' => $this->calculateNextRefreshTime($feed, $now),
            ];

            if ($this->isRssHubFeed($feed)) {
                $row['status'] = $this->describeRssHubStatus($feedId, $now);
                $rsshub[] = $row;
            } else {
                $row['status'] = 'Single refresh (no follow-up)';
                $regular[] = $row;
            }
        }

        usort($rsshub, static fn ($a, $b) => $a['fetchTime'] <=> $b['fetchTime']);
        usort($regular, static fn ($a, $b) => $a['fetchTime'] <=> $b['fetchTime']);

        return [
            'rsshub' => $rsshub,
            'regular' => $regular,
        ];
    }

    private function shouldRunPrimary(FreshRSS_Feed $feed, int $now): bool
    {
        if ($feed->lastUpdate() === 0) {
            return true;
        }

        if (($now - $feed->lastUpdate()) >= (2 * $this->intervalSeconds)) {
            return true;
        }

        $slot = $this->slotForFeed($feed);
        $currentWindow = $this->windowIndex($now, $slot);
        $lastWindow = $this->windowIndex($feed->lastUpdate(), $slot);

        return $currentWindow > $lastWindow;
    }

    private function windowIndex(int $timestamp, int $slot): int
    {
        if ($timestamp <= 0) {
            return -1_000_000_000;
        }

        $value = ($timestamp - $slot) / $this->intervalSeconds;

        return (int) floor($value);
    }

    /**
     * Calculate a deterministic time slot for a feed within the refresh interval.
     * 
     * This creates a single unified slot space for ALL feeds (both RSSHub and non-RSSHub).
     * RSSHub feeds and regular feeds can share the same time slots - that's perfectly fine.
     * RSSHub feeds just get an additional follow-up refresh 10 minutes after their primary refresh.
     * 
     * The slot is based on the feed's URL, ID, and system salt, ensuring:
     * - Deterministic assignment (same feed always gets same slot)
     * - Even distribution across the 24-hour period
     * - No separation between RSSHub and non-RSSHub feeds (they share the same slot space)
     */
    private function slotForFeed(FreshRSS_Feed $feed): int
    {
        if ($this->intervalSeconds <= 0) {
            return 0;
        }

        $input = sprintf(
            '%s|%d|%s',
            $feed->url(includeCredentials: false),
            $feed->id(),
            FreshRSS_Context::systemConf()->salt ?? '',
        );

        $hash = crc32($input);
        if ($hash < 0) {
            $hash += 2 ** 32;
        }

        return (int) ($hash % $this->intervalSeconds);
    }

    private function isRssHubFeed(FreshRSS_Feed $feed): bool
    {
        if (empty($this->rsshubHosts)) {
            return false;
        }

        $host = strtolower(parse_url($feed->url(includeCredentials: false), PHP_URL_HOST) ?? '');

        if ($host === '') {
            return false;
        }

        foreach ($this->rsshubHosts as $allowedHost) {
            if ($host === $allowedHost || str_ends_with($host, '.'.$allowedHost)) {
                return true;
            }
        }

        return false;
    }

    private function scheduleFollowup(int $feedId, int $timestamp, ?string $feedName = null, bool $saveImmediately = true): void
    {
        $this->pendingFollowups[$feedId] = $timestamp;
        $this->pendingFollowupsDirty = true;

        if ($saveImmediately) {
            $this->persistPendingFollowups(true);
        }
        self::$feedStats['rsshub_queued']++;
        $this->logPeriodicStats();
    }

    private function clearFollowup(int $feedId, ?string $feedName = null, bool $saveImmediately = true): void
    {
        if (!isset($this->pendingFollowups[$feedId])) {
            return;
        }

        unset($this->pendingFollowups[$feedId]);
        $this->pendingFollowupsDirty = true;

        if ($saveImmediately) {
            $this->persistPendingFollowups(true);
        }

        $this->logPeriodicStats();
    }

    /**
     * @param array<int|string,int|string>|null $raw
     *
     * @return array<int,int>
     */
    private function sanitizePendingFollowups(?array $raw): array
    {
        if ($raw === null) {
            return [];
        }

        $result = [];
        foreach ($raw as $feedId => $timestamp) {
            $feedId = (int) $feedId;
            $timestamp = (int) $timestamp;

            if ($feedId > 0 && $timestamp > 0) {
                $result[$feedId] = $timestamp;
            }
        }

        return $result;
    }

    private function pruneOutdatedFollowups(): void
    {
        if (empty($this->pendingFollowups)) {
            return;
        }

        $threshold = time() - (2 * $this->intervalSeconds);
        $updated = false;
        $initialCount = count($this->pendingFollowups);

        foreach ($this->pendingFollowups as $feedId => $dueAt) {
            if ($dueAt < $threshold) {
                unset($this->pendingFollowups[$feedId]);
                $updated = true;
            }
        }

        if ($updated) {
            $this->pendingFollowupsDirty = true;
            $this->persistPendingFollowups(true);
            $removed = $initialCount - count($this->pendingFollowups);
            $this->logNotice(
                'Removed %d stale RSSHub follow-up(s) older than %s',
                $removed,
                date('r', $threshold),
            );
        }
    }

    private function persistPendingFollowups(bool $force = false): void
    {
        if (!$force && !$this->pendingFollowupsDirty) {
            return;
        }

        $conf = FreshRSS_Context::userConf();
        $conf->_attribute(self::FOLLOWUP_QUEUE_CONF_KEY, $this->pendingFollowups);
        $conf->save();
        $this->pendingFollowupsDirty = false;
    }

    /**
     * @return array<int,string>
     */
    private function parseHosts(string $input): array
    {
        $hosts = preg_split('/[\s,]+/', strtolower($input)) ?: [];
        $cleaned = [];

        foreach ($hosts as $host) {
            $host = trim($host);

            if ($host === '') {
                continue;
            }

            if (str_contains($host, '://')) {
                $host = parse_url($host, PHP_URL_HOST) ?: $host;
            }

            $host = ltrim($host, '.');

            if ($host !== '') {
                $cleaned[] = $host;
            }
        }

        return array_values(array_unique($cleaned));
    }

    private function logDebug(string $message, mixed ...$arguments): void
    {
        Minz_Log::debug($this->formatLogMessage($message, $arguments));
    }

    private function logNotice(string $message, mixed ...$arguments): void
    {
        Minz_Log::notice($this->formatLogMessage($message, $arguments));
    }

    private function logWarning(string $message, mixed ...$arguments): void
    {
        // Use warning level for important operational events to ensure visibility in FreshRSS interface
        $formattedMessage = $this->formatLogMessage($message, $arguments);
        
        // Try warning() first, fall back to warn() or notice() if needed
        if (is_callable(['Minz_Log', 'warning'])) {
            Minz_Log::warning($formattedMessage);
        } elseif (is_callable(['Minz_Log', 'warn'])) {
            Minz_Log::warn($formattedMessage);
        } else {
            // Fall back to notice if warning methods don't exist
            Minz_Log::notice($formattedMessage);
        }
    }

    /**
     * @param array<int,mixed> $arguments
     */
    private function formatLogMessage(string $message, array $arguments): string
    {
        if ($arguments !== []) {
            /** @var list<mixed> $arguments */
            $message = vsprintf($message, $arguments);
        }

        return 'DailySpread: ' . $message;
    }
}
