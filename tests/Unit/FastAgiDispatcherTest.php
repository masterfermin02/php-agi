<?php

declare(strict_types=1);

// Load the AGI test double only for this test file.
// FastAgiDispatcher instantiates "new AGI()" in the Fperdomo\PhpAgi namespace.
if (! class_exists(Fperdomo\PhpAgi\AGI::class, false)) {
    require_once __DIR__.'/../Support/FakeAgiForFastAgiDispatcher.php';
}

use Fperdomo\PhpAgi\FastAgiDispatcher;

beforeEach(function (): void {
    $this->tmpBaseDir = sys_get_temp_dir().'/phpagi-fastagi-dispatcher-'.bin2hex(random_bytes(8));
    mkdir($this->tmpBaseDir, 0777, true);
});

afterEach(function (): void {
    if (! isset($this->tmpBaseDir) || ! is_dir($this->tmpBaseDir)) {
        return;
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->tmpBaseDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($it as $file) {
        if ($file->isDir()) {
            @rmdir($file->getPathname());
        } else {
            @unlink($file->getPathname());
        }
    }

    @rmdir($this->tmpBaseDir);

    // Ensure we don't leak prepared instances between tests.
    Fperdomo\PhpAgi\AGI::$__nextInstance = null;
});

it('refuses to dispatch when agi_network_script is missing', function (): void {
    $dispatcher = new FastAgiDispatcher(baseDir: $this->tmpBaseDir);

    ob_start();
    $dispatcher->handle();
    ob_end_clean();

    // Assert: nothing executed; no files were created.
    expect(glob($this->tmpBaseDir.'/*'))->toBeEmpty();
});

it('blocks path traversal outside baseDir', function (): void {
    // Arrange
    $dispatcher = new FastAgiDispatcher(baseDir: $this->tmpBaseDir);

    // Provide a handler outside baseDir that would create a marker file if executed.
    $outsideDir = sys_get_temp_dir().'/phpagi-fastagi-outside-'.bin2hex(random_bytes(8));
    mkdir($outsideDir, 0777, true);

    $outsideHandler = $outsideDir.'/outside.php';
    $outsideMarker = $outsideDir.'/outside.marker';
    file_put_contents($outsideHandler, "<?php file_put_contents('".addslashes($outsideMarker)."', 'ran');");

    $preparedAgi = new Fperdomo\PhpAgi\AGI;
    $preparedAgi->request['agi_network_script'] = '../'.basename($outsideDir).'/outside.php';
    Fperdomo\PhpAgi\AGI::$__nextInstance = $preparedAgi;

    ob_start();
    $dispatcher->handle();
    ob_end_clean();

    // Assert: the outside handler was not executed
    expect(file_exists($outsideMarker))->toBeFalse();

    // cleanup
    @unlink($outsideHandler);
    if (file_exists($outsideMarker)) {
        @unlink($outsideMarker);
    }
    @rmdir($outsideDir);
});

it('returns when handler does not exist', function (): void {
    $dispatcher = new FastAgiDispatcher(baseDir: $this->tmpBaseDir);

    $preparedAgi = new Fperdomo\PhpAgi\AGI;
    $preparedAgi->request['agi_network_script'] = 'missing.php';
    Fperdomo\PhpAgi\AGI::$__nextInstance = $preparedAgi;

    ob_start();
    $dispatcher->handle();
    ob_end_clean();

    // Handler is missing, so nothing should have been created/executed.
    expect(glob($this->tmpBaseDir.'/*'))->toBeEmpty();
});

it('executes a handler within baseDir and exposes $agi and $fastagi', function (): void {
    $handlersDir = $this->tmpBaseDir.'/handlers';
    mkdir($handlersDir, 0777, true);

    $marker = $handlersDir.'/ran.marker';
    $handler = $handlersDir.'/hello.php';

    file_put_contents($handler, <<<'PHP'
<?php
// Make sure both variables are provided
if (!isset($agi) || !isset($fastagi)) {
    throw new RuntimeException('Expected $agi and $fastagi to be set');
}

file_put_contents(__DIR__ . '/ran.marker', 'ok');
PHP);

    $dispatcher = new FastAgiDispatcher(baseDir: $handlersDir);

    $preparedAgi = new Fperdomo\PhpAgi\AGI;
    $preparedAgi->request['agi_network_script'] = 'hello.php';
    Fperdomo\PhpAgi\AGI::$__nextInstance = $preparedAgi;

    ob_start();
    $dispatcher->handle();
    ob_end_clean();

    expect(file_exists($marker))->toBeTrue();
    expect(file_get_contents($marker))->toBe('ok');
});
