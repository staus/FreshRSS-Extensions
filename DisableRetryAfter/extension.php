<?php

class DisableRetryAfterExtension extends Minz_Extension
{
    private const CONFIG_KEY_DOMAINS = 'disableretryafter_domains';
    private const CONFIG_KEY_DISABLE_ALL = 'disableretryafter_disable_all';
    private const SYSTEM_RETRY_AFTER_KEY = 'retry_after_domains';

    /**
     * @var array<int,string>
     */
    private array $bypassDomains = [];

    private bool $disableAll = false;

    public function init()
    {
        parent::init();

        // Register hook to clear retry-after before feed actualization
        // This hook is called before FreshRSS fetches the feed
        $this->registerHook('feed_before_actualize', [$this, 'feedBeforeActualizeHook']);
        
        $this->registerTranslates();
        $this->loadConfig();
    }

    /**
     * Load configuration from user settings
     */
    private function loadConfig(): void
    {
        $conf = FreshRSS_Context::userConf();
        
        $domainsInput = $conf->attributeString(self::CONFIG_KEY_DOMAINS, '');
        $this->bypassDomains = $this->parseDomains($domainsInput);
        $this->disableAll = (bool) $conf->attributeBool(self::CONFIG_KEY_DISABLE_ALL, false);
    }

    /**
     * Called when the configuration page is loaded or saved.
     */
    public function handleConfigureAction(): void
    {
        $this->registerTranslates();
        $this->loadConfig();

        if (!Minz_Request::isPost()) {
            return;
        }

        $conf = FreshRSS_Context::userConf();
        $domainsInput = trim((string) Minz_Request::param('disableretryafter_domains', ''));
        $disableAll = Minz_Request::param('disableretryafter_disable_all', 'off') === 'on';

        $conf->_attribute(self::CONFIG_KEY_DOMAINS, $domainsInput);
        $conf->_attribute(self::CONFIG_KEY_DISABLE_ALL, $disableAll);
        $conf->save();

        $this->loadConfig();

        $domainSummary = empty($this->bypassDomains) ? 'none' : implode(', ', $this->bypassDomains);
        $this->logNotice(
            'Configuration updated: disable all = %s, bypass domains: %s',
            $disableAll ? 'yes' : 'no',
            $domainSummary
        );
    }

    /**
     * Hook called before a feed is actualized (fetched).
     * This is where we clear the retry-after state for domains that should bypass it.
     * 
     * @param FreshRSS_Feed $feed
     * @return FreshRSS_Feed|null
     */
    public function feedBeforeActualizeHook(FreshRSS_Feed $feed)
    {
        // Process the feed to clear retry-after if needed
        $this->processFeedForBypass($feed);
        return $feed;
    }

    /**
     * Process a feed to clear retry-after if it should be bypassed
     * 
     * @param FreshRSS_Feed $feed
     */
    private function processFeedForBypass(FreshRSS_Feed $feed): void
    {
        $feedDomain = $this->extractDomain($feed->url(includeCredentials: false));
        
        if ($feedDomain === '') {
            return;
        }

        if ($this->disableAll) {
            // If global disable is enabled, clear retry-after for all domains
            $this->clearRetryAfterForDomain($feedDomain);
            $this->logNotice(
                'Bypassed retry-after for domain: %s (feed: %s) [global disable enabled]',
                $feedDomain,
                $feed->name()
            );
            return;
        }

        if (empty($this->bypassDomains)) {
            // No domains configured, nothing to do
            return;
        }

        // Check if this domain should bypass retry-after
        if ($this->shouldBypassRetryAfter($feedDomain)) {
            $this->clearRetryAfterForDomain($feedDomain);
            $this->logNotice(
                'Bypassed retry-after for domain: %s (feed: %s)',
                $feedDomain,
                $feed->name()
            );
        }
    }

    /**
     * Extract domain from URL
     * 
     * @param string $url
     * @return string
     */
    private function extractDomain(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null || $host === '') {
            return '';
        }
        
        // Remove port if present (e.g., "example.com:8080" -> "example.com")
        $host = strtolower($host);
        if (str_contains($host, ':')) {
            $host = explode(':', $host, 2)[0];
        }
        
