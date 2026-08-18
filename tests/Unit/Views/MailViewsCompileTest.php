<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;

/**
 * Every Blade view in the package must compile to parseable PHP.
 *
 * verification.blade.php and user-credentials.blade.php both shipped broken: the greeting
 * read `Good Morning@if($user->name), ...@endif`, and Blade only treats `@` as a directive
 * when the preceding character is NOT a word character — the rule that stops `foo@bar.com`
 * compiling. So the `@if` stayed literal text while its `@endif` compiled anyway, leaving
 * an unmatched endif that broke the enclosing if/elseif/else:
 *
 *   syntax error, unexpected token "else", expecting end of file
 *
 * The whole view failed to parse, so every verification and credentials mail threw instead
 * of sending. Nothing caught it because no test ever compiled a view — the templates were
 * only ever exercised through mocked mailers.
 */
it('compiles every blade view to parseable php', function () {
    $viewPath = dirname(__DIR__, 3) . '/views';
    $files    = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewPath));

    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }

    expect($files)->not->toBeEmpty();

    // withoutComponentTags(): compiling <x-mail-layout> resolves a View Factory out of the
    // container, which a unit harness has no reason to bind. The bug this guards lives in
    // the directive layer, which is still compiled in full.
    $compiler = new BladeCompiler(new Filesystem(), sys_get_temp_dir());
    $compiler->withoutComponentTags();

    $broken   = [];

    foreach ($files as $file) {
        $tmp = tempnam(sys_get_temp_dir(), 'blade_') . '.php';
        file_put_contents($tmp, $compiler->compileString(file_get_contents($file)));

        // php -l is the only honest check here: a template compiles to a string just fine
        // and can still be a parse error, which is exactly what shipped.
        $output = [];
        exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $output, $status);
        @unlink($tmp);

        if ($status !== 0) {
            $broken[] = basename($file) . ': ' . implode(' ', $output);
        }
    }

    expect($broken)->toBe([]);
});
