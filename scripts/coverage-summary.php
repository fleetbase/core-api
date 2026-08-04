<?php

declare(strict_types=1);

/*
 * Usage: coverage-summary.php [clover.xml] [--min=<percent>]
 *
 * Prints the coverage summary and then enforces a floor on line, method and fully covered class
 * coverage, exiting non-zero when any of them falls short. The floor is what keeps the suite at
 * 100%: without it a merge can drop coverage and nothing fails.
 *
 * Genuinely unreachable or defensive branches should carry a narrow @codeCoverageIgnoreStart/End
 * block with a comment explaining why, which is the existing convention in this codebase.
 */

$cloverPath      = 'coverage/clover.xml';
$minimumCoverage = 100.0;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--min=')) {
        $value = substr($argument, strlen('--min='));

        if (!is_numeric($value) || (float) $value < 0 || (float) $value > 100) {
            fwrite(STDERR, "Invalid --min value, expected a percentage between 0 and 100: {$value}\n");
            exit(1);
        }

        $minimumCoverage = (float) $value;

        continue;
    }

    $cloverPath = $argument;
}

if (!is_file($cloverPath)) {
    fwrite(STDERR, "Coverage file not found: {$cloverPath}\n");
    fwrite(STDERR, "Run `composer test:coverage:clover` first.\n");
    exit(1);
}

$xml = simplexml_load_file($cloverPath);
if (!$xml) {
    fwrite(STDERR, "Unable to parse Clover coverage file: {$cloverPath}\n");
    exit(1);
}

function coveragePercent(int $covered, int $total): float
{
    return $total === 0 ? 0.0 : round(($covered / $total) * 100, 2);
}

function intMetric(SimpleXMLElement $node, string $name): int
{
    return (int) ($node->metrics[$name] ?? 0);
}

function hasMetric(SimpleXMLElement $node, string $name): bool
{
    foreach ($node->metrics->attributes() as $metricName => $value) {
        if ($metricName === $name) {
            return true;
        }
    }

    return false;
}

function derivedClassCoverageMetrics(SimpleXMLElement $project): array
{
    $classes        = 0;
    $coveredClasses = 0;
    $touchedClasses = 0;

    foreach ($project->xpath('.//class') ?: [] as $class) {
        $statements        = intMetric($class, 'statements');
        $coveredStatements = intMetric($class, 'coveredstatements');

        if ($statements === 0) {
            continue;
        }

        $classes++;

        if ($coveredStatements > 0) {
            $touchedClasses++;
        }

        if ($statements > 0 && $coveredStatements === $statements) {
            $coveredClasses++;
        }
    }

    return [
        'classes' => $classes,
        'covered' => $coveredClasses,
        'touched' => $touchedClasses,
    ];
}

$project = $xml->project;
$metrics = $project->metrics;

$statements        = (int) ($metrics['statements'] ?? 0);
$coveredStatements = (int) ($metrics['coveredstatements'] ?? 0);
$methods           = (int) ($metrics['methods'] ?? 0);
$coveredMethods    = (int) ($metrics['coveredmethods'] ?? 0);
$classes           = (int) ($metrics['classes'] ?? 0);
$coveredClasses    = (int) ($metrics['coveredclasses'] ?? 0);
$classCoverageLabel = 'Class coverage';
$classCoverageNote  = '';
$touchedClasses    = null;

if (!hasMetric($project, 'coveredclasses')) {
    $derivedClasses      = derivedClassCoverageMetrics($project);
    $classes             = $classes ?: $derivedClasses['classes'];
    $coveredClasses      = $derivedClasses['covered'];
    $touchedClasses      = $derivedClasses['touched'];
    $classCoverageLabel  = 'Fully covered class coverage';
    $classCoverageNote   = ' derived from class statement metrics because Clover omits coveredclasses';
}

$files       = [];
$directories = [];

