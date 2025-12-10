<?php

class TDMcheckerExtension extends Minz_Extension
{
    private const CACHE_DURATION_SECONDS = 86_400; // 24 hours
    private const TDM_STATUS_KEY = 'tdmchecker_status';
    private const WORKER_URL = 'https://cloudflareworker-scraper.manyone-developers-account.workers.dev/';
    private const CHECK_TIMEOUT_SECONDS = 10; // Allow up to 10 seconds for API call

    public function init()
    {
        parent::init();

        // Hook into feed refresh to periodically check TDM status
        $this->registerHook('feed_before_actualize', [$this, 'checkTDMStatus']);
        
        // Inject JavaScript variables for feed management page
        $this->registerHook('js_vars', [$this, 'injectJSVars']);
        
        // Add JavaScript file - inject directly in init for feed pages
        $this->injectJavaScriptFile();
        
        // Add CSS styling - use inline CSS injection via js_vars for better compatibility
        $this->registerHook('js_vars', [$this, 'injectCSS']);
        
        $this->registerTranslates();
    }

    /**
     * Inject JavaScript file for feed management page
     */
    private function injectJavaScriptFile(): void
    {
        $action = Minz_Request::actionName();
        $controller = Minz_Request::controllerName();
        
        if ($controller === 'feed' && ($action === 'update' || $action === 'configure')) {
            try {
                $scriptUrl = $this->getFileUrl('tdmchecker.js', true);
                if (!empty($scriptUrl)) {
                    Minz_View::appendScript($scriptUrl);
                }
            } catch (Exception $e) {
                $this->logWarning('Failed to load TDMchecker JavaScript: %s', $e->getMessage());
            }
        }
    }

    /**
     * Inject JavaScript variables for the feed management page
     * 
     * @param array $vars
     * @return array
     */
    public function injectJSVars(array $vars): array
    {
        // Only inject on feed configuration pages
        $action = Minz_Request::actionName();
        $controller = Minz_Request::controllerName();
        
        if ($controller === 'feed' && ($action === 'update' || $action === 'configure')) {
            $feedId = Minz_Request::paramInt('id');
            if ($feedId > 0) {
                try {
                    $feedDao = FreshRSS_Factory::createFeedDao();
                    $feed = $feedDao->searchById($feedId);
                    if ($feed !== null) {
                        $status = $this->getTDMStatus($feedId);
                        $vars['tdmchecker'] = [
                            'feedId' => $feedId,
                            'websiteUrl' => $feed->website(),
                            'status' => $status !== null ? [
                                'opt_out' => $status['opt_out'],
                                'checked_at' => $status['checked_at'],
                            ] : null,
                            'checkUrl' => _url('extension', 'configure', 'e', urlencode($this->getName())),
                            'csrfToken' => FreshRSS_Auth::csrfToken(),
                        ];
                    }
                } catch (Exception $e) {
                    // Ignore errors
                }
            }
        }
        
        return $vars;
    }


