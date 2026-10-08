<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebsiteEmailExtractorService
{
    /**
     * Common contact and about path extensions to check shallowly
     */
    protected array $candidatePaths = [
        '/contact',
        '/contact-us',
        '/contact_us',
        '/about',
        '/about-us',
        '/about_us',
        '/get-in-touch',
    ];

    /**
     * File extensions mistakenly parsed as emails (e.g. image@2x.png)
     */
    protected array $blacklistedExtensions = [
        'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'bmp', 'ico',
        'css', 'js', 'json', 'xml', 'woff', 'woff2', 'ttf', 'eot',
        'mp4', 'mp3', 'pdf', 'zip', 'gz', 'tar',
    ];

    /**
     * Placeholder domains or garbage terms to ignore
     */
    protected array $blacklistedDomains = [
        'example.com', 'example.org', 'example.net',
        'domain.com', 'yourdomain.com', 'email.com',
        'wixpress.com', 'sentry.io', 'schema.org',
        'w3.org', 'googleapis.com', 'google.com',
        'facebook.com', 'twitter.com', 'instagram.com',
        'github.com', 'gravatar.com', 'cloudflare.com',
        'test.com', 'sample.com', 'localhost',
    ];

    /**
     * User agent mimicking modern desktop browser to prevent false 403s
     */
    protected string $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    /**
     * Normalize website URL to standard http/https format
     */
    public function normalizeUrl(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        $url = trim($url);
        if (!preg_match('~^(?:f|ht)tps?://~i', $url)) {
            $url = 'https://' . $url;
        }

        $parsed = parse_url($url);
        if (empty($parsed['host'])) {
            return null;
        }

        $scheme = $parsed['scheme'] ?? 'https';
        $host = strtolower($parsed['host']);
        // Strip www prefix for domain normalization
        $normalizedHost = preg_replace('/^www\./i', '', $host);

        $path = $parsed['path'] ?? '/';

        return "{$scheme}://{$host}" . ($path === '/' ? '' : rtrim($path, '/'));
    }

    /**
     * Extract root domain name from URL for duplicate checking
     */
    public function extractDomain(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';
        if (empty($host)) {
            return null;
        }
        $host = strtolower($host);
        return preg_replace('/^www\./i', '', $host);
    }

    /**
     * Extract emails from a website with mode and timeout options
     *
     * @param string $websiteUrl
     * @param array $options
     * @return array ['success' => bool, 'primary_email' => ?string, 'email_source' => ?string, 'emails' => array, 'error' => ?string]
     */
    public function extractEmails(string $websiteUrl, array $options = []): array
    {
        $normalizedUrl = $this->normalizeUrl($websiteUrl);
        if (!$normalizedUrl) {
            return [
                'success' => false,
                'primary_email' => null,
                'email_source' => null,
                'emails' => [],
                'error' => 'Invalid website URL format',
            ];
        }

        $discoveryMode = $options['email_discovery_mode'] ?? 'website_contact';
        $maxSubpages = 2;
        if ($discoveryMode === 'basic') {
            $maxSubpages = 0; // Homepage only
        } elseif ($discoveryMode === 'deep') {
            $maxSubpages = 4; // Deeper shallow crawl
        }

        $timeout = isset($options['timeout']) ? (int) $options['timeout'] : 10;

        $parsedBase = parse_url($normalizedUrl);
        $baseHost = strtolower($parsedBase['host'] ?? '');
        $baseOrigin = ($parsedBase['scheme'] ?? 'https') . '://' . $baseHost;

        $foundEmails = []; // [email_normalized => ['email' => original, 'source' => human_source, 'source_page' => path]]
        $visitedUrls = [];
        $failedPages = 0;

        // 1. Fetch homepage
        $homeResult = $this->fetchPage($normalizedUrl, $timeout);
        $visitedUrls[] = $normalizedUrl;

        if (!$homeResult['success']) {
            Log::channel('scraper')->warning("Failed to fetch homepage: {$normalizedUrl}. Error: {$homeResult['error']}");
            return [
                'success' => false,
                'primary_email' => null,
                'email_source' => null,
                'emails' => [],
                'error' => $homeResult['error'] ?? 'Could not reach website',
            ];
        }

        $this->parseEmailsFromHtml($homeResult['html'], 'Homepage', '/', $foundEmails);

        // 2. Discover shallow contact/about links if mode allows
        if ($maxSubpages > 0) {
            $candidateUrls = $this->findContactLinks($homeResult['html'] ?? '', $baseOrigin, $baseHost);

            $checkedSubpages = 0;
            foreach ($candidateUrls as $subUrl) {
                if ($checkedSubpages >= $maxSubpages) {
                    break;
                }
                if (in_array($subUrl, $visitedUrls)) {
                    continue;
                }

                $visitedUrls[] = $subUrl;
                $checkedSubpages++;

                $subResult = $this->fetchPage($subUrl, $timeout);
                if ($subResult['success']) {
                    $path = parse_url($subUrl, PHP_URL_PATH) ?? $subUrl;
                    $label = 'Contact Page';
                    if (str_contains(strtolower($path), 'about')) {
                        $label = 'About Page';
                    }
                    $this->parseEmailsFromHtml($subResult['html'], $label, $path, $foundEmails);
                }
            }
        }

        $emailsList = array_values($foundEmails);

        if (empty($emailsList)) {
            return [
                'success' => true,
                'primary_email' => null,
                'email_source' => null,
                'emails' => [],
                'error' => null,
            ];
        }

        // Select the most relevant primary email (prioritize info@, contact@, support@, hello@, sales@)
        $primary = $this->pickPrimaryEmail($emailsList);

        return [
            'success' => true,
            'primary_email' => $primary['email_normalized'],
            'email_source' => $primary['source'] ?? 'Website',
            'emails' => $emailsList,
            'error' => null,
        ];
    }

    /**
     * Safely fetch a web page with timeouts and custom user agent
     */
    protected function fetchPage(string $url, int $timeout = 10): array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => $this->userAgent,
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
            ])
            ->timeout($timeout)
            ->connectTimeout(min(6, $timeout))
            ->withOptions([
                'allow_redirects' => ['max' => 5, 'strict' => true],
                'verify' => false,
            ])
            ->get($url);

            if ($response->successful()) {
                $contentType = $response->header('Content-Type') ?? '';
                if (!empty($contentType) && !str_contains(strtolower($contentType), 'text/html') && !str_contains(strtolower($contentType), 'text/plain')) {
                    return ['success' => false, 'html' => '', 'error' => 'Not an HTML page'];
                }
                return ['success' => true, 'html' => $response->body(), 'error' => null];
            }

            return [
                'success' => false,
                'html' => '',
                'error' => "HTTP error {$response->status()}",
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'html' => '',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Parse and validate email addresses from HTML body, footer, and mailto links
     */
    protected function parseEmailsFromHtml(string $html, string $sourceLabel, string $sourcePage, array &$foundEmails): void
    {
        if (empty($html)) {
            return;
        }

        // Decode HTML entities (e.g., &#64; or &commat;) and URL encoded values
        $decodedHtml = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $rawUrlDecoded = urldecode($decodedHtml);

        // 1. Mailto: links
        if (preg_match_all('/mailto:([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/i', $decodedHtml, $matches)) {
            foreach ($matches[1] as $email) {
                $this->addValidEmail($email, 'Mailto Link', $sourcePage, $foundEmails);
            }
        }
        if (preg_match_all('/mailto:([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/i', $rawUrlDecoded, $matches)) {
            foreach ($matches[1] as $email) {
                $this->addValidEmail($email, 'Mailto Link', $sourcePage, $foundEmails);
            }
        }

        // 2. Footer-specific detection
        if (preg_match('/<footer[^>]*>(.*?)<\/footer>/is', $decodedHtml, $footerMatch)) {
            $footerPattern = '/\b[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}\b/';
            if (preg_match_all($footerPattern, $footerMatch[1], $footerEmails)) {
                foreach ($footerEmails[0] as $email) {
                    $this->addValidEmail($email, 'Footer', $sourcePage, $foundEmails);
                }
            }
        }

        // 3. Visible plaintext email regex
        $pattern = '/\b[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}\b/';
        if (preg_match_all($pattern, $decodedHtml, $matches)) {
            foreach ($matches[0] as $email) {
                $this->addValidEmail($email, $sourceLabel, $sourcePage, $foundEmails);
            }
        }
    }

    /**
     * Validate and add an email to the list
     */
    protected function addValidEmail(string $email, string $sourceLabel, string $sourcePage, array &$foundEmails): void
    {
        $email = trim(urldecode($email));
        $email = ltrim($email, " \t\n\r\0\x0B/%");
        $normalized = strtolower(trim($email));

        // Basic filter check
        if (!filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        // Check file extensions (e.g. user@2x.png)
        $parts = explode('.', $normalized);
        $ext = end($parts);
        if (in_array($ext, $this->blacklistedExtensions)) {
            return;
        }

        // Check blacklisted domains
        $domain = substr(strrchr($normalized, "@"), 1);
        if (in_array($domain, $this->blacklistedDomains)) {
            return;
        }

        // Reject tracking / placeholder prefixes
        if (preg_match('/^(no-reply|noreply|donotreply|mailer-daemon|postmaster|bounce|unsubscribe)@/i', $normalized)) {
            return;
        }

        // Reject obvious dummy emails
        if (in_array($normalized, ['info@example.com', 'user@domain.com', 'test@test.com', 'admin@domain.com', 'example@mail.com', 'user@mail.com'])) {
            return;
        }

        if (!isset($foundEmails[$normalized])) {
            $foundEmails[$normalized] = [
                'email' => $email,
                'email_normalized' => $normalized,
                'source' => $sourceLabel,
                'source_page' => $sourcePage,
            ];
            Log::channel('scraper')->debug("Extracted valid email: {$normalized} from {$sourceLabel} ({$sourcePage})");
        }
    }

    /**
     * Find relevant contact/about subpage links on the same domain
     */
    protected function findContactLinks(string $html, string $baseOrigin, string $baseHost): array
    {
        $links = [];
        if (empty($html)) {
            foreach ($this->candidatePaths as $path) {
                $links[] = $baseOrigin . $path;
            }
            return $links;
        }

        // Extract hrefs containing contact or about
        if (preg_match_all('/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $html, $matches)) {
            foreach ($matches[1] as $href) {
                $href = trim($href);
                if (empty($href) || str_starts_with($href, '#') || str_starts_with($href, 'javascript:') || str_starts_with($href, 'tel:') || str_starts_with($href, 'mailto:')) {
                    continue;
                }

                $lowerHref = strtolower($href);
                if (str_contains($lowerHref, 'contact') || str_contains($lowerHref, 'about') || str_contains($lowerHref, 'reach')) {
                    if (str_starts_with($href, '/')) {
                        $fullUrl = $baseOrigin . $href;
                    } elseif (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
                        $host = parse_url($href, PHP_URL_HOST);
                        if ($host && strtolower($host) === $baseHost) {
                            $fullUrl = $href;
                        } else {
                            continue;
                        }
                    } else {
                        $fullUrl = $baseOrigin . '/' . ltrim($href, '/');
                    }

                    if (!in_array($fullUrl, $links)) {
                        $links[] = $fullUrl;
                    }
                }
            }
        }

        // Fall back to candidate paths if none discovered
        if (empty($links)) {
            foreach ($this->candidatePaths as $path) {
                $links[] = $baseOrigin . $path;
            }
        }

        return array_slice($links, 0, 4);
    }

    /**
     * Pick primary email using business heuristics
     */
    protected function pickPrimaryEmail(array $emails): array
    {
        $priorityPrefixes = ['info@', 'contact@', 'hello@', 'support@', 'sales@', 'office@', 'admin@'];

        foreach ($priorityPrefixes as $prefix) {
            foreach ($emails as $item) {
                if (str_starts_with($item['email_normalized'], $prefix)) {
                    return $item;
                }
            }
        }

        return $emails[0];
    }
}
