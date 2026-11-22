<?php
require_once __DIR__ . '/dashboard_lib.php';

const DASH_CMS = [
    'wordpress' => ['WordPress', 'zip', 'https://wordpress.org/latest.zip', '', 'wordpress'],
    'joomla' => ['Joomla', 'release', 'joomla/joomla-cms', '', '~Stable-Full_Package\.zip$~i'],
    'grav' => ['Grav', 'release', 'getgrav/grav', '', '~^grav-v?[0-9][0-9.]*\.zip$~i', 'grav'],
    'drupal' => ['Drupal', 'composer', 'drupal/recommended-project', 'web'],
    'drupal-cms' => ['Drupal CMS', 'composer', 'drupal/cms', 'web'],
    'craft' => ['Craft CMS', 'composer', 'craftcms/craft', 'web'],
    'kirby' => ['Kirby Starterkit', 'composer', 'getkirby/starterkit', ''],
    'statamic' => ['Statamic', 'composer', 'statamic/statamic', 'public'],
    'concrete' => ['Concrete CMS', 'composer', 'concretecms/composer', 'public'],
    'silverstripe' => ['Silverstripe CMS', 'composer', 'silverstripe/installer', 'public'],
    'october' => ['October CMS', 'composer', 'october/october', ''],
    'typo3' => ['TYPO3', 'composer', 'typo3/cms-base-distribution', 'public'],
    'processwire' => ['ProcessWire', 'git', 'https://github.com/processwire/processwire.git', ''],
    'backdrop' => ['Backdrop CMS', 'git', 'https://github.com/backdrop/backdrop.git', ''],
];

function dashTemplates(): array {
    $catalog = [];
    foreach (DASH_CMS as $id => [$label, $method, $source, $directory]) {
        $catalog[$id] = compact('label', 'method', 'source', 'directory') + ['category' => 'cms'];
        if ($method === 'zip') $catalog[$id]['wrapper'] = DASH_CMS[$id][4];
        if ($method === 'release') {
            $catalog[$id]['asset'] = DASH_CMS[$id][4];
            $catalog[$id]['wrapper'] = DASH_CMS[$id][5] ?? '';
        }
    }
    return $catalog + (dashJson('settings')['custom_templates'] ?? []);
}

function dashTemplateFields(array $input): array {
    $label = trim($input['label'] ?? '');
    $category = $input['category'] ?? '';
    $method = $input['method'] ?? '';
    $source = trim($input['source'] ?? '');
    $directory = trim($input['directory'] ?? '');
    $version = trim($input['version'] ?? '');
    if ($label === '' || strlen($label) > 80 || !in_array($category, ['cms', 'framework'], true)) {
        throw new RuntimeException('Enter a name and choose CMS or Framework.');
    }
    if ($method === 'git') {
        if (!dashRemote($source)) throw new RuntimeException('Enter an HTTPS Git repository URL without credentials.');
        if ($version !== '' && !preg_match('~\A[A-Za-z0-9][A-Za-z0-9._/-]{0,127}\z~', $version)) {
            throw new RuntimeException('Enter a valid Git branch or tag.');
        }
    } elseif ($method === 'composer') {
        if (!preg_match('~\A[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*\z~', $source)
            || ($version !== '' && !preg_match('#\A[0-9A-Za-z~^<>=.*_+|, /-]{1,100}\z#', $version))) {
            throw new RuntimeException('Enter a Composer package such as vendor/project and an optional version constraint.');
        }
    } else {
        throw new RuntimeException('Choose Git or Composer.');
    }
    $directory = trim(str_replace('\\', '/', $directory), '/');
    if ($directory === '.') $directory = '';
    if ($directory !== '') {
        dashPathParts($directory);
        if (!preg_match('~\A[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*\z~', $directory)) {
            throw new RuntimeException('Use a relative public folder such as public or frontend/web.');
        }
    }
    $installDependencies = $method === 'git' && ($input['install_dependencies'] ?? '') === '1';
    return compact('label', 'category', 'method', 'source', 'directory', 'version', 'installDependencies');
}

function dashTemplateHttp(string $url, $stream, int $limit): void {
    if (!function_exists('curl_init')) throw new RuntimeException('Enable PHP cURL to download templates.');
    $curl = curl_init($url);
    $size = 0;
    curl_setopt_array($curl, [
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 240, CURLOPT_FAILONERROR => true, CURLOPT_USERAGENT => 'XAMPP Dashboard',
        CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($stream, $limit, &$size) {
            $size += strlen($chunk);
            return $size > $limit ? 0 : fwrite($stream, $chunk);
        },
    ]);
    try {
        if (!curl_exec($curl)) throw new RuntimeException($size > $limit ? 'Template download exceeds its size limit.' : 'Could not download template: ' . curl_error($curl));
    } finally {
        curl_close($curl);
    }
}

function dashTemplateRelease(array $template): string {
    $metadata = fopen('php://temp', 'w+b');
    try {
        dashTemplateHttp('https://api.github.com/repos/' . $template['source'] . '/releases/latest', $metadata, 1024 * 1024);
        rewind($metadata);
        $release = json_decode(stream_get_contents($metadata), true, 512, JSON_THROW_ON_ERROR);
        foreach ($release['assets'] ?? [] as $asset) {
            $url = $asset['browser_download_url'] ?? '';
            if (preg_match($template['asset'], $asset['name'] ?? '')
                && str_starts_with($url, 'https://github.com/' . $template['source'] . '/releases/download/')) return $url;
        }
        throw new RuntimeException('The latest release does not contain the expected installation ZIP.');
    } finally {
        fclose($metadata);
    }
}

function dashInstallTemplate(array $template, string $destination): void {
    $version = $template['version'] ?? '';
    $label = $template['label'];
    switch ($template['method']) {
        case 'git':
            $command = ['git', 'clone', '--progress', '--depth', '1'];
            if ($version !== '') array_push($command, '--branch', $version);
            array_push($command, '--', $template['source'], $destination);
            runJob($command, 'Cloning ' . $label);
            if (!empty($template['installDependencies'])) {
                if (!is_file(dashFilePath($destination, 'composer.json'))) {
                    throw new RuntimeException('The repository has no composer.json for dependency installation.');
                }
                runJob(['composer', 'install', '--no-interaction', '--prefer-dist'], 'Installing Composer dependencies', $destination);
            }
            return;
        case 'composer':
            $command = ['composer', 'create-project', '--no-interaction', '--prefer-dist', '--', $template['source'], $destination];
            if ($version !== '') $command[] = $version;
            runJob($command, 'Installing ' . $label);
            return;
        case 'release':
        case 'zip':
            emitJob('stage', 'Downloading', 'Downloading ' . $label . '…');
            $url = $template['method'] === 'release' ? dashTemplateRelease($template) : $template['source'];
            $file = tempnam(DASH_DATA, 'template_');
            if ($file === false) throw new RuntimeException('Could not stage the template ZIP.');
            try {
                $output = fopen($file, 'wb');
                if ($output === false) throw new RuntimeException('Could not open the template ZIP.');
                try {
                    dashTemplateHttp($url, $output, 100 * 1024 * 1024);
                } finally {
                    fclose($output);
                }
                emitJob('stage', 'Extracting', 'Extracting ' . $label . '…');
                dashRestore($file, $destination, $template['wrapper'] ?? '');
            } finally {
                @unlink($file);
            }
            return;
        default:
            throw new RuntimeException('Unsupported template source.');
    }
}
