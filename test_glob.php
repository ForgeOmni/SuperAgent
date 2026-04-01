<?php

require __DIR__ . '/vendor/autoload.php';

use SuperAgent\Tools\Builtin\GlobTool;

// Create test directory
$testDir = sys_get_temp_dir() . '/glob_test_' . uniqid();
mkdir($testDir, 0755, true);

// Create test files
file_put_contents($testDir . '/file1.txt', 'test');
file_put_contents($testDir . '/file2.php', '<?php');
mkdir($testDir . '/subdir');
file_put_contents($testDir . '/subdir/file3.php', '<?php');

$tool = new GlobTool();

// First test what files exist
echo "Files in test directory:\n";
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($testDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($iterator as $file) {
    if ($file->isFile()) {
        echo "  " . $file->getPathname() . "\n";
    }
}

// Test glob pattern generation
$pattern = '**/*.php';
$regex = '/^(.*/)?[^/]*\\.php$/';
echo "\nPattern: $pattern\n";
echo "Expected regex: $regex\n";

// Test recursive glob
echo "\nTesting **/*.php pattern:\n";
$result = $tool->execute([
    'pattern' => '**/*.php',
    'path' => $testDir,
]);
echo $result->contentAsString() . "\n\n";

// Clean up
exec("rm -rf " . escapeshellarg($testDir));