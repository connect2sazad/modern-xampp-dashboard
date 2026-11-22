<?php
require_once __DIR__ . '/dashboard_lib.php';

$pages = json_decode(file_get_contents(__DIR__ . '/docs/pages.json'), true, 512, JSON_THROW_ON_ERROR);
$page = $_GET['page'] ?? 'overview';
$valid = is_string($page) && isset($pages[$page]);
$title = $valid ? $pages[$page]['title'] : 'Page not found';
$fragment = $valid ? __DIR__ . '/docs/html/' . $page . '.html' : null;
if (!$valid) http_response_code(404);
elseif (!is_file($fragment)) http_response_code(503);

function docEscape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="en" <?= dashThemeAttributes() ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= docEscape($title) ?> · Dashboard Docs</title>
    <link rel="stylesheet" href="docs/docs.css">
    <link rel="stylesheet" href="themes.css">
</head>
<body>
    <header class="docs-header">
        <div class="docs-header-inner">
            <a class="docs-brand" href="docs.php">Dashboard Docs</a>
            <a class="dashboard-link" href="index.php">← Back to Dashboard</a>
        </div>
    </header>
    <div class="docs-layout">
        <nav class="docs-nav" aria-label="Documentation">
            <p>Guides</p>
            <?php foreach ($pages as $id => $guide): ?>
                <a href="docs.php?page=<?= rawurlencode($id) ?>" <?= $page === $id ? 'aria-current="page"' : '' ?>><?= docEscape($guide['title']) ?></a>
            <?php endforeach; ?>
        </nav>
        <main class="docs-content">
            <?php if ($fragment !== null && is_file($fragment)): ?>
                <?php readfile($fragment); ?>
            <?php elseif (!$valid): ?>
                <h1>Page not found</h1>
                <p>Choose a guide from the navigation or return to the <a href="docs.php">overview</a>.</p>
            <?php else: ?>
                <h1>Documentation unavailable</h1>
                <p>Run the documentation build before opening this guide.</p>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
