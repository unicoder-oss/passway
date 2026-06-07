<?php

declare(strict_types=1);

namespace Passway\Services;

final class DocumentationService
{
    public const DEFAULT_SLUG = 'features';

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $cache = [];

    /**
     * @return array{article: array<string, mixed>, categories: array<int, array{name: string, articles: array<int, array<string, mixed>>}>}|null
     */
    public function page(string $slug, string $locale): ?array
    {
        $slug = $this->normalizeSlug($slug) ?: self::DEFAULT_SLUG;
        $articles = $this->articlesForLocale($locale);
        $article = $articles[$slug] ?? null;

        if ($article === null && normalize_locale_code($locale) !== app_fallback_locale()) {
            $fallbackArticles = $this->articlesForLocale(app_fallback_locale());
            $article = $fallbackArticles[$slug] ?? null;
        }

        if ($article === null) {
            return null;
        }

        return [
            'article' => $article,
            'categories' => $this->categoriesForLocale($locale),
        ];
    }

    /**
     * @return array<int, array{name: string, articles: array<int, array<string, mixed>>}>
     */
    public function categoriesForLocale(string $locale): array
    {
        $articles = \array_values($this->articlesForLocale($locale));
        if ($articles === [] && normalize_locale_code($locale) !== app_fallback_locale()) {
            $articles = \array_values($this->articlesForLocale(app_fallback_locale()));
        }

        $categories = [];
        foreach ($articles as $article) {
            $category = (string) ($article['category'] ?? __('ui.docs.uncategorized'));
            $categories[$category][] = [
                'slug' => (string) $article['slug'],
                'title' => (string) $article['title'],
                'order' => (int) $article['order'],
                'category' => $category,
            ];
        }

        $result = [];
        foreach ($categories as $name => $items) {
            \usort($items, $this->articleSorter());
            $result[] = ['name' => (string) $name, 'articles' => $items];
        }

        \usort($result, static function (array $left, array $right): int {
            $leftOrder = (int) ($left['articles'][0]['order'] ?? 0);
            $rightOrder = (int) ($right['articles'][0]['order'] ?? 0);
            return $leftOrder <=> $rightOrder ?: \strnatcasecmp((string) $left['name'], (string) $right['name']);
        });

        return $result;
    }

