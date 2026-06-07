<?php

declare(strict_types=1);

namespace Passway\Tests\Services;

use Passway\Services\DocumentationService;
use PHPUnit\Framework\TestCase;

final class DocumentationServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        reset_request_locale();
        parent::tearDown();
    }

    public function test_it_loads_localized_articles_and_categories(): void
    {
        set_request_locale('ru');

        $service = new DocumentationService();
        $page = $service->page('features', 'ru');

        self::assertNotNull($page);
        self::assertSame('Возможности сервиса', $page['article']['title']);
        self::assertStringContainsString('/docs/faq', (string) $page['article']['html']);
        self::assertNotEmpty($page['categories']);
        self::assertSame('Начало работы', $page['categories'][0]['name']);
    }

    public function test_it_falls_back_to_english_for_unknown_locale(): void
    {
        set_request_locale('en');

        $service = new DocumentationService();
        $page = $service->page('features', 'de');

        self::assertNotNull($page);
        self::assertSame('Service capabilities', $page['article']['title']);
    }

    public function test_markdown_renderer_supports_notes_and_sanitizes_html(): void
    {
        set_request_locale('en');

        $service = new DocumentationService();
        $html = $service->renderMarkdown("# Title\n\n<script>alert(1)</script>\n\n> [!NOTE]\n> Keep it safe.\n\n[bad](javascript:alert(1))\n\n![ok](/docs/images/example.png)");

        self::assertStringContainsString('<h1 id="title">Title</h1>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('<aside class="docs-note">', $html);
        self::assertStringContainsString('<a href="#">bad</a>', $html);
        self::assertStringContainsString('<img src="/docs/images/example.png" alt="ok"', $html);
    }
}
