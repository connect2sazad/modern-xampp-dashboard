<?php
require_once __DIR__ . '/dashboard_lib.php';

function dashGitOrigin(string $path): string {
    if (!function_exists('exec')) throw new RuntimeException('Git is unavailable in Apache.');
    $prefix = 'git -C ' . escapeshellarg($path) . ' ';
    $discard = DIRECTORY_SEPARATOR === '\\' ? ' 2>NUL' : ' 2>/dev/null';
    exec($prefix . 'rev-parse --is-inside-work-tree' . $discard, $check, $status);
    if ($status !== 0 || trim(implode('', $check)) !== 'true') throw new RuntimeException('Git could not read this repository.');
    exec($prefix . 'config --local --get-all remote.origin.url' . $discard, $output, $status);
    if ($status === 1) return '';
    if ($status !== 0) throw new RuntimeException('Git could not read the origin.');
    return trim($output[0] ?? '');
}

function dashInitializeProjectGit(string $path, string $defaultBranch): void {
    $branch = $defaultBranch === 'master' ? 'master' : 'main';
    dashExec('git -C ' . escapeshellarg($path) . ' init -b ' . $branch);
}

function dashDisplayOrigin(string $origin): string {
    if (!str_contains($origin, '://')) return $origin;
    $url = parse_url($origin);
    if ($url === false || !isset($url['host'])) return '';
    $user = ($url['scheme'] ?? '') === 'ssh' && isset($url['user']) ? $url['user'] . '@' : '';
    return $url['scheme'] . '://' . $user . $url['host']
        . (isset($url['port']) ? ':' . $url['port'] : '') . ($url['path'] ?? '');
}

function dashProjectColor($value, string $default = ''): string {
    return is_string($value) && preg_match('/\A#[0-9a-f]{6}\z/i', $value) ? strtolower($value) : $default;
}
