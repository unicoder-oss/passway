<?php

declare(strict_types=1);

namespace Passway\Services;

final class ViewService
{
    /** @param array<string, mixed> $data */
    public function render(string $view, array $data = [], ?string $layout = 'layout'): string
    {
        $viewPath = base_path('resources/views/' . $view . '.php');
        if (!\file_exists($viewPath)) {
            throw new \RuntimeException(__('ui.backend.view.view_not_found', ['view' => $view]));
        }

        $content = $this->capture($viewPath, $data);
        if ($layout === null) {
            return $content;
        }

        $layoutPath = base_path('resources/views/' . $layout . '.php');
        if (!\file_exists($layoutPath)) {
            throw new \RuntimeException(__('ui.backend.view.layout_not_found', ['layout' => $layout]));
        }

        return $this->capture($layoutPath, \array_merge($data, ['content' => $content]));
    }

    /** @param array<string, mixed> $data */
    private function capture(string $path, array $data): string
    {
        \extract($data, EXTR_SKIP);
        \ob_start();
        require $path;
        return (string) \ob_get_clean();
    }
}
