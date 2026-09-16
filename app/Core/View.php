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

    /**
     * Include a template with the given variables in scope.
     *
     * Every local in this method is prefixed with __view so it cannot collide
     * with a view variable. extract() uses EXTR_SKIP, so a collision would mean
     * the framework's local silently wins and the template renders with the wrong
     * value — a view variable named $template or $data would break in a way that
     * is very hard to see.
     *
     * @param array<string,mixed> $__viewData
     */
    private function renderFile(string $__viewName, array $__viewData): string
    {
        $__viewPath = $this->resolve($__viewName);

        if (!is_file($__viewPath)) {
            throw new \RuntimeException("View [{$__viewName}] not found at {$__viewPath}.");
        }

        $__viewVariables = array_merge($this->shared, $__viewData);

        // $__view is the one name templates are expected to use, for the section
        // and include helpers.
        $__view = $this;

        extract($__viewVariables, EXTR_SKIP);

        ob_start();

        try {
            include $__viewPath;
        } catch (\Throwable $__viewException) {
            ob_end_clean();
            throw $__viewException;
        }

        $__viewOutput = (string) ob_get_clean();

        // Carry scalars the template defined for itself — $title above all —
        // through to the layout.
        foreach (get_defined_vars() as $__viewLocal => $__viewValue) {
            if (str_starts_with($__viewLocal, '__view')) {
                continue;
            }

            if (!array_key_exists($__viewLocal, $__viewVariables)
                && (is_scalar($__viewValue) || $__viewValue === null)
            ) {
                $this->exported[$__viewLocal] = $__viewValue;
            }
        }

        return $__viewOutput;
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