foreach ($project->xpath('.//file') ?: [] as $file) {
    $path              = (string) $file['name'];
    $fileStatements    = intMetric($file, 'statements');
    $coveredFileLines  = intMetric($file, 'coveredstatements');
    $fileMethods       = intMetric($file, 'methods');
    $coveredFileMethod = intMetric($file, 'coveredmethods');

    if ($fileStatements === 0) {
        continue;
    }

    $files[] = [
        'path'            => $path,
        'covered'         => $coveredFileLines,
        'statements'      => $fileStatements,
        'methods'         => $fileMethods,
        'covered_methods' => $coveredFileMethod,
        'percent'         => coveragePercent($coveredFileLines, $fileStatements),
    ];

    $relativePath = preg_replace('#^' . preg_quote(getcwd(), '#') . '/?#', '', $path);
    $parts        = explode('/', $relativePath ?: $path);
    $directory    = count($parts) > 2 ? $parts[0] . '/' . $parts[1] : dirname($relativePath ?: $path);

    if (!isset($directories[$directory])) {
        $directories[$directory] = [
            'covered'    => 0,
            'statements' => 0,
        ];
    }

    $directories[$directory]['covered'] += $coveredFileLines;
    $directories[$directory]['statements'] += $fileStatements;
}

usort($files, function (array $a, array $b): int {
    return $a['percent'] <=> $b['percent']
        ?: $b['statements'] <=> $a['statements'];
});

$directoryRows = [];
foreach ($directories as $directory => $directoryMetrics) {
    $directoryRows[] = [
        'directory'  => $directory,
        'covered'    => $directoryMetrics['covered'],
        'statements' => $directoryMetrics['statements'],
        'percent'    => coveragePercent($directoryMetrics['covered'], $directoryMetrics['statements']),
    ];
}

usort($directoryRows, function (array $a, array $b): int {
    return $a['percent'] <=> $b['percent']
        ?: $b['statements'] <=> $a['statements'];
});

printf("Line coverage: %.2f%% (%d/%d statements)\n", coveragePercent($coveredStatements, $statements), $coveredStatements, $statements);
printf("Method coverage: %.2f%% (%d/%d methods)\n", coveragePercent($coveredMethods, $methods), $coveredMethods, $methods);
printf("%s: %.2f%% (%d/%d classes%s)\n", $classCoverageLabel, coveragePercent($coveredClasses, $classes), $coveredClasses, $classes, $classCoverageNote);
if ($touchedClasses !== null) {
    printf("Touched class coverage: %.2f%% (%d/%d classes executed at least once)\n", coveragePercent($touchedClasses, $classes), $touchedClasses, $classes);
}

echo "\nLowest covered directories:\n";
foreach (array_slice($directoryRows, 0, 10) as $row) {
    printf("  %6.2f%%  %5d/%-5d  %s\n", $row['percent'], $row['covered'], $row['statements'], $row['directory']);
}

echo "\nLowest covered files:\n";
foreach (array_slice($files, 0, 20) as $file) {
    $relativePath = preg_replace('#^' . preg_quote(getcwd(), '#') . '/?#', '', $file['path']);
    printf("  %6.2f%%  %5d/%-5d  %s\n", $file['percent'], $file['covered'], $file['statements'], $relativePath ?: $file['path']);
}

// Enforced after the listings above so a failing build still shows which files to look at.
$enforced = [
    'Line coverage'                => coveragePercent($coveredStatements, $statements),
    'Method coverage'              => coveragePercent($coveredMethods, $methods),
    'Fully covered class coverage' => coveragePercent($coveredClasses, $classes),
];

$shortfalls = array_filter($enforced, static fn (float $percent): bool => $percent < $minimumCoverage);

if ($shortfalls === []) {
    printf("\nCoverage threshold met: every enforced metric is at or above %.2f%%.\n", $minimumCoverage);
    exit(0);
}

fwrite(STDERR, sprintf("\nCoverage threshold not met (minimum %.2f%%):\n", $minimumCoverage));

foreach ($shortfalls as $metric => $percent) {
    fwrite(STDERR, sprintf("  %s is %.2f%%, short by %.2f%%.\n", $metric, $percent, $minimumCoverage - $percent));
}

fwrite(STDERR, "\nSee the \"Lowest covered files\" list above for where to add tests. If a statement is\n");
fwrite(STDERR, "genuinely unreachable or defensive, wrap it in a narrow @codeCoverageIgnoreStart/End block\n");
fwrite(STDERR, "with a comment explaining why.\n");

exit(1);
