<?php

declare(strict_types=1);

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Node\Directory;
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

    $phpunitBridgeDirectory = dirname(realpath($autoloaderFile)) . '/bin/.phpunit';

    if (is_dir($phpunitBridgeDirectory)) {
        $files = scandir($phpunitBridgeDirectory);

        foreach ($files as $file) {
            $phpunitAutoloader = $phpunitBridgeDirectory . '/' . $file . '/vendor/autoload.php';

            if (
                '.' !== $file
                && '..' !== $file
                && file_exists($phpunitAutoloader)
            ) {
                require $phpunitAutoloader;
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
    /** @var Directory $report */
    foreach ($coverage->getReport() as $report) {
        if (is_dir($report->getPath()) && dirname($report->getPath()) === getcwd()) {
            $paths[] = basename($report->getPath());
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
    $totalCoveredLines / $totalExecutableLines * 100,
    $totalCoveredLines,
    $totalExecutableLines,
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
    $totalExecutableLines = $totalExecutableLines + $pathReport->getNumExecutableLines();
    $totalCoveredLines = $totalCoveredLines + $pathReport->getNumExecutedLines();

    if ('line' === $metric) {
        $reportedCoverage = $pathReport->getLineExecutedPercent();
    } elseif ('method' === $metric) {
        $reportedCoverage = $pathReport->getTestedMethodsPercent();
    } elseif ('class' === $metric) {
        $reportedCoverage = $pathReport->getTestedClassesPercent();
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

function printCodeCoverageReport(Directory $pathReport): void
{
    global $io;

    $rightAlignedTableStyle = new TableStyle();
    $rightAlignedTableStyle->setPadType(STR_PAD_LEFT);

    $table = new Table($io);
    $table->setColumnWidth(0, 20);
    $table->setColumnStyle(1, $rightAlignedTableStyle);
    $table->setColumnStyle(2, $rightAlignedTableStyle);

    $table->setHeaders(['Coverage Metric', 'Relative Coverage', 'Absolute Coverage']);
    $table->addRow([
        'Line Coverage',
        sprintf('%.2F%%', $pathReport->getLineExecutedPercent()),
        sprintf('%d/%d', $pathReport->getNumExecutedLines(), $pathReport->getNumExecutableLines()),
    ]);
    $table->addRow([
        'Method Coverage',
        sprintf('%.2F%%', $pathReport->getTestedMethodsPercent()),
        sprintf('%d/%d', $pathReport->getNumTestedMethods(), $pathReport->getNumMethods()),
    ]);
    $table->addRow([
        'Class Coverage',
        sprintf('%.2F%%', $pathReport->getTestedClassesPercent()),
        sprintf('%d/%d', $pathReport->getNumTestedClasses(), $pathReport->getNumClasses()),
    ]);

    $io->title('Code coverage report for directory "' . $pathReport->getPath() . '"');
    $table->render();
    $io->newLine(1);
}

function getReportForPath(Directory $rootReport, string $path): ?Directory
{
    $currentPath = getcwd() . DIRECTORY_SEPARATOR . $path;

    /** @var Directory $report */
    foreach ($rootReport as $report) {
        if (0 === mb_strpos($report->getPath(), $currentPath)) {
            return $report;
        }
    }

    return null;
}