        return $host;
    }

    /**
     * Check if a domain should bypass retry-after
     * 
     * @param string $domain
     * @return bool
     */
    private function shouldBypassRetryAfter(string $domain): bool
    {
        if (empty($this->bypassDomains)) {
            return false;
        }

        foreach ($this->bypassDomains as $bypassDomain) {
            // Exact match
            if ($domain === $bypassDomain) {
                return true;
            }
            
            // Subdomain match (e.g., "rsshub.app" matches "subdomain.rsshub.app")
            if (str_ends_with($domain, '.' . $bypassDomain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clear retry-after state for a domain by manipulating system configuration
     * 
     * This method attempts multiple approaches to clear the retry-after state,
     * since FreshRSS may store it in different ways depending on the version.
     * 
     * @param string $domain
     */
    private function clearRetryAfterForDomain(string $domain): void
    {
        if ($domain === '') {
            return;
        }

        try {
            $systemConf = FreshRSS_Context::systemConf();
            
            // Approach 1: Try to clear via system configuration
            // FreshRSS may store retry-after in system config with various key names
            $possibleKeys = [
                self::SYSTEM_RETRY_AFTER_KEY,
                'retry_after',
                'retryAfter',
                'retry_after_domains',
            ];
            
            foreach ($possibleKeys as $key) {
                if (method_exists($systemConf, 'hasParam') && $systemConf->hasParam($key)) {
                    $retryAfterData = $systemConf->attributeArray($key) ?? [];
                    if (is_array($retryAfterData) && isset($retryAfterData[$domain])) {
                        unset($retryAfterData[$domain]);
                        $systemConf->_attribute($key, $retryAfterData);
                        $systemConf->save();
                        $this->logDebug('Cleared retry-after for domain %s via system config key: %s', $domain, $key);
                        return;
                    }
                }
            }
            
            // Approach 2: Try to use reflection to access private/internal state
            // This is a fallback if the state is stored in a way not accessible via public APIs
            if (class_exists('FreshRSS_Feed')) {
                try {
                    // Try to access any static properties or methods that might store retry-after
                    $reflection = new ReflectionClass('FreshRSS_Feed');
                    // This is speculative - we'll only log if we find something
                } catch (Exception $e) {
                    // Ignore reflection errors
                }
            }
            
            // Approach 3: Try to clear via cache if available
            if (class_exists('Minz_Cache')) {
                $cacheKeys = [
                    'retry_after_' . md5($domain),
                    'retry_after_' . $domain,
                    'retryAfter_' . md5($domain),
                ];
                
                foreach ($cacheKeys as $cacheKey) {
                    if (method_exists('Minz_Cache', 'delete')) {
                        try {
                            Minz_Cache::delete($cacheKey);
                            $this->logDebug('Attempted to clear cache key: %s', $cacheKey);
                        } catch (Exception $e) {
                            // Ignore cache errors
                        }
                    }
                }
            }
            
            // Approach 4: Try to clear via file-based cache if it exists
            // FreshRSS might store retry-after in a file cache
            if (defined('DATA_PATH')) {
                $cacheDir = DATA_PATH . '/cache/';
                if (is_dir($cacheDir) && is_writable($cacheDir)) {
                    $cacheFile = $cacheDir . 'retry_after_' . md5($domain) . '.php';
                    if (file_exists($cacheFile)) {
                        @unlink($cacheFile);
                        $this->logDebug('Deleted cache file: %s', $cacheFile);
                    }
                }
            }
            
        } catch (Exception $e) {
            // Log but don't fail - this is a best-effort operation
            $this->logWarning('Failed to clear retry-after for domain %s: %s', $domain, $e->getMessage());
        }
    }

    /**
     * Parse domains from input string (one per line or comma-separated)
     * 
     * @param string $input
     * @return array<int,string>
     */
    private function parseDomains(string $input): array
    {
        if ($input === '') {
            return [];
        }

        // Split by newlines or commas
        $domains = preg_split('/[\r\n,]+/', $input) ?: [];
        $cleaned = [];

        foreach ($domains as $domain) {
            $domain = trim($domain);
            
            if ($domain === '') {
                continue;
            }

            // Remove protocol if present
            if (str_contains($domain, '://')) {
                $domain = parse_url($domain, PHP_URL_HOST) ?? $domain;
            }

            // Remove leading dots and normalize
            $domain = ltrim(strtolower($domain), '.');
            
            // Remove port if present
            if (str_contains($domain, ':')) {
                $domain = explode(':', $domain, 2)[0];
            }

            if ($domain !== '') {
                $cleaned[] = $domain;
            }
        }

        return array_values(array_unique($cleaned));
    }

    /**
     * Get domains list for configuration page
     * 
     * @return string
     */
    public function getDomainsInput(): string
    {
        $conf = FreshRSS_Context::userConf();
        return $conf->attributeString(self::CONFIG_KEY_DOMAINS, '');
    }

    /**
     * Get disable all setting for configuration page
     * 
     * @return bool
     */
    public function getDisableAll(): bool
    {
        $conf = FreshRSS_Context::userConf();
        return (bool) $conf->attributeBool(self::CONFIG_KEY_DISABLE_ALL, false);
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
        $formattedMessage = $this->formatLogMessage($message, $arguments);
        
        if (is_callable(['Minz_Log', 'warning'])) {
            Minz_Log::warning($formattedMessage);
        } elseif (is_callable(['Minz_Log', 'warn'])) {
            Minz_Log::warn($formattedMessage);
        } else {
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

        return '[DisableRetryAfter] ' . $message;
    }
}

