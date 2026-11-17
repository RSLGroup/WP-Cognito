<?php
/** Run after composer install in the plugin directory. Optional argument: release tag. */
$root = dirname(__DIR__);
$plugin = $root . '/wp-content/plugins/wp-cognito-sso';
$source = file_get_contents($plugin . '/wp-cognito-sso.php');
preg_match('/^ \* Version: (\d+\.\d+\.\d+)\r?$/m', $source, $match);
$version = $match[1] ?? '';
$tag = $argv[1] ?? 'v' . $version;
if (!$version || !in_array($tag, [$version, 'v' . $version], true)) {
    fwrite(STDERR, "Release tag must match the plugin Version header.\n");
    exit(1);
}
if (!preg_match('/^Stable tag: ' . preg_quote($version, '/') . '\r?$/m', file_get_contents($plugin . '/readme.txt'))) {
    fwrite(STDERR, "readme.txt Stable tag must match the plugin Version header.\n");
    exit(1);
}
if (!file_exists($plugin . '/vendor/autoload.php')) {
    fwrite(STDERR, "Install production Composer dependencies before building a release.\n");
    exit(1);
}
if (!is_dir($root . '/dist')) {
    mkdir($root . '/dist', 0755, true);
}
$path = $root . '/dist/wp-cognito-sso.zip';
$zip = new ZipArchive();
if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Cannot create release ZIP.');
}
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($plugin, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if (!$file->isFile() || $file->isLink()) {
        continue;
    }
    $relative = substr($file->getPathname(), strlen($plugin) + 1);
    if (preg_match('~(^|/)(\.git|\.DS_Store)(/|$)~', $relative)) {
        continue;
    }
    if (!$zip->addFile($file->getPathname(), 'wp-cognito-sso/' . $relative)) {
        throw new RuntimeException('Cannot add release file: ' . $relative);
    }
}
if (!$zip->close()) {
    throw new RuntimeException('Cannot finish release ZIP.');
}
echo "Built {$path} ({$version})\n";
