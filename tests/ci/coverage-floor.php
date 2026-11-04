<?php

declare(strict_types=1);

/*
 * The tests' coverage may not fall below these (STATIC-10): in all, and in each part that keeps
 * sites apart or keeps the server whole. Raise a floor as its coverage grows; lowering one is a
 * decision a review sees. (Commands are tested end to end, tests/e2e, not here.)
 *
 *   php tests/ci/coverage-floor.php var/clover.xml
 */

const FLOORS = [
    '' => 75.0,
    'Database' => 90.0,
    'Docker' => 91.0,
    'Git' => 80.0,
    'Network' => 95.0,
    'Operation' => 90.0,
    'Release' => 94.0,
    'ServerUpdate' => 90.0,
    'Site' => 93.0,
];

$clover = $argv[1] ?? 'var/clover.xml';
$report = is_file($clover) ? simplexml_load_file($clover) : false;

if (false === $report) {
    fwrite(\STDERR, "No coverage report at {$clover}: run phpunit --coverage-clover {$clover} first.\n");
    exit(2);
}

$counts = [];

foreach ($report->xpath('//file') ?: [] as $file) {
    $path = (string) preg_replace('#^.*/src/#', '', (string) $file['name']);
    $metrics = $file->metrics;

    foreach (['', explode('/', $path)[0]] as $part) {
        $counts[$part][0] = ($counts[$part][0] ?? 0) + (int) $metrics['statements'];
        $counts[$part][1] = ($counts[$part][1] ?? 0) + (int) $metrics['coveredstatements'];
    }
}

$failed = false;

foreach (FLOORS as $part => $floor) {
    [$lines, $covered] = $counts[$part] ?? [0, 0];
    $coverage = 0 === $lines ? 0.0 : 100 * $covered / $lines;
    $ok = $coverage >= $floor;
    $failed = $failed || !$ok;
    printf("%s %-13s %5.1f%% of %5d lines (at least %.0f%%)\n", $ok ? 'ok  ' : 'FAIL', '' === $part ? 'in all' : $part, $coverage, $lines, $floor);
}

exit($failed ? 1 : 0);
