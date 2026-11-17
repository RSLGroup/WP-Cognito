<?php
/** Exercise release preparation against a disposable Git repository. */
require dirname(__DIR__) . '/scripts/prepare-release.php';
function release_expect($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function release_cleanup($path) {
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            release_cleanup($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($path);
}
$root = sys_get_temp_dir() . '/wcsso-release-test-' . bin2hex(random_bytes(8));
mkdir($root . '/wp-content/plugins/wp-cognito-sso', 0755, true);
try {
    $plugin_path = $root . '/wp-content/plugins/wp-cognito-sso/wp-cognito-sso.php';
    $readme_path = $root . '/wp-content/plugins/wp-cognito-sso/readme.txt';
    file_put_contents($plugin_path, "<?php\n/**\n * Version: 1.0.10\n */\n");
    file_put_contents($readme_path, "Stable tag: 1.0.10\n\n== Changelog ==\n\n= 1.0.10 =\n* Previous changes.\n");
    wcsso_release_git($root, ['init', '-b', 'main']);
    wcsso_release_git($root, ['config', 'user.name', 'Release Test']);
    wcsso_release_git($root, ['config', 'user.email', 'test@example.com']);
    wcsso_release_git($root, ['add', '.']);
    wcsso_release_git($root, ['commit', '-m', 'Merged plugin changes']);
    $source = wcsso_release_git($root, ['rev-parse', 'HEAD']);
    release_expect(wcsso_prepare_release($root) === 'v1.0.11', 'First release must increment the patch version.');
    release_expect(wcsso_release_git($root, ['rev-parse', 'main']) === $source, 'Release must not move main.');
    release_expect(wcsso_release_git($root, ['rev-parse', 'HEAD^']) === $source, 'Release commit must derive from the triggering source.');
    release_expect(strpos(file_get_contents($plugin_path), 'Version: 1.0.11') !== false, 'Release plugin must contain new version.');
    release_expect(strpos(file_get_contents($readme_path), 'Stable tag: 1.0.11') !== false, 'Release stable tag must match.');
    release_expect(strpos(file_get_contents($readme_path), '= 1.0.10 =') !== false, 'Existing changelog entries must be preserved.');
    $release_commit = wcsso_release_git($root, ['rev-parse', 'HEAD']);
    wcsso_release_git($root, ['checkout', 'main']);
    release_expect(wcsso_prepare_release($root) === 'v1.0.11', 'Retry must reuse its release tag.');
    release_expect(wcsso_release_git($root, ['rev-parse', 'HEAD']) === $release_commit, 'Retry must use the original release commit.');
    wcsso_release_git($root, ['checkout', 'main']);
    wcsso_release_git($root, ['commit', '--allow-empty', '-m', 'Next merge']);
    release_expect(wcsso_prepare_release($root) === 'v1.0.12', 'Next merge must advance past the previous release even if main version is unchanged.');
    wcsso_release_git($root, ['checkout', 'main']);
    wcsso_release_git($root, ['tag', 'v1.9.9']);
    wcsso_release_git($root, ['tag', 'v99.0.0-beta']);
    wcsso_release_git($root, ['commit', '--allow-empty', '-m', 'Another merge']);
    release_expect(wcsso_prepare_release($root) === 'v1.9.10', 'Version ordering must be numeric and prerelease tags must be ignored.');
    wcsso_release_git($root, ['checkout', 'main']);
    file_put_contents($readme_path, "Stable tag: 2.0.0\n");
    try {
        wcsso_prepare_release($root);
        throw new LogicException('Dirty source must fail.');
    } catch (RuntimeException $error) {
        release_expect(strpos($error->getMessage(), 'clean checkout') !== false, 'Dirty source must fail clearly.');
    }
    wcsso_release_git($root, ['add', '.']);
    wcsso_release_git($root, ['commit', '-m', 'Mismatched headers']);
    try {
        wcsso_prepare_release($root);
        throw new LogicException('Mismatched headers must fail.');
    } catch (RuntimeException $error) {
        release_expect(strpos($error->getMessage(), 'must match') !== false, 'Mismatched headers must fail clearly.');
    }
    echo "Release preparation integration tests passed.\n";
} finally {
    release_cleanup($root);
}