    /**
     * Display TDM opt-out status on the feed management page (DEPRECATED - using JS now)
     * This should appear between Website URL and Feed URL fields
     * Handles different hook signatures (feed object or array)
     * 
     * @param FreshRSS_Feed|array $feedOrArray
     * @return string
     */
    private function displayTDMStatus($feedOrArray): string
    {
        // Handle different hook signatures
        $feed = null;
        if (is_array($feedOrArray)) {
            $feed = $feedOrArray['feed'] ?? null;
            if ($feed === null && isset($feedOrArray['id'])) {
                try {
                    $feedDao = FreshRSS_Factory::createFeedDao();
                    $feed = $feedDao->searchById($feedOrArray['id']);
                } catch (Exception $e) {
                    // Ignore
                }
            }
        } elseif ($feedOrArray instanceof FreshRSS_Feed) {
            $feed = $feedOrArray;
        }

        if ($feed === null) {
            // Try to get feed from context
            $feedId = FreshRSS_Context::currentFeed()?->id();
            if ($feedId === null) {
                return '';
            }
            try {
                $feedDao = FreshRSS_Factory::createFeedDao();
                $feed = $feedDao->searchById($feedId);
            } catch (Exception $e) {
                return '';
            }
        }

        if ($feed === null) {
            return '';
        }

        $feedId = $feed->id();
        $websiteUrl = $feed->website();
        $status = $this->getTDMStatus($feedId);
        
        // Determine display value
        if ($status === null) {
            $displayValue = 'null (not checked)';
            $statusClass = 'tdm-status-unknown';
        } else {
            $displayValue = $status['opt_out'] ? 'true' : 'false';
            $statusClass = $status['opt_out'] ? 'tdm-status-opted-out' : 'tdm-status-not-opted-out';
        }

        // Build HTML - this will appear between Website URL and Feed URL
        $html = '<div class="form-group">';
        $html .= '<label class="group-name">TDM opt out</label>';
        $html .= '<div class="group-controls">';
        $html .= '<span class="' . htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') . '" id="tdm-status-' . $feedId . '">';
        $html .= htmlspecialchars($displayValue, ENT_NOQUOTES, 'UTF-8');
        $html .= '</span>';
        
        // Add force check button if website URL exists
        if (!empty($websiteUrl)) {
            $html .= ' <button type="button" class="btn" onclick="tdmcheckerForceCheck(' . $feedId . ')" id="tdm-check-btn-' . $feedId . '" style="margin-left: 10px;">';
            $html .= 'Check TDM';
            $html .= '</button>';
            $html .= '<span id="tdm-check-status-' . $feedId . '" style="margin-left: 10px; display: none; font-size: 0.9em;"></span>';
        }
        
        $html .= '</div>';
        $html .= '</div>';

        // Add JavaScript for force check button
        $checkUrl = htmlspecialchars(_url('extension', 'configure', 'e', urlencode($this->getName())), ENT_QUOTES, 'UTF-8');
        $csrfToken = htmlspecialchars(FreshRSS_Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
        
        $html .= '<script>
        if (typeof tdmcheckerForceCheck === "undefined") {
            function tdmcheckerForceCheck(feedId) {
                    const btn = document.getElementById("tdm-check-btn-" + feedId);
                    const statusSpan = document.getElementById("tdm-status-" + feedId);
                    const checkStatus = document.getElementById("tdm-check-status-" + feedId);
                    
                    if (!btn || btn.disabled) return;
                    
                    btn.disabled = true;
                    btn.textContent = "Checking...";
                    if (checkStatus) {
                        checkStatus.style.display = "inline";
                        checkStatus.textContent = "";
                    }
                    
                    const formData = new FormData();
                    formData.append("_csrf", "' . $csrfToken . '");
                    formData.append("check_feed_id", feedId);
                    formData.append("ajax", "1");
                    
                    fetch("' . $checkUrl . '", {
                        method: "POST",
                        body: formData
                    })
                    .then(response => response.text())
                    .then(text => {
                        try {
                            const data = JSON.parse(text);
                            if (data.status === "success") {
                                if (statusSpan) {
                                    statusSpan.textContent = data.opt_out ? "true" : "false";
                                    statusSpan.className = data.opt_out ? "tdm-status-opted-out" : "tdm-status-not-opted-out";
                                }
                                if (checkStatus) {
                                    checkStatus.textContent = "✓ Checked";
                                    checkStatus.style.color = "#00a32a";
                                }
                            } else {
                                if (checkStatus) {
                                    checkStatus.textContent = "✗ Error: " + (data.message || "Unknown error");
                                    checkStatus.style.color = "#d63638";
                                }
                            }
                        } catch (e) {
                            // If not JSON, might be HTML redirect - reload page
                            if (text.includes("gen.action.done") || text.includes("success")) {
                                location.reload();
                            } else {
                                if (checkStatus) {
                                    checkStatus.textContent = "✗ Error: Invalid response";
                                    checkStatus.style.color = "#d63638";
                                }
                            }
                        }
                    })
                    .catch(error => {
                        if (checkStatus) {
                            checkStatus.textContent = "✗ Error: " + error.message;
                            checkStatus.style.color = "#d63638";
                        }
                    })
                    .finally(() => {
                        if (btn) {
                            btn.disabled = false;
                            btn.textContent = "Check TDM";
                        }
                        if (checkStatus) {
                            setTimeout(() => {
                                checkStatus.style.display = "none";
                            }, 3000);
                        }
                    });
            }
        }
        </script>';

        return $html;
    }


    /**
     * Check TDM status for a feed when it's being refreshed
     * Only checks if cache has expired (24 hours)
     * 
     * @param FreshRSS_Feed $feed
     * @return FreshRSS_Feed|null
     */
    public function checkTDMStatus(FreshRSS_Feed $feed): ?FreshRSS_Feed
    {
        $feedId = $feed->id();
        $websiteUrl = $feed->website();
        
        // Skip if no website URL
        if (empty($websiteUrl)) {
            $this->logDebug('Feed %d has no website URL, skipping TDM check', $feedId);
            return $feed;
        }

        // Check if we need to refresh the status
        $currentStatus = $this->getTDMStatus($feedId);
        $now = time();
        
        if ($currentStatus !== null) {
            $age = $now - $currentStatus['checked_at'];
            if ($age < self::CACHE_DURATION_SECONDS) {
                // Still within cache period, no need to check
                $this->logDebug(
                    'Feed %d TDM status cached (age: %d seconds), skipping check',
                    $feedId,
                    $age
                );
                return $feed;
            }
        }

        // Perform async check (don't block feed refresh)
        // We'll check in the background to avoid blocking
        $this->performTDMCheckAsync($feedId, $websiteUrl);

        return $feed;
    }

    /**
     * Perform TDM check asynchronously (non-blocking)
     * Uses register_shutdown_function to perform check after response is sent
     * 
     * @param int $feedId
     * @param string $websiteUrl
     */
    private function performTDMCheckAsync(int $feedId, string $websiteUrl): void
    {
        // Use register_shutdown_function to perform check after response is sent
        // This prevents blocking the feed refresh
        // Note: We capture $this in the closure, which should work fine
        register_shutdown_function(function () use ($feedId, $websiteUrl) {
            try {
                $this->performTDMCheck($feedId, $websiteUrl);
            } catch (Exception $e) {
                // Log but don't throw - this is in shutdown handler
                error_log(sprintf(
                    'TDMchecker: Error in async TDM check for feed %d: %s',
                    $feedId,
                    $e->getMessage()
                ));
            }
        });
    }

    /**
     * Perform the actual TDM check via Cloudflare worker
     * 
     * @param int $feedId
     * @param string $websiteUrl
     * @return bool|null Returns true if opted out, false if not, null on error
     */
    private function performTDMCheck(int $feedId, string $websiteUrl): ?bool
    {
        $apiUrl = self::WORKER_URL . '?domain=' . urlencode($websiteUrl);
        
        $this->logDebug('Checking TDM status for feed %d, website: %s', $feedId, $websiteUrl);

        $ch = curl_init($apiUrl);
        if ($ch === false) {
            $this->logWarning('Failed to initialize cURL for feed %d', $feedId);
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::CHECK_TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_USERAGENT => 'FreshRSS-TDMchecker/1.0',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || !empty($error)) {
            $this->logWarning(
                'TDM check failed for feed %d: %s',
                $feedId,
                $error ?: 'Unknown cURL error'
            );
            return null;
        }

        if ($httpCode !== 200) {
            $this->logWarning(
                'TDM check returned HTTP %d for feed %d',
                $httpCode,
                $feedId
            );
            return null;
        }

        $data = json_decode($response, true);
        if ($data === null || !isset($data['summary']['tdm_opt_out_detected'])) {
            $this->logWarning(
                'Invalid TDM check response for feed %d: %s',
                $feedId,
                substr($response, 0, 200)
            );
            return null;
        }

        $optOut = (bool) $data['summary']['tdm_opt_out_detected'];
        
        // Save the result
        $this->saveTDMStatus($feedId, $optOut);
        
        $this->logDebug(
            'TDM check completed for feed %d: opt_out=%s',
            $feedId,
            $optOut ? 'true' : 'false'
        );

        return $optOut;
    }

    /**
     * Get TDM status for a feed
     * 
     * @param int $feedId
     * @return array{opt_out: bool, checked_at: int}|null
     */
    private function getTDMStatus(int $feedId): ?array
    {
        $conf = FreshRSS_Context::userConf();
        $allStatuses = $conf->attributeArray(self::TDM_STATUS_KEY) ?? [];
        
        if (!isset($allStatuses[$feedId])) {
            return null;
        }

        $status = $allStatuses[$feedId];
        
        // Validate structure
        if (!is_array($status) || !isset($status['opt_out']) || !isset($status['checked_at'])) {
            return null;
        }

        return [
            'opt_out' => (bool) $status['opt_out'],
            'checked_at' => (int) $status['checked_at'],
        ];
    }

    /**
     * Save TDM status for a feed
     * 
     * @param int $feedId
     * @param bool $optOut
     */
    private function saveTDMStatus(int $feedId, bool $optOut): void
    {
        $conf = FreshRSS_Context::userConf();
        $allStatuses = $conf->attributeArray(self::TDM_STATUS_KEY) ?? [];
        
        $allStatuses[$feedId] = [
            'opt_out' => $optOut,
            'checked_at' => time(),
        ];
        
        $conf->_attribute(self::TDM_STATUS_KEY, $allStatuses);
        $conf->save();
    }

    /**
     * Manually trigger TDM check for a feed (for use in configure page)
     * 
     * @param int $feedId
     * @return bool|null
     */
    public function checkTDMStatusManually(int $feedId): ?bool
    {
        try {
            $feedDao = FreshRSS_Factory::createFeedDao();
            $feed = $feedDao->searchById($feedId);
            
            if ($feed === null) {
                $this->logWarning('Feed %d not found for manual TDM check', $feedId);
                return null;
            }

            $websiteUrl = $feed->website();
            if (empty($websiteUrl)) {
                $this->logWarning('Feed %d has no website URL for manual TDM check', $feedId);
                return null;
            }

            return $this->performTDMCheck($feedId, $websiteUrl);
        } catch (Exception $e) {
            $this->logWarning('Error in manual TDM check for feed %d: %s', $feedId, $e->getMessage());
            return null;
        }
    }

    /**
     * Get TDM status for display (public method for templates)
     * 
     * @param int $feedId
     * @return array{opt_out: bool|null, checked_at: int|null, display: string}
     */
    public function getTDMStatusForDisplay(int $feedId): array
    {
        $status = $this->getTDMStatus($feedId);
        
        if ($status === null) {
            return [
                'opt_out' => null,
                'checked_at' => null,
                'display' => 'null (not checked)',
            ];
        }

        return [
            'opt_out' => $status['opt_out'],
            'checked_at' => $status['checked_at'],
            'display' => $status['opt_out'] ? 'true' : 'false',
        ];
    }

    /**
     * Inject CSS styling via js_vars (more reliable than css hook)
     * 
     * @param array $vars
     * @return array
     */
    public function injectCSS(array $vars): array
    {
        // Inject CSS inline via JavaScript for better compatibility
        $action = Minz_Request::actionName();
        $controller = Minz_Request::controllerName();
        
        if ($controller === 'feed' && ($action === 'update' || $action === 'configure')) {
            if (!isset($vars['tdmchecker_css_injected'])) {
                $css = '
.tdm-status-opted-out { color: #d63638; font-weight: bold; }
.tdm-status-not-opted-out { color: #00a32a; font-weight: bold; }
.tdm-status-unknown { color: #646970; font-style: italic; }
[id^="tdm-check-status-"] { font-size: 0.9em; }
';
                $vars['tdmchecker_css'] = $css;
                $vars['tdmchecker_css_injected'] = true;
            }
        }
        
        return $vars;
    }

    /**
     * Handle configuration page actions
     */
    public function handleConfigureAction(): void
    {
        $this->registerTranslates();

        if (!Minz_Request::isPost()) {
            return;
        }

        // Handle manual check request (from configure page or feed UI)
        $feedId = Minz_Request::paramInt('check_feed_id');
        if ($feedId > 0) {
            $result = $this->checkTDMStatusManually($feedId);
            
            // If AJAX request, return JSON
            if (Minz_Request::param('ajax') === '1' || 
                Minz_Request::header('X-Requested-With') === 'XMLHttpRequest') {
                header('Content-Type: application/json');
                if ($result !== null) {
                    $status = $this->getTDMStatus($feedId);
                    echo json_encode([
                        'status' => 'success',
                        'opt_out' => $status['opt_out'] ?? false,
                        'checked_at' => $status['checked_at'] ?? time(),
                    ]);
                } else {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Failed to check TDM status. Make sure the feed has a website URL.',
                    ]);
                }
                exit;
            }
            
            // Regular form submission
            if ($result !== null) {
                Minz_Request::good(_t('gen.action.done'));
            } else {
                Minz_Request::bad(_t('gen.action.fail'));
            }
        }
    }

    private function logDebug(string $message, mixed ...$arguments): void
    {
        Minz_Log::debug($this->formatLogMessage($message, $arguments));
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

        return 'TDMchecker: ' . $message;
    }
}
