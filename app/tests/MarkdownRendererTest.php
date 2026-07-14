<?php
declare(strict_types=1);

use Montelibero\BSN\Controllers\DocumentsController;
use Montelibero\BSN\MarkdownRenderer;

error_reporting(E_ALL & ~E_DEPRECATED);

require dirname(__DIR__) . '/vendor/autoload.php';

function assertContains(string $needle, string $haystack, string $message): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message . ' Missing: ' . $needle);
    }
}

function assertNotContains(string $needle, string $haystack, string $message): void
{
    if (str_contains(strtolower($haystack), strtolower($needle))) {
        throw new RuntimeException($message . ' Found: ' . $needle);
    }
}

$Renderer = new MarkdownRenderer();

$stored_payload = "# Heading\n\n**bold**\n\n<script>alert(1)</script><img src=x onerror=alert(1)>\n\n[unsafe](javascript:alert(1))\n\n- [**nested unsafe**](javascript:alert(2))\n- <img src=x onerror=alert(2)>";
if (!DocumentsController::looksLikeMarkdown($stored_payload)) {
    throw new RuntimeException('The regression payload must follow the document and token Markdown render path.');
}

$unsafe_html = $Renderer->render($stored_payload);
assertNotContains('<script', $unsafe_html, 'Raw script tags must be escaped.');
assertNotContains('<img', $unsafe_html, 'Raw image tags must be escaped.');
assertContains('&lt;script&gt;', $unsafe_html, 'Escaped source text should remain visible.');
assertContains('&lt;img src=x onerror=alert(1)&gt;', $unsafe_html, 'Unsafe HTML should remain visible only as escaped text.');
assertNotContains('href="javascript:', $unsafe_html, 'Unsafe URL schemes must not survive rendering.');

$normal_markdown = $Renderer->render("# Заголовок\n\n**Жирный текст** и [ссылка](https://example.com)");
assertContains('<h1>Заголовок</h1>', $normal_markdown, 'Headings must still render.');
assertContains('<strong>Жирный текст</strong>', $normal_markdown, 'Emphasis must still render.');
assertContains('href="https://example.com"', $normal_markdown, 'HTTPS links must still render.');

fwrite(STDOUT, "Markdown renderer regression test passed.\n");
