<?php
declare(strict_types=1);

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

echo "Parser tests passed (9 assertions).\n";
