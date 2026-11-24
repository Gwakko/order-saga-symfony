<?php

declare(strict_types=1);

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Temporal\Testing\Downloader;
use Temporal\Testing\SystemInfo;

/**
 * Explicit, one-off install of `temporal-test-server` for the workflow tests (`make wf-install`, M6.2).
 * The binary is NOT part of vendor: it is a GraalVM build from the Temporal Java SDK releases. A pinned release is
 * downloaded into var/temporal-test-server/ (git-ignored through /var/); the tests never download anything themselves.
 * Re-running is a no-op while the binary exists; `--force` re-downloads.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

const TEST_SERVER_RELEASE = 'v1.41.1'; // temporalio/sdk-java, published 2026-11-16

$target = dirname(__DIR__) . '/var/temporal-test-server';
$binary = $target . '/temporal-test-server';

if (is_file($binary) && !in_array('--force', $argv, true)) {
    fwrite(STDOUT, sprintf("temporal-test-server already installed: %s (%d bytes, sha256 %s)\n", $binary, filesize($binary), hash_file('sha256', $binary)));
    exit(0);
}

if (!is_dir($target) && !mkdir($target, 0o775, true) && !is_dir($target)) {
    fwrite(STDERR, "Cannot create $target\n");
    exit(1);
}

// The SDK downloader writes into the current directory.
chdir($target);

$token = getenv('GITHUB_TOKEN');
$client = HttpClient::create(['headers' => ['authorization' => is_string($token) && $token !== '' ? 'token ' . $token : null]]);

(new Downloader(new Filesystem(), $client, TEST_SERVER_RELEASE))->download(SystemInfo::detect());

fwrite(STDOUT, sprintf("installed %s (%d bytes, sha256 %s) from sdk-java %s\n", $binary, filesize($binary), hash_file('sha256', $binary), TEST_SERVER_RELEASE));
