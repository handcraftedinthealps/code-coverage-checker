<?php

declare(strict_types=1);

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Node\Directory;
use SebastianBergmann\CodeCoverage\Node\File;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableStyle;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

// Autoloader
$autoloaderFiles = [__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php'];

foreach ($autoloaderFiles as $autoloaderFile) {
    if (!file_exists($autoloaderFile)) {
        continue;
    }

    $loader = require $autoloaderFile;

    $phpunitBridgeDirectories = [
        dirname(realpath($autoloaderFile)) . '/bin/.phpunit',
        dirname(dirname(realpath($autoloaderFile))) . '/bin/.phpunit',
    ];

    foreach ($phpunitBridgeDirectories as $phpunitBridgeDirectory) {
        if (!is_dir($phpunitBridgeDirectory)) {
            continue;
        }

        $files = scandir($phpunitBridgeDirectory);

        foreach ($files as $file) {
            $phpunitAutoloader = $phpunitBridgeDirectory . '/' . $file . '/vendor/autoload.php';

            if (
                '.' !== $file
                && '..' !== $file
                && file_exists($phpunitAutoloader)
            ) {
                require $phpunitAutoloader;

                break 2;
            }
        }
    }

    break;
}

// construct symfony io object to format output

$inputDefinition = new InputDefinition();
$inputDefinition->addArgument(new InputArgument('coverage-file', InputArgument::REQUIRED));
$inputDefinition->addArgument(new InputArgument('metric', InputArgument::REQUIRED));
$inputDefinition->addArgument(new InputArgument('threshold', InputArgument::REQUIRED));
$inputDefinition->addArgument(new InputArgument('paths', InputArgument::IS_ARRAY));

// Trim any options passed to the command
$argvArguments = explode(' ', explode(' --', implode(' ', $argv))[0]);

$input = new ArgvInput($argvArguments, $inputDefinition);

$io = new SymfonyStyle($input, new ConsoleOutput());

$metric = $input->getArgument('metric');
$threshold = min(100, max(0, (float) $input->getArgument('threshold')));

// load code coverage report
$coverageReportPath = getcwd() . '/' . $input->getArgument('coverage-file');
if (!is_readable($coverageReportPath)) {
    $io->error('Coverage report file "' . $coverageReportPath . '" is not readable or does not exist.');
    exit(1);
}

/** @var CodeCoverage $coverage */
$coverage = require $coverageReportPath;

$paths = $input->getArgument('paths');

// Check all root paths if no paths are given
if (empty($paths)) {
    /** @var Directory|File $report */
    foreach ($coverage->getReport() as $report) {
        if (\method_exists($report, 'getPath')) {
            // PHPUNIT <= 8
            $path = $report->getPath();
        } else {
            // PHPUNIT 9
            $path = $report->pathAsString();
        }

        if (is_dir($path) && dirname($path) === getcwd()) {
            $paths[] = basename($path);
        }
    }
}

$totalExecutableLines = 0;
$totalCoveredLines = 0;
$exit = 0;

foreach ($paths as $path) {
    $exit += assertCodeCoverage($coverage, $path, $metric, $threshold);
}

$message = sprintf(
    'Line Coverage for all included files: %.2F%% (%d/%d).',
    $totalExecutableLines ? $totalCoveredLines / $totalExecutableLines * 100 : 100,
    $totalCoveredLines,
    $totalExecutableLines
);
$io->block($message, 'INFO', 'fg=black;bg=white', ' ', true);

exit($exit);

function assertCodeCoverage(CodeCoverage $coverage, string $path, string $metric, float $threshold)
{
    global $io;
    global $totalExecutableLines;
    global $totalCoveredLines;

    $rootReport = $coverage->getReport();
    $pathReport = getReportForPath($rootReport, $path);

    if (!$pathReport) {
        $io->error('Coverage report for path "' . $path . '" not found.');

        return 1;
    }

    printCodeCoverageReport($pathReport);

    $pathCoverageMetrics = extractCoverageMetrics($pathReport);

    $totalExecutableLines = $totalExecutableLines + $pathCoverageMetrics['line']['total'];
    $totalCoveredLines = $totalCoveredLines + $pathCoverageMetrics['line']['covered'];

    $selectedMetric = getCoverageMetric($pathCoverageMetrics, $metric);
    if (null === $selectedMetric) {
        $io->error('Coverage metric "' . $metric . '"" is not supported yet.');

        return 1;
    }

    $reportedCoverage = $selectedMetric['percent'];

    if ($reportedCoverage < $threshold) {
        $io->error(sprintf(
            'Code Coverage for metric "%s" and path "%s" is below threshold of %.2F%%.',
            $metric,
            $path,
            $threshold
        ));
        printFilesBelowThresholdReport($pathReport, $metric, $threshold);
        $io->newLine(1);

        return 1;
    }

    $io->success(sprintf(
        'Code Coverage for metric "%s" and path "%s" is above threshold of %.2F%%.',
        $metric,
        $path,
        $threshold
    ));
    $io->newLine(1);

    return 0;
}

/**
 * @param Directory|File $pathReport
 */
function printCodeCoverageReport($pathReport): void
{
    global $io;

    $rightAlignedTableStyle = new TableStyle();
    $rightAlignedTableStyle->setPadType(STR_PAD_LEFT);

    $table = new Table($io);
    $table->setColumnWidth(0, 20);
    $table->setColumnStyle(1, $rightAlignedTableStyle);
    $table->setColumnStyle(2, $rightAlignedTableStyle);

    $pathCoverageMetrics = extractCoverageMetrics($pathReport);

    $table->setHeaders(['Coverage Metric', 'Relative Coverage', 'Absolute Coverage']);
    $table->addRow([
        'Line Coverage',
        sprintf('%.2F%%', $pathCoverageMetrics['line']['percent']),
        sprintf('%d/%d', $pathCoverageMetrics['line']['covered'], $pathCoverageMetrics['line']['total']),
    ]);
    $table->addRow([
        'Method Coverage',
        sprintf('%.2F%%', $pathCoverageMetrics['method']['percent']),
        sprintf('%d/%d', $pathCoverageMetrics['method']['covered'], $pathCoverageMetrics['method']['total']),
    ]);
    $table->addRow([
        'Class Coverage',
        sprintf('%.2F%%', $pathCoverageMetrics['class']['percent']),
        sprintf('%d/%d', $pathCoverageMetrics['class']['covered'], $pathCoverageMetrics['class']['total']),
    ]);

    $path = getReportPath($pathReport);

    $io->title('Code coverage report for directory "' . $path . '"');
    $table->render();
    $io->newLine(1);
}

/**
 * @param Directory|File $report
 */
function getReportPath($report): string
{
    if (\method_exists($report, 'getPath')) {
        // PHPUNIT <= 8
        return $report->getPath();
    }

    // PHPUNIT 9
    return $report->pathAsString();
}

/**
 * @param Directory|File $report
 *
 * @return array<string, array{percent: float, covered: int, total: int}>
 */
function extractCoverageMetrics($report): array
{
    if (\method_exists($report, 'getNumExecutableLines')) {
        // PHPUNIT <= 8
        $lineTotal = $report->getNumExecutableLines();
        $lineCovered = $report->getNumExecutedLines();
        $linePercent = $report->getLineExecutedPercent();
        $methodTotal = $report->getNumMethods();
        $methodCovered = $report->getNumTestedMethods();
        $methodPercent = $report->getTestedMethodsPercent();
        $classTotal = $report->getNumClasses();
        $classCovered = $report->getNumTestedClasses();
        $classPercent = $report->getTestedClassesPercent();
    } else {
        // PHPUNIT 9
        $lineTotal = $report->numberOfExecutableLines();
        $lineCovered = $report->numberOfExecutedLines();
        $linePercent = $report->percentageOfExecutedLines()->asFloat();
        $methodTotal = $report->numberOfMethods();
        $methodCovered = $report->numberOfTestedMethods();
        $methodPercent = $report->percentageOfTestedMethods()->asFloat();
        $classTotal = $report->numberOfClasses();
        $classCovered = $report->numberOfTestedClasses();
        $classPercent = $report->percentageOfTestedClasses()->asFloat();
    }

    return [
        'line' => [
            'percent' => (float) $linePercent,
            'covered' => (int) $lineCovered,
            'total' => (int) $lineTotal,
        ],
        'method' => [
            'percent' => (float) $methodPercent,
            'covered' => (int) $methodCovered,
            'total' => (int) $methodTotal,
        ],
        'class' => [
            'percent' => (float) $classPercent,
            'covered' => (int) $classCovered,
            'total' => (int) $classTotal,
        ],
    ];
}

/**
 * @param array<string, array{percent: float, covered: int, total: int}> $coverageMetrics
 *
 * @return array{percent: float, covered: int, total: int}|null
 */
function getCoverageMetric(array $coverageMetrics, string $metric): ?array
{
    if ('line' === $metric || 'method' === $metric || 'class' === $metric) {
        return $coverageMetrics[$metric];
    }

    return null;
}

/**
 * @param Directory|File $pathReport
 */
function printFilesBelowThresholdReport($pathReport, string $metric, float $threshold): void
{
    global $io;

    $failingFiles = deduplicateFailingFilesByPath(collectFilesBelowThreshold($pathReport, $metric, $threshold));
    usort($failingFiles, static function (array $firstFile, array $secondFile): int {
        if ($firstFile['percent'] < $secondFile['percent']) {
            return -1;
        }

        if ($firstFile['percent'] > $secondFile['percent']) {
            return 1;
        }

        return strcmp($firstFile['path'], $secondFile['path']);
    });

    $io->title(sprintf(
        'Files below threshold (metric "%s", threshold %.2F%%)',
        $metric,
        $threshold
    ));

    if (empty($failingFiles)) {
        $io->text('No individual files below threshold were found for this metric.');

        return;
    }

    $rightAlignedTableStyle = new TableStyle();
    $rightAlignedTableStyle->setPadType(STR_PAD_LEFT);

    $table = new Table($io);
    $table->setColumnStyle(1, $rightAlignedTableStyle);
    $table->setColumnStyle(2, $rightAlignedTableStyle);
    $table->setHeaders(['File', 'Relative Coverage', 'Absolute Coverage']);

    foreach ($failingFiles as $failingFile) {
        $table->addRow([
            $failingFile['path'],
            sprintf('%.2F%%', $failingFile['percent']),
            sprintf('%d/%d', $failingFile['covered'], $failingFile['total']),
        ]);
    }

    $table->render();
}

/**
 * @param Directory|File $pathReport
 *
 * @return array<int, array{path: string, percent: float, covered: int, total: int}>
 */
function collectFilesBelowThreshold($pathReport, string $metric, float $threshold): array
{
    if ($pathReport instanceof File) {
        $fileCoverageMetrics = extractCoverageMetrics($pathReport);
        $fileMetric = getCoverageMetric($fileCoverageMetrics, $metric);

        if (null === $fileMetric || $fileMetric['percent'] >= $threshold) {
            return [];
        }

        return [[
            'path' => getRelativePath(getReportPath($pathReport)),
            'percent' => $fileMetric['percent'],
            'covered' => $fileMetric['covered'],
            'total' => $fileMetric['total'],
        ]];
    }

    $failingFiles = [];
    /** @var Directory|File $report */
    foreach ($pathReport as $report) {
        $failingFiles = array_merge(
            $failingFiles,
            collectFilesBelowThreshold($report, $metric, $threshold)
        );
    }

    return $failingFiles;
}

/**
 * @param array<int, array{path: string, percent: float, covered: int, total: int}> $failingFiles
 *
 * @return array<int, array{path: string, percent: float, covered: int, total: int}>
 */
function deduplicateFailingFilesByPath(array $failingFiles): array
{
    $uniqueFailingFiles = [];

    foreach ($failingFiles as $failingFile) {
        $path = $failingFile['path'];

        if (!isset($uniqueFailingFiles[$path])) {
            $uniqueFailingFiles[$path] = $failingFile;

            continue;
        }

        if ($failingFile['percent'] < $uniqueFailingFiles[$path]['percent']) {
            $uniqueFailingFiles[$path] = $failingFile;
        }
    }

    return array_values($uniqueFailingFiles);
}

function getRelativePath(string $path): string
{
    $currentDirectory = getcwd() . DIRECTORY_SEPARATOR;
    if (0 === mb_strpos($path, $currentDirectory)) {
        return mb_substr($path, mb_strlen($currentDirectory));
    }

    return $path;
}

/**
 * @return Directory|File|null
 */
function getReportForPath(Directory $rootReport, string $path)
{
    $currentPath = getcwd() . DIRECTORY_SEPARATOR . $path;

    $rootPath = getReportPath($rootReport);

    if (0 === mb_strpos($rootPath, $currentPath)) {
        return $rootReport;
    }

    /** @var Directory $report */
    foreach ($rootReport as $report) {
        $path = getReportPath($report);

        if (0 === mb_strpos($path, $currentPath)) {
            return $report;
        }
    }

    return null;
}
