<?php

declare(strict_types=1);

/*
 * Guards against date drift in the test suite.
 *
 * A hardcoded date literal that is today or in the future is only safe in a test file that freezes
 * the clock. If the clock is frozen the literal can never drift; if it is not, the literal is a
 * scheduled failure that lands whenever the wall clock catches up to it.
 *
 * Files that call Carbon::setTestNow(), travelTo() or freezeTime() are skipped entirely. Individual
 * lines can opt out with a `// date-drift-ok: <reason>` comment for literals that are genuinely
 * never compared against the clock.
 *
 * Usage: check-test-date-drift.php [path] [--today=YYYY-MM-DD]
 *
 * The --today override exists so the check itself can be exercised against a chosen reference date;
 * it defaults to the current UTC date.
 */

$testsPath = __DIR__ . '/../tests';
$today     = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--today=')) {
        $today = substr($argument, strlen('--today='));

        if (preg_match('/^20\d{2}-\d{2}-\d{2}$/', $today) !== 1) {
            fwrite(STDERR, "Invalid --today value, expected YYYY-MM-DD: {$today}\n");
            exit(1);
        }

        continue;
    }

    $testsPath = $argument;
}

if (!is_dir($testsPath)) {
    fwrite(STDERR, "Tests directory not found: {$testsPath}\n");
    exit(1);
}

const DATE_PATTERN   = '/\b(20\d{2})-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])\b/';
// Matched on `::setTestNow(` rather than `Carbon::setTestNow(` so an aliased or fully qualified
// import (`use Illuminate\Support\Carbon as TestClock`) still registers as a frozen clock.
const FREEZE_PATTERN = '/::setTestNow\(|travelTo\(|freezeTime\(/';
const ESCAPE_HATCH   = 'date-drift-ok';

/**
 * Recursively collect every PHP file beneath the given directory.
 *
 * @return string[]
 */
function collectTestFiles(string $path): array
{
    $files    = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getRealPath();
        }
    }

    sort($files);

    return $files;
}

/**
 * Find every date literal in the file that is today or later and is not exempt.
 *
 * @return array<int, array{line: int, date: string, source: string}>
 */
function findDriftingDates(string $contents, string $today): array
{
    if (preg_match(FREEZE_PATTERN, $contents) === 1) {
        return [];
    }

    $drifting = [];

    foreach (explode("\n", $contents) as $index => $line) {
        if (str_contains($line, ESCAPE_HATCH)) {
            continue;
        }

        if (preg_match_all(DATE_PATTERN, $line, $matches) === 0) {
            continue;
        }

        foreach (array_unique($matches[0]) as $date) {
            if ($date >= $today) {
                $drifting[] = [
                    'line'   => $index + 1,
                    'date'   => $date,
                    'source' => trim($line),
                ];
            }
        }
    }

    return $drifting;
}

$files    = collectTestFiles($testsPath);
$failures = [];

foreach ($files as $file) {
    $contents = (string) file_get_contents($file);

    foreach (findDriftingDates($contents, $today) as $drift) {
        $failures[] = ['file' => $file] + $drift;
    }
}

$root         = realpath(__DIR__ . '/..') ?: '';
$relativePath = static fn (string $file): string => $root !== '' && str_starts_with($file, $root . '/')
    ? substr($file, strlen($root) + 1)
    : $file;

if ($failures === []) {
    $count = count($files);
    echo "Test date drift check passed: no future-dated literals in unfrozen test files ({$count} files scanned, today is {$today}).\n";
    exit(0);
}

fwrite(STDERR, "Test date drift check failed (today is {$today}).\n\n");

foreach ($failures as $failure) {
    fwrite(STDERR, sprintf(
        "  %s:%d uses %s, which is not in the past.\n    %s\n",
        $relativePath($failure['file']),
        $failure['line'],
        $failure['date'],
        $failure['source']
    ));
}

fwrite(STDERR, "\nThese literals will change meaning as the wall clock advances, and any of them compared\n");
fwrite(STDERR, "against now() — directly or through a global scope such as ExpiryScope — will eventually\n");
fwrite(STDERR, "fail the suite on a day nobody touched the code.\n\n");
fwrite(STDERR, "Fix by freezing the clock with Carbon::setTestNow() in a beforeEach (and resetting it in\n");
fwrite(STDERR, "afterEach), or, if the literal is genuinely never compared to the clock, annotate the line\n");
fwrite(STDERR, "with `// " . ESCAPE_HATCH . ": <reason>`.\n");

exit(1);
