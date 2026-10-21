<?php
declare(strict_types=1);

/**
 * Parses saved post content. This class deliberately performs no network access.
 */
final class SLA_Parser {
    /**
     * @return array<int, array{url:string,anchor:string,type:string}>
     */
    public static function extract_links(string $html, string $home_url): array {
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
            $url = self::resolve_url($raw_url, $home_url, $home_scheme);
            $target = wp_parse_url($url);
            $target_host = isset($target['host']) ? strtolower(rtrim((string) $target['host'], '.')) : '';
            $type = ($target_host !== '' && $home_host !== '' && hash_equals($home_host, $target_host)) ? 'internal' : 'external';

            $anchor = trim(preg_replace('/\\s+/u', ' ', (string) $node->textContent) ?? '');
            $result[] = [
                'url' => $url,
                'anchor' => mb_substr($anchor, 0, 2000, 'UTF-8'),
                'type' => $type,
            ];
        }

        unset($errors);
        return $result;
    }

    private static function resolve_url(string $url, string $home_url, string $home_scheme): string {
        if ($url === '') {
            return '';
        }

        if (preg_match('~^(?:https?:)?//~i', $url)) {
            if (str_starts_with($url, '//')) {
                return $home_scheme . ':' . $url;
            }
            return esc_url_raw($url, ['http', 'https']);
        }

        // Do not classify javascript:, data:, mailto:, tel:, or other schemes as site links.
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url)) {
            return esc_url_raw($url, ['http', 'https']);
        }

        $home = wp_parse_url($home_url);
        $scheme = $home_scheme ?: 'https';
        $host = isset($home['host']) ? (string) $home['host'] : '';
        $port = isset($home['port']) ? ':' . (int) $home['port'] : '';
        $base = $scheme . '://' . $host . $port;
        $path = isset($home['path']) ? rtrim((string) $home['path'], '/') : '';

        if (str_starts_with($url, '#') || str_starts_with($url, '?')) {
            $current_path = isset($home['path']) ? (string) $home['path'] : '/';
            return $base . ($current_path ?: '/') . $url;
        }

        if (str_starts_with($url, '/')) {
            return $base . $url;
        }

        return $base . ($path !== '' ? $path : '') . '/' . ltrim($url, '/');
    }
}
