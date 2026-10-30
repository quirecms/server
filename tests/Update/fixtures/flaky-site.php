<?php

declare(strict_types=1);

// A site that has just started, under load: its health check and its home page each answer an error
// every other time, never both rightly at once. Its sign-in page answers as the test says
// (QUIRE_TEST_PAGES: "ok" or "broken").
$path = (string) parse_url(is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/', \PHP_URL_PATH);
$counter = sys_get_temp_dir().'/quire-flaky-'.getenv('QUIRE_TEST_ID').'-'.md5($path);
$count = (int) @file_get_contents($counter) + 1;
file_put_contents($counter, (string) $count);

http_response_code(match ($path) {
    '/_quire/health' => 0 === $count % 2 ? 200 : 503,
    '/' => 1 === $count % 2 ? 200 : 503,
    default => 'ok' === getenv('QUIRE_TEST_PAGES') ? 200 : 500,
});
