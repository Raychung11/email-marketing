<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Plain-PHP template renderer with layout inheritance and named sections.
 *
 * Escaping is the default: templates call e() for any interpolated value, and
 * the raw() helper exists but is deliberately awkward to type so that unescaped
 * output is a conscious decision that shows up in review.
 */
final class View
{
    /** @var array<string,mixed> */
    private array $shared = [];

    /** @var array<string,string> */
    private array $sections = [];

    /** @var array<int,string> */
    private array $sectionStack = [];

    private ?string $layout = null;

    /** @var array<string,mixed> variables a template defined for its layout */
    private array $exported = [];

    public function __construct(private readonly string $basePath)
    {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /** @param array<string,mixed> $data */
    public function shareMany(array $data): void
    {
        foreach ($data as $key => $value) {
            $this->shared[$key] = $value;
        }
    }

    /** @param array<string,mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $previousLayout   = $this->layout;
        $previousSections = $this->sections;

        $this->layout   = null;
        $this->sections = [];

        $content = $this->renderFile($template, $data);

        if ($this->layout !== null) {
            $layout                    = $this->layout;
            $this->layout              = null;
            $this->sections['content'] = $this->sections['content'] ?? $content;

            // Variables the template defined for itself — $title above all — are
            // handed to the layout, so a page can name itself where it is written
            // rather than through a separate controller argument.
            $content = $this->renderFile($layout, array_merge($data, $this->exported));
        }

        $this->exported = [];

        $this->layout   = $previousLayout;
        $this->sections = $previousSections;

        return $content;
    }

    /** @param array<string,mixed> $data */
    private function renderFile(string $template, array $data): string
    {
        $path = $this->resolve($template);

        if (!is_file($path)) {
            throw new \RuntimeException("View [{$template}] not found at {$path}.");
        }

        $variables = array_merge($this->shared, $data);

        // $__view is used by the helper methods inside templates.
        $__view = $this;

        extract($variables, EXTR_SKIP);

        ob_start();

        try {
            include $path;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        $output = (string) ob_get_clean();

        // Capture scalars the template defined, skipping the framework's own
        // locals and anything already supplied by the caller.
        foreach (get_defined_vars() as $name => $value) {
            if (in_array($name, ['__view', 'path', 'variables', 'data', 'template', 'output', 'e'], true)) {
                continue;
            }

            if (!array_key_exists($name, $variables) && (is_scalar($value) || $value === null)) {
                $this->exported[$name] = $value;
            }
        }

        return $output;
    }

    private function resolve(string $template): string
    {
        return $this->basePath . '/' . str_replace('.', '/', $template) . '.php';
    }

    public function extend(string $layout): void
    {
        $this->layout = $layout;
    }

    public function startSection(string $name): void
    {
        $this->sectionStack[] = $name;
        ob_start();
    }

    public function endSection(): void
    {
        $name = array_pop($this->sectionStack);

        if ($name === null) {
            throw new \RuntimeException('endSection() called without a matching startSection().');
        }

        $this->sections[$name] = (string) ob_get_clean();
    }

    public function section(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    public function hasSection(string $name): bool
    {
        return isset($this->sections[$name]);
    }

    /** @param array<string,mixed> $data */
    public function include(string $template, array $data = []): string
    {
        return $this->renderFile($template, array_merge($this->shared, $data));
    }

    public function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
