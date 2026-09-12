<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\View;
use Tests\Support\TestCase;

/**
 * The view engine.
 *
 * The shadowing test exists because this bug was real and silent: renderFile()
 * had locals named $template and $data, extract() uses EXTR_SKIP, so a view
 * variable with either name was quietly replaced by the framework's own — the
 * page did not error, it rendered the wrong thing.
 */
final class ViewTest extends TestCase
{
    private string $directory;

    public function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/views-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/layouts', 0775, true);
    }

    public function tearDown(): void
    {
        foreach (glob($this->directory . '/**/*.php') ?: [] as $file) {
            @unlink($file);
        }

        foreach (glob($this->directory . '/*.php') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory . '/layouts');
        @rmdir($this->directory);

        parent::tearDown();
    }

    public function testViewVariablesAreNotShadowedByTheEnginesOwnLocals(): void
    {
        // Every name the renderer once used as a local.
        $this->write('shadow.php', '<?= $template ?>|<?= $data ?>|<?= $path ?>|<?= $variables ?>|<?= $output ?>');

        $view = new View($this->directory);

        $rendered = $view->render('shadow', [
            'template'  => 'TEMPLATE',
            'data'      => 'DATA',
            'path'      => 'PATH',
            'variables' => 'VARIABLES',
            'output'    => 'OUTPUT',
        ]);

        $this->assertSame('TEMPLATE|DATA|PATH|VARIABLES|OUTPUT', $rendered);
    }

    public function testATemplateCanNameItselfForItsLayout(): void
    {
        $this->write('layouts/wrap.php', '<title><?= $title ?></title><?= $__view->section("content") ?>');
        $this->write('page.php',
            '<?php $__view->extend("layouts.wrap"); $title = "Contacts"; ?>'
            . '<?php $__view->startSection("content"); ?>Body<?php $__view->endSection(); ?>'
        );

        $view = new View($this->directory);

        $this->assertSame('<title>Contacts</title>Body', $view->render('page'));
    }

    public function testCallerDataWinsOverATemplateDefault(): void
    {
        $this->write('layouts/wrap.php', '<title><?= $title ?></title>');
        $this->write('page.php',
            '<?php $__view->extend("layouts.wrap"); $title = $title ?? "Fallback"; ?>'
            . '<?php $__view->startSection("content"); ?>x<?php $__view->endSection(); ?>'
        );

        $view = new View($this->directory);

        $this->assertSame('<title>Given</title>', $view->render('page', ['title' => 'Given']));
    }

    public function testExportedVariablesDoNotLeakBetweenRenders(): void
    {
        $this->write('layouts/wrap.php', '[<?= $title ?? "none" ?>]');
        $this->write('titled.php',
            '<?php $__view->extend("layouts.wrap"); $title = "First"; ?>'
            . '<?php $__view->startSection("content"); ?>a<?php $__view->endSection(); ?>'
        );
        $this->write('untitled.php',
            '<?php $__view->extend("layouts.wrap"); ?>'
            . '<?php $__view->startSection("content"); ?>b<?php $__view->endSection(); ?>'
        );

        $view = new View($this->directory);

        $this->assertSame('[First]', $view->render('titled'));
        $this->assertSame('[none]', $view->render('untitled'), 'The previous page\'s title must not carry over');
    }

    public function testOutputBufferingUnwindsWhenATemplateThrows(): void
    {
        $this->write('broken.php', '<?php throw new \RuntimeException("template blew up"); ?>');

        $view  = new View($this->directory);
        $depth = ob_get_level();

        $this->assertThrows(\RuntimeException::class, static fn () => $view->render('broken'));

        // A leaked output buffer corrupts every response after it.
        $this->assertSame($depth, ob_get_level(), 'The output buffer is unwound on failure');
    }

    public function testEscapingIsAvailableAndCorrect(): void
    {
        $view = new View($this->directory);

        $this->assertSame('&lt;script&gt;', $view->e('<script>'));
        $this->assertSame('&quot;quoted&quot;', $view->e('"quoted"'));
        $this->assertSame('&#039;single&#039;', $view->e("'single'"));
    }

    private function write(string $name, string $contents): void
    {
        file_put_contents($this->directory . '/' . $name, $contents);
    }
}
