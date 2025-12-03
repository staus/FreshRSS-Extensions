<?php

class DisableRetryAfterExtension extends Minz_Extension
{
    private const CONFIG_KEY_DOMAINS = 'disableretryafter_domains';
    private const CONFIG_KEY_DISABLE_ALL = 'disableretryafter_disable_all';

    /**
     * @var array<int,string>
     */
    private $bypassDomains = [];

    /**
     * @var bool
     */
    private $disableAll = false;

    public function init()
    {
        parent::init();
        $this->registerHook('feed_before_actualize', [$this, 'feedBeforeActualizeHook']);
        $this->registerTranslates();
    }

    /**
     * Hook called before a feed is actualized (fetched).
     */
    public function feedBeforeActualizeHook(FreshRSS_Feed $feed)
    {
        // Load config lazily on first use
        if (empty($this->bypassDomains) && !$this->disableAll) {
            $this->loadConfig();
        }
        
        $this->processFeedForBypass($feed);
        return $feed;
    }

    /**
     * Process a feed to clear retry-after if it should be bypassed
     */
    private function processFeedForBypass(FreshRSS_Feed $feed): void
    {
        $feedDomain = $this->extractDomain($feed->url(includeCredentials: false));
        
        if ($feedDomain === '') {
            return;
        }

        if ($this->disableAll) {
            $this->clearRetryAfterForDomain($feedDomain);
            $this->logNotice(
                'Bypassed retry-after for domain: %s (feed: %s) [global disable enabled]',
                $feedDomain,
                $feed->name()
            );
            return;
        }

        if (empty($this->bypassDomains)) {
            return;
        }

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
     */
    private function extractDomain(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null || $host === '') {
            return '';
        }
        
        $host = strtolower($host);
        if (str_contains($host, ':')) {
            $host = explode(':', $host, 2)[0];
        }
        
        return $host;
    }

    /**
     * Check if a domain should bypass retry-after
     */
    private function shouldBypassRetryAfter(string $domain): bool
    {
        if (empty($this->bypassDomains)) {
            return false;
        }

        foreach ($this->bypassDomains as $bypassDomain) {
            if ($domain === $bypassDomain) {
                return true;
            }
            
            if (str_ends_with($domain, '.' . $bypassDomain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clear retry-after state for a domain
     */
    private function clearRetryAfterForDomain(string $domain): void
    {
        if ($domain === '') {
            return;
        }

        try {
            $systemConf = FreshRSS_Context::systemConf();
            
            // Try to clear via system configuration
            $possibleKeys = [
                'retry_after_domains',
                'retry_after',
                'retryAfter',
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
        } catch (Exception $e) {
            $this->logWarning('Failed to clear retry-after for domain %s: %s', $domain, $e->getMessage());
        }
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

    /**
     * Load configuration from user settings
     */
    private function loadConfig(): void
    {
        $conf = FreshRSS_Context::userConf();
        
        $domainsInput = $conf->attributeString(self::CONFIG_KEY_DOMAINS, '');
        $this->bypassDomains = $this->parseDomains($domainsInput);
        
        $disableAllStr = $conf->attributeString(self::CONFIG_KEY_DISABLE_ALL, '');
        $this->disableAll = in_array(strtolower($disableAllStr), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * Parse domains from input string
     */
    private function parseDomains(string $input): array
    {
        if ($input === '') {
            return [];
        }

        $domains = preg_split('/[\r\n,]+/', $input) ?: [];
        $cleaned = [];

        foreach ($domains as $domain) {
            $domain = trim($domain);
            
            if ($domain === '') {
                continue;
            }

            if (str_contains($domain, '://')) {
                $domain = parse_url($domain, PHP_URL_HOST) ?? $domain;
            }

            $domain = ltrim(strtolower($domain), '.');
            
            if (str_contains($domain, ':')) {
                $domain = explode(':', $domain, 2)[0];
            }

            if ($domain !== '') {
                $cleaned[] = $domain;
            }
        }

        return array_values(array_unique($cleaned));
    }

    public function handleConfigureAction(): void
    {
        $this->registerTranslates();

        if (!Minz_Request::isPost()) {
            return;
        }

        try {
            $conf = FreshRSS_Context::userConf();
            $domainsInput = trim((string) Minz_Request::param('disableretryafter_domains', ''));
            $disableAll = Minz_Request::param('disableretryafter_disable_all', 'off') === 'on';

            $conf->_attribute(self::CONFIG_KEY_DOMAINS, $domainsInput);
            $conf->_attribute(self::CONFIG_KEY_DISABLE_ALL, $disableAll ? '1' : '');
            $conf->save();
        } catch (Exception $e) {
            // Silently handle errors
        }
    }

    /**
     * Get domains list for configuration page
     */
    public function getDomainsInput(): string
    {
        try {
            $conf = FreshRSS_Context::userConf();
            return $conf->attributeString(self::CONFIG_KEY_DOMAINS, '');
        } catch (Exception $e) {
            return '';
        }
    }

    /**
     * Get disable all setting for configuration page
     */
    public function getDisableAll(): bool
    {
        try {
            $conf = FreshRSS_Context::userConf();
            $disableAllStr = $conf->attributeString(self::CONFIG_KEY_DISABLE_ALL, '');
            return in_array(strtolower($disableAllStr), ['1', 'true', 'on', 'yes'], true);
        } catch (Exception $e) {
            return false;
        }
    }
}
