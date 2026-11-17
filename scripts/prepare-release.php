<?php
/** Create an idempotent release commit and tag without writing to main. */
function wcsso_release_git($root, array $args) {
    $command = implode(' ', array_map('escapeshellarg', array_merge(['git', '-C', $root], $args)));
    exec($command . ' 2>&1', $output, $status);
    if ($status !== 0) {
        throw new RuntimeException(implode("\n", $output));
    }
    return trim(implode("\n", $output));
}

function wcsso_prepare_release($root) {
    if (wcsso_release_git($root, ['status', '--porcelain', '--untracked-files=no']) !== '') {
        throw new RuntimeException('Release preparation requires a clean checkout.');
    }
    $source = wcsso_release_git($root, ['rev-parse', 'HEAD']);
    $marker = 'WP Cognito SSO release from ' . $source;
    $tags = preg_split('/\R/', wcsso_release_git($root, ['tag', '--list', 'v*']));
    $plugin_path = 'wp-content/plugins/wp-cognito-sso/wp-cognito-sso.php';
    $readme_path = 'wp-content/plugins/wp-cognito-sso/readme.txt';
    $plugin = file_get_contents($root . '/' . $plugin_path);
    $readme = file_get_contents($root . '/' . $readme_path);
    if (!preg_match('/^ \* Version: (\d+\.\d+\.\d+)\r?$/m', $plugin, $match)) {
        throw new RuntimeException('Plugin Version must use major.minor.patch.');
    }
    $latest = $match[1];
    if (!preg_match('/^Stable tag: ' . preg_quote($latest, '/') . '\r?$/m', $readme)) {
        throw new RuntimeException('Plugin Version and readme Stable tag must match.');
    }
    foreach ($tags as $tag) {
        if (!preg_match('/^v(\d+\.\d+\.\d+)$/D', $tag, $match)) {
            continue;
        }
        $subject = wcsso_release_git($root, ['for-each-ref', '--format=%(contents:subject)', 'refs/tags/' . $tag]);
        if ($subject === $marker) {
            // A prior attempt already pushed this tag; build its exact contents again.
            wcsso_release_git($root, ['checkout', '--detach', $tag]);
            return $tag;
        }
        if (version_compare($match[1], $latest, '>')) {
            $latest = $match[1];
        }
    }
    $parts = explode('.', $latest);
    $parts[2] = (int) $parts[2] + 1;
    $version = implode('.', $parts);
    $tag = 'v' . $version;
    $plugin = preg_replace('/^( \* Version: )\d+\.\d+\.\d+(\r?)$/m', '${1}' . $version . '${2}', $plugin, 1);
    $readme = preg_replace('/^(Stable tag: )\d+\.\d+\.\d+(\r?)$/m', '${1}' . $version . '${2}', $readme, 1);
    $readme = preg_replace('/^(== Changelog ==\r?\n)/m', '$1' . "\n= {$version} =\n* Automated release from main.\n", $readme, 1);
    wcsso_release_git($root, ['checkout', '--detach', $source]);
    file_put_contents($root . '/' . $plugin_path, $plugin);
    file_put_contents($root . '/' . $readme_path, $readme);
    wcsso_release_git($root, ['add', '--', $plugin_path, $readme_path]);
    $identity = ['-c', 'user.name=github-actions[bot]', '-c', 'user.email=41898282+github-actions[bot]@users.noreply.github.com'];
    wcsso_release_git($root, array_merge($identity, ['commit', '-m', 'Release ' . $tag]));
    wcsso_release_git($root, array_merge($identity, ['tag', '-a', $tag, '-m', $marker]));
    return $tag;
}

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        echo wcsso_prepare_release(dirname(__DIR__)) . "\n";
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(1);
    }
}
