<?php
/** Strict PHP 8+ S3 compression, cleanup and real loopback transport regressions. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (PHP_VERSION_ID < 80000 || !extension_loaded('curl') || !extension_loaded('gd') || !function_exists('imagewebp')) {
    fwrite(STDERR, "Requires PHP 8+, cURL, GD/WebP and subprocess creation.\n"); exit(2);
}
$temporary = sys_get_temp_dir() . '/enhancement-s3-test-' . bin2hex(random_bytes(6));
mkdir($temporary, 0700);
$environment = getenv();
$environment['TMPDIR'] = $temporary;
$environment['ENHANCEMENT_S3_TEST_ROOT'] = $temporary;
$exit = 0;
try {
    foreach ([dirname(__DIR__) . '/bot-moments/s3-storage.php', __DIR__ . '/client.php'] as $test) {
        $process = proc_open([PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'display_errors=1', $test], [STDIN, STDOUT, STDERR], $pipes, null, $environment);
        $result = proc_close($process);
        if ($result !== 0) { $exit = $result; break; }
    }
} finally {
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname());
        else unlink($entry->getPathname());
    }
    rmdir($temporary);
}
exit($exit);
