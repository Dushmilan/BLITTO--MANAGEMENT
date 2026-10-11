<?php

use Tests\Support\DeadlineWriteDetector;

/**
 * CONTEXT.md invariant 4: the docketing module is the sole authority on
 * deadlines. No duplicate tracking elsewhere in the domain.
 */
it('writes deadline rows only from the docketing domain', function () {
    $appDir = dirname(__DIR__, 2).'/app';

    $violations = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($appDir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();

        // The docketing domain is the authority; everything else is scanned.
        if (str_contains($path, DIRECTORY_SEPARATOR.'Domain'.DIRECTORY_SEPARATOR.'Docketing'.DIRECTORY_SEPARATOR)) {
            continue;
        }

        if (DeadlineWriteDetector::isWrite((string) file_get_contents($path))) {
            $violations[] = substr($path, strlen($appDir) + 1);
        }
    }

    expect($violations)->toBeEmpty();
});
