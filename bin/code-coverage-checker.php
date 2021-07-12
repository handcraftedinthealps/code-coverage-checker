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

    if (\method_exists($pathReport, 'getNumExecutableLines')) {
        // PHPUNIT <= 8
        $pathNumberOfExecutableLines = $pathReport->getNumExecutableLines();
        $pathNumberOfExecutedLines = $pathReport->getNumExecutedLines();
        $pathPercentageOfExecutedLines = $pathReport->getLineExecutedPercent();
        $pathPercentageOfTestedMethods = $pathReport->getTestedMethodsPercent();
        $pathPercentageOfTestedClasses = $pathReport->getTestedClassesPercent();
    } else {
        // PHPUNIT 9
        $pathNumberOfExecutableLines = $pathReport->numberOfExecutableLines();
        $pathNumberOfExecutedLines = $pathReport->numberOfExecutedLines();
        $pathPercentageOfExecutedLines = $pathReport->percentageOfExecutedLines()->asFloat();
        $pathPercentageOfTestedMethods = $pathReport->percentageOfTestedMethods()->asFloat();
        $pathPercentageOfTestedClasses = $pathReport->percentageOfTestedClasses()->asFloat();
    }

    $totalExecutableLines = $totalExecutableLines + $pathNumberOfExecutableLines;
    $totalCoveredLines = $totalCoveredLines + $pathNumberOfExecutedLines;

    if ('line' === $metric) {
        $reportedCoverage = $pathPercentageOfExecutedLines;
    } elseif ('method' === $metric) {
        $reportedCoverage = $pathPercentageOfTestedMethods;
    } elseif ('class' === $metric) {
        $reportedCoverage = $pathPercentageOfTestedClasses;
    } else {
        $io->error('Coverage metric "' . $metric . '"" is not supported yet.');

        return 1;
    }

    $reportedCoverage = (float) $reportedCoverage;

    if ($reportedCoverage < $threshold) {
        $io->error(sprintf(
            'Code Coverage for metric "%s" and path "%s" is below threshold of %.2F%%.',
            $metric,
            $path,
            $threshold
        ));
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


    if (\method_exists($pathReport, 'getNumExecutableLines')) {
        // PHPUNIT <= 8
        $pathNumberOfExecutableLines = $pathReport->getNumExecutableLines();
        $pathNumberOfExecutedLines = $pathReport->getNumExecutedLines();
        $pathPercentageOfExecutedLines = $pathReport->getLineExecutedPercent();
        $pathPercentageOfTestedMethods = $pathReport->getTestedMethodsPercent();
        $pathNumberOfTestedMethods = $pathReport->getNumTestedMethods();
        $pathNumberOfMethods = $pathReport->getNumMethods();
        $pathPercentageOfTestedClasses = $pathReport->getTestedClassesPercent();
        $pathNumberOfTestedClasses = $pathReport->getNumTestedClasses();
        $pathNumberOfClasses = $pathReport->getNumClasses();
    } else {
        // PHPUNIT 9
        $pathNumberOfExecutableLines = $pathReport->numberOfExecutableLines();
        $pathNumberOfExecutedLines = $pathReport->numberOfExecutedLines();
        $pathPercentageOfExecutedLines = $pathReport->percentageOfExecutedLines()->asFloat();
        $pathPercentageOfTestedMethods = $pathReport->percentageOfTestedMethods()->asFloat();
        $pathNumberOfTestedMethods = $pathReport->numberOfTestedMethods();
        $pathNumberOfMethods = $pathReport->numberOfMethods();
        $pathPercentageOfTestedClasses = $pathReport->percentageOfTestedClasses()->asFloat();
        $pathNumberOfTestedClasses = $pathReport->numberOfTestedClasses();
        $pathNumberOfClasses = $pathReport->numberOfClasses();
    }

    $table->setHeaders(['Coverage Metric', 'Relative Coverage', 'Absolute Coverage']);
    $table->addRow([
        'Line Coverage',
        sprintf('%.2F%%', $pathPercentageOfExecutedLines),
        sprintf('%d/%d', $pathNumberOfExecutedLines, $pathNumberOfExecutableLines),
    ]);
    $table->addRow([
        'Method Coverage',
        sprintf('%.2F%%', $pathPercentageOfTestedMethods),
        sprintf('%d/%d', $pathNumberOfTestedMethods, $pathNumberOfMethods),
    ]);
    $table->addRow([
        'Class Coverage',
        sprintf('%.2F%%', $pathPercentageOfTestedClasses),
        sprintf('%d/%d', $pathNumberOfTestedClasses, $pathNumberOfClasses),
    ]);

    if (\method_exists($pathReport, 'getPath')) {
        // PHPUNIT <= 8
        $path = $pathReport->getPath();
    } else {
        // PHPUNIT 9
        $path = $pathReport->pathAsString();
    }

    $io->title('Code coverage report for directory "' . $path . '"');
    $table->render();
    $io->newLine(1);
}

/**
 * @return Directory|File|null
 */
function getReportForPath(Directory $rootReport, string $path)
{
    $currentPath = getcwd() . DIRECTORY_SEPARATOR . $path;

    if (\method_exists($rootReport, 'getPath')) {
        // PHPUNIT <= 8
        $rootPath = $rootReport->getPath();
    } else {
        // PHPUNIT 9
        $rootPath = $rootReport->pathAsString();
    }

    if (0 === mb_strpos($rootPath, $currentPath)) {
        return $rootReport;
    }

    /** @var Directory $report */
    foreach ($rootReport as $report) {
        if (\method_exists($report, 'getPath')) {
            // PHPUNIT <= 8
            $path = $report->getPath();
        } else {
            // PHPUNIT 9
            $path = $report->pathAsString();
        }

        if (0 === mb_strpos($path, $currentPath)) {
            return $report;
        }
    }

    return null;
}
