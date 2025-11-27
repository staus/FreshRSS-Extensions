<?php

class AutoTaggerExtension extends Minz_Extension
{
    private const CONFIG_KEY_PREFIX = 'autotagger_patterns_';

    /**
     * Label categories and their configuration keys
     * @var array<string,string>
     */
    private const LABEL_CATEGORIES = [
        'dealTerms' => 'Deal Terms',
        'shoppingEvents' => 'Shopping Events',
        'urgencyTerms' => 'Urgency Terms',
        'promotionalCodes' => 'Promotional Codes',
        'callToAction' => 'Call to Action',
        'affiliateMarkers' => 'Affiliate Markers',
        'discountPatterns' => 'Discount Patterns',
    ];

    /**
     * @var array<string,array<string>>
     */
    private array $patterns = [];

    public function init()
    {
        parent::init();

        $this->registerHook('entry_before_insert', [$this, 'tagEntry']);
        $this->registerTranslates();
        $this->loadPatterns();
    }

    /**
     * Load regex patterns from configuration
     */
    private function loadPatterns(): void
    {
        $conf = FreshRSS_Context::userConf();
        $this->patterns = [];

        foreach (array_keys(self::LABEL_CATEGORIES) as $category) {
            $key = self::CONFIG_KEY_PREFIX . $category;
            $raw = $conf->attributeString($key, '');
            
            if ($raw === '') {
                $this->patterns[$category] = [];
                continue;
            }

            // Split by newlines and filter out empty lines
            $lines = preg_split('/\r?\n/', $raw);
            $patterns = [];
            
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $patterns[] = $line;
                }
            }
            
            $this->patterns[$category] = $patterns;
        }
    }

    /**
     * Called when the configuration page is loaded or saved.
     */
    public function handleConfigureAction(): void
    {
        $this->registerTranslates();
        $this->loadPatterns();

        if (!Minz_Request::isPost()) {
            return;
        }

        $conf = FreshRSS_Context::userConf();
        $hasChanges = false;

        foreach (array_keys(self::LABEL_CATEGORIES) as $category) {
            $key = self::CONFIG_KEY_PREFIX . $category;
            $paramName = 'autotagger_' . $category;
            $value = trim((string) Minz_Request::param($paramName, ''));
            
            $conf->_attribute($key, $value);
            $hasChanges = true;
        }

        if ($hasChanges) {
            $conf->save();
            $this->loadPatterns();
            Minz_Request::good(_t('gen.action.done'));
        }
    }

    /**
     * Hook called before an entry is inserted into the database.
     * Apply tags/labels based on regex patterns.
     * 
     * @param FreshRSS_Entry $entry
     * @return FreshRSS_Entry
     */
    public function tagEntry(FreshRSS_Entry $entry): FreshRSS_Entry
    {
        if (empty($this->patterns)) {
            return $entry;
        }

        $title = $entry->title() ?? '';
        $content = $entry->content() ?? '';
        
        // Combine title and content for matching
        $textToMatch = $title . ' ' . strip_tags($content);
        
        // Normalize whitespace
        $textToMatch = preg_replace('/\s+/', ' ', $textToMatch);
        
        $matchedLabels = [];
        
        // Try each category's patterns
        foreach ($this->patterns as $category => $patterns) {
            if (empty($patterns)) {
                continue;
            }

            foreach ($patterns as $pattern) {
                if ($pattern === '') {
                    continue;
                }

                // Try to match the pattern (case-insensitive)
                // Use delimiters and add 'i' flag for case-insensitive matching
                try {
                    $regex = '/' . $pattern . '/i';
                    if (preg_match($regex, $textToMatch)) {
                        $labelName = self::LABEL_CATEGORIES[$category];
                        $matchedLabels[] = $labelName;
                        $this->logDebug(
                            'Matched pattern "%s" for category "%s" in entry "%s"',
                            $pattern,
                            $category,
                            substr($title, 0, 50)
                        );
                        // Only need one match per category
                        break;
                    }
                } catch (Exception $e) {
                    $this->logWarning(
                        'Invalid regex pattern "%s" in category "%s": %s',
                        $pattern,
                        $category,
                        $e->getMessage()
                    );
                }
            }
        }

        // Add matched labels as tags to the entry
        // In FreshRSS, tags can function as labels/categories
        $existingTags = $entry->tags() ?? [];
        
        if (!empty($matchedLabels)) {
            // Add matched labels
            $allTags = array_unique(array_merge($existingTags, $matchedLabels));
            $entry->_tags($allTags);
        } else {
            // If no labels matched, add "Checked by AutoTagger" to indicate the entry was processed
            $checkedTag = 'Checked by AutoTagger';
            if (!in_array($checkedTag, $existingTags, true)) {
                $allTags = array_unique(array_merge($existingTags, [$checkedTag]));
                $entry->_tags($allTags);
                $this->logDebug(
                    'No patterns matched for entry "%s", added "Checked by AutoTagger" tag',
                    substr($title, 0, 50)
                );
            }
        }

        return $entry;
    }

    /**
     * Get patterns for a specific category (for use in configure.phtml)
     * 
     * @param string $category
     * @return string
     */
    public function getPatternsForCategory(string $category): string
    {
        $conf = FreshRSS_Context::userConf();
        $key = self::CONFIG_KEY_PREFIX . $category;
        return $conf->attributeString($key, '');
    }

    /**
     * Get all label categories
     * 
     * @return array<string,string>
     */
    public function getLabelCategories(): array
    {
        return self::LABEL_CATEGORIES;
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

        return 'AutoTagger: ' . $message;
    }
}
