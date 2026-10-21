<?php
declare(strict_types=1);

/**
 * Parses saved post content. This class deliberately performs no network access.
 */
final class SLA_Parser {
    /**
     * @return array<int, array{url:string,anchor:string,type:string}>
     */
    public static function extract_links(string $html, string $home_url, ?string $source_url = null): array {
        if (trim($html) === '') {
            return [];
        }

        $old_errors = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($old_errors);

        if (!$loaded) {
            return [];
        }

        $home = wp_parse_url($home_url);
        $home_host = isset($home['host']) ? strtolower(rtrim((string) $home['host'], '.')) : '';
        $home_scheme = isset($home['scheme']) ? strtolower((string) $home['scheme']) : 'https';
        $result = [];

        foreach ($document->getElementsByTagName('a') as $node) {
            if (!$node instanceof DOMElement || !$node->hasAttribute('href')) {
                continue;
            }

            $raw_url = trim(html_entity_decode($node->getAttribute('href'), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $url = self::resolve_url($raw_url, $home_url, $home_scheme, $source_url);
            $target = wp_parse_url($url);
            $target_host = isset($target['host']) ? strtolower(rtrim((string) $target['host'], '.')) : '';
            $type = ($raw_url === '' || ($target_host !== '' && $home_host !== '' && hash_equals($home_host, $target_host))) ? 'internal' : 'external';

            $anchor = trim(preg_replace('/\\s+/u', ' ', (string) $node->textContent) ?? '');
            $result[] = [
                'url' => $url,
                'anchor' => function_exists('mb_substr') ? mb_substr($anchor, 0, 2000, 'UTF-8') : substr($anchor, 0, 2000),
                'type' => $type,
            ];
        }

        unset($errors);
        return $result;
    }

    private static function resolve_url(string $url, string $home_url, string $home_scheme, ?string $source_url = null): string {
        if ($url === '') {
            return '';
        }

        if (preg_match('~^(?:https?:)?//~i', $url)) {
            if (str_starts_with($url, '//')) {
                return $home_scheme . ':' . $url;
            }
            return esc_url_raw($url, ['http', 'https']);
        }

        // Preserve useful contact protocols for reporting, but strip executable or data schemes.
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url)) {
            return esc_url_raw($url, ['http', 'https', 'mailto', 'tel', 'sms', 'ftp']);
        }

        $home = wp_parse_url($home_url);
        $base_url = $source_url ?: $home_url;
        $base_parts = wp_parse_url($base_url);
        $scheme = isset($base_parts['scheme']) ? (string) $base_parts['scheme'] : ($home_scheme ?: 'https');
        $host = isset($base_parts['host']) ? (string) $base_parts['host'] : (isset($home['host']) ? (string) $home['host'] : '');
        $port = isset($base_parts['port']) ? ':' . (int) $base_parts['port'] : (isset($home['port']) ? ':' . (int) $home['port'] : '');
        $base = $scheme . '://' . $host . $port;
        $base_path = isset($base_parts['path']) ? (string) $base_parts['path'] : '/';

        if (str_starts_with($url, '#') || str_starts_with($url, '?')) {
            return $base . ($base_path ?: '/') . $url;
        }

        if (str_starts_with($url, '/')) {
            $path = $url;
        } else {
            $directory = substr($base_path, 0, (int) strrpos($base_path, '/') + 1);
            $path = ($directory ?: '/') . $url;
        }

        $parts = explode('/', $path);
        $normalized = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                if (!$normalized && $part === '') {
                    $normalized[] = '';
                }
                continue;
            }
            if ($part === '..') {
                if (count($normalized) > 1) {
                    array_pop($normalized);
                }
                continue;
            }
            $normalized[] = $part;
        }
        $resolved_path = implode('/', $normalized);
        if (!str_starts_with($resolved_path, '/')) {
            $resolved_path = '/' . $resolved_path;
        }
        return $base . $resolved_path;
    }
}
