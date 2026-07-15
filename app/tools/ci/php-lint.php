<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    if (str_starts_with($path, $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
        continue;
    }

    $files[] = $path;
}

sort($files);
$failed = false;

foreach ($files as $file) {
    $output = [];
    $exit_code = 0;
    exec(
        escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1',
        $output,
        $exit_code
    );

    if ($exit_code !== 0) {
        $failed = true;
        fwrite(STDERR, implode(PHP_EOL, $output) . PHP_EOL);
    }
}

if ($failed) {
    exit(1);
}

fwrite(STDOUT, sprintf("PHP lint passed for %d files.\n", count($files)));