    public function renderMarkdown(string $markdown): string
    {
        $lines = \preg_split('/\R/u', $markdown) ?: [];
        $html = [];
        $paragraph = [];
        $list = [];
        $inCode = false;
        $code = [];

        $flushParagraph = function () use (&$html, &$paragraph): void {
            if ($paragraph === []) {
                return;
            }
            $html[] = '<p>' . $this->renderInline(\implode(' ', $paragraph)) . '</p>';
            $paragraph = [];
        };

        $flushList = function () use (&$html, &$list): void {
            if ($list === []) {
                return;
            }
            $html[] = '<ul><li>' . \implode('</li><li>', \array_map(fn(string $item): string => $this->renderInline($item), $list)) . '</li></ul>';
            $list = [];
        };

        $flushCode = function () use (&$html, &$code): void {
            $html[] = '<pre class="docs-code"><code>' . e(\implode("\n", $code)) . '</code></pre>';
            $code = [];
        };

        for ($i = 0, $count = \count($lines); $i < $count; $i++) {
            $line = \rtrim((string) $lines[$i]);

            if (\str_starts_with(\trim($line), '```')) {
                if ($inCode) {
                    $flushCode();
                    $inCode = false;
                } else {
                    $flushParagraph();
                    $flushList();
                    $inCode = true;
                }
                continue;
            }

            if ($inCode) {
                $code[] = $line;
                continue;
            }

            if (\preg_match('/^>\s*\[!NOTE\]\s*$/u', $line) === 1) {
                $flushParagraph();
                $flushList();
                $noteLines = [];
                while (($lines[$i + 1] ?? null) !== null && \preg_match('/^>\s?(.*)$/u', (string) $lines[$i + 1], $matches) === 1) {
                    $noteLines[] = (string) $matches[1];
                    $i++;
                }
                $html[] = '<aside class="docs-note"><strong>' . e(__('ui.docs.note')) . '</strong><p>' . $this->renderInline(\trim(\implode(' ', $noteLines))) . '</p></aside>';
                continue;
            }

            if (\trim($line) === '') {
                $flushParagraph();
                $flushList();
                continue;
            }

            if (\preg_match('/^(#{1,3})\s+(.+)$/u', $line, $matches) === 1) {
                $flushParagraph();
                $flushList();
                $level = \strlen((string) $matches[1]);
                $title = \trim((string) $matches[2]);
                $id = $this->headingId($title);
                $html[] = '<h' . $level . ' id="' . e($id) . '">' . $this->renderInline($title) . '</h' . $level . '>';
                continue;
            }

            if (\preg_match('/^-\s+(.+)$/u', $line, $matches) === 1) {
                $flushParagraph();
                $list[] = (string) $matches[1];
                continue;
            }

            $flushList();
            $paragraph[] = $line;
        }

        if ($inCode) {
            $flushCode();
        }
        $flushParagraph();
        $flushList();

        return \implode("\n", $html);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function articlesForLocale(string $locale): array
    {
        $locale = normalize_locale_code($locale) ?? app_fallback_locale();
        if (isset($this->cache[$locale])) {
            return $this->cache[$locale];
        }

        $dir = base_path('resources/docs/user/' . $locale);
        $files = \is_dir($dir) ? (\glob($dir . '/*.md') ?: []) : [];
        $articles = [];

        foreach ($files as $file) {
            $raw = (string) \file_get_contents($file);
            [$meta, $body] = $this->parseFrontMatter($raw);
            $slug = $this->normalizeSlug((string) ($meta['slug'] ?? \basename($file, '.md')));
            if ($slug === '') {
                continue;
            }

            $title = \trim((string) ($meta['title'] ?? $slug));
            $category = \trim((string) ($meta['category'] ?? __('ui.docs.uncategorized')));
            $articles[$slug] = [
                'slug' => $slug,
                'title' => $title !== '' ? $title : $slug,
                'category' => $category !== '' ? $category : __('ui.docs.uncategorized'),
                'order' => (int) ($meta['order'] ?? 1000),
                'body' => $body,
                'html' => $this->renderMarkdown($body),
            ];
        }

        \uasort($articles, $this->articleSorter());
        return $this->cache[$locale] = $articles;
    }

    /**
     * @return array{0: array<string, string>, 1: string}
     */
    private function parseFrontMatter(string $raw): array
    {
        if (\preg_match('/^---\R(.*?)\R---\R?(.*)$/su', $raw, $matches) !== 1) {
            return [[], $raw];
        }

        $meta = [];
        foreach (\preg_split('/\R/u', (string) $matches[1]) ?: [] as $line) {
            if (\preg_match('/^([a-zA-Z0-9_-]+):\s*(.*)$/u', (string) $line, $parts) === 1) {
                $meta[(string) $parts[1]] = \trim((string) $parts[2], " \t\n\r\0\x0B\"'");
            }
        }

        return [$meta, (string) $matches[2]];
    }

    private function renderInline(string $text): string
    {
        $placeholders = [];
        $store = static function (string $html) use (&$placeholders): string {
            $key = '%%PASSWAY_DOCS_' . \count($placeholders) . '%%';
            $placeholders[$key] = $html;
            return $key;
        };

        $text = \preg_replace_callback('/`([^`]+)`/u', static fn(array $matches): string => $store('<code>' . e((string) $matches[1]) . '</code>'), $text) ?? $text;
        $text = \preg_replace_callback('/!\[([^\]]*)\]\(([^)]+)\)/u', fn(array $matches): string => $store($this->renderImage((string) $matches[1], (string) $matches[2])), $text) ?? $text;
        $text = \preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/u', fn(array $matches): string => $store($this->renderLink((string) $matches[1], (string) $matches[2])), $text) ?? $text;

        $escaped = e($text);
        $escaped = \preg_replace('/\*\*([^*]+)\*\*/u', '<strong>$1</strong>', $escaped) ?? $escaped;

        return \strtr($escaped, $placeholders);
    }

    private function renderLink(string $label, string $href): string
    {
        $href = $this->safeHref($href);
        return '<a href="' . e($href) . '">' . e($label) . '</a>';
    }

    private function renderImage(string $alt, string $src): string
    {
        $src = $this->safeImageSrc($src);
        if ($src === '') {
            return '';
        }
        return '<img src="' . e($src) . '" alt="' . e($alt) . '" loading="lazy" decoding="async">';
    }

    private function safeHref(string $href): string
    {
        $href = \trim($href);
        if ($href === '' || \preg_match('/^(https?:\/\/|mailto:|\/|#)/i', $href) !== 1) {
            return '#';
        }
        return $href;
    }

    private function safeImageSrc(string $src): string
    {
        $src = \trim($src);
        if ($src === '' || \preg_match('/^(https?:\/\/|\/docs\/images\/)/i', $src) !== 1) {
            return '';
        }
        return $src;
    }

    private function normalizeSlug(string $slug): string
    {
        return \trim((string) \preg_replace('/[^a-z0-9_-]+/i', '-', \strtolower($slug)), '-');
    }

    private function headingId(string $title): string
    {
        $id = $this->normalizeSlug($title);
        return $id !== '' ? $id : 'section';
    }

    private function articleSorter(): callable
    {
        return static function (array $left, array $right): int {
            return (int) ($left['order'] ?? 0) <=> (int) ($right['order'] ?? 0)
                ?: \strnatcasecmp((string) ($left['title'] ?? ''), (string) ($right['title'] ?? ''));
        };
    }
}
