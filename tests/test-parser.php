<?php
declare(strict_types=1);

if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url): array|false {
        return parse_url($url);
    }
}
if (!function_exists('esc_url_raw')) {
    function esc_url_raw(string $url, array $protocols = ['http', 'https']): string {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        return $scheme && !in_array(strtolower((string) $scheme), $protocols, true) ? '' : $url;
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $string, int $start, ?int $length = null, ?string $encoding = null): string {
        return substr($string, $start, $length);
    }
}

require_once __DIR__ . '/../includes/class-sla-parser.php';

function sla_assert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$home = 'https://senoobar.ir/';
$links = SLA_Parser::extract_links(
    '<p><a href="/beds?size=160#details">تخت خواب</a><a href="https://example.com/x">بیرونی</a><a href="">بدون مقصد</a><a href="/empty"><img src="x"></a><script>not a link</script></p>',
    $home
);

sla_assert(count($links) === 4, 'extract only anchor elements, including empty destination');
sla_assert($links[0]['url'] === 'https://senoobar.ir/beds?size=160#details', 'resolve relative URL and preserve query/fragment');
sla_assert($links[0]['type'] === 'internal', 'same-host link is internal');
sla_assert($links[0]['anchor'] === 'تخت خواب', 'extract visible anchor text');
sla_assert($links[1]['type'] === 'external', 'different host is external');
sla_assert($links[2]['url'] === '', 'empty href stays empty');
sla_assert($links[3]['anchor'] === '', 'image-only link has empty text anchor');

$protocolRelative = SLA_Parser::extract_links('<a href="//senoobar.ir/about">درباره</a>', $home);
sla_assert($protocolRelative[0]['type'] === 'internal', 'protocol-relative same-host link is internal');

$duplicates = SLA_Parser::extract_links('<a href="/same">یکی</a><a href="/same">دو</a>', $home);
sla_assert(count($duplicates) === 2, 'duplicate occurrences are preserved for reporting');

$externalSubdomain = SLA_Parser::extract_links('<a href="https://shop.senoobar.ir/">فروشگاه</a>', $home);
sla_assert($externalSubdomain[0]['type'] === 'external', 'a different hostname is not assumed internal');

$relative = SLA_Parser::extract_links('<a href="related">مرتبط</a>', $home, 'https://senoobar.ir/blog/article/');
sla_assert($relative[0]['url'] === 'https://senoobar.ir/blog/article/related', 'resolve plain relative URL against source permalink');

$mail = SLA_Parser::extract_links('<a href="mailto:hello@example.com">ایمیل</a>', $home);
sla_assert($mail[0]['url'] === 'mailto:hello@example.com', 'preserve non-web contact links in the inventory');
sla_assert($mail[0]['type'] === 'external', 'contact links are not classified as internal URLs');
sla_assert($links[2]['type'] === 'internal', 'empty href is treated as a same-document link');

echo "Parser tests passed (13 assertions).\n";
