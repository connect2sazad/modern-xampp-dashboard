# Requires PowerShell 7.
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$pages = Get-Content (Join-Path $PSScriptRoot 'pages.json') -Raw | ConvertFrom-Json -AsHashtable
$output = Join-Path $PSScriptRoot 'html'
New-Item -ItemType Directory -Path $output -Force | Out-Null

$sourceRoutes = @{}
foreach ($id in $pages.Keys) {
    $source = [IO.Path]::GetFullPath((Join-Path $root $pages[$id].source))
    $sourceRoutes[$source] = $id
}

foreach ($id in $pages.Keys) {
    $source = [IO.Path]::GetFullPath((Join-Path $root $pages[$id].source))
    $html = (ConvertFrom-Markdown -LiteralPath $source).Html
    $html = [regex]::Replace($html, 'href="([^"]+)"', {
        param($match)

        $target = [Net.WebUtility]::HtmlDecode($match.Groups[1].Value)
        if ($target -notmatch '(?i)\.md(?:#.*)?$' -or $target -match '^(?:[a-z][a-z0-9+.-]*:|//)') {
            return $match.Value
        }
        $file, $anchor = $target.Split('#', 2)
        $resolved = [IO.Path]::GetFullPath((Join-Path (Split-Path -Parent $source) ([uri]::UnescapeDataString($file))))
        if (!$sourceRoutes.ContainsKey($resolved)) {
            throw "No HTML documentation route for '$target' in '$source'."
        }
        $url = 'docs.php?page=' + $sourceRoutes[$resolved]
        if ($anchor) { $url += '#' + $anchor }
        'href="' + [Net.WebUtility]::HtmlEncode($url) + '"'
    })
    Set-Content (Join-Path $output ($id + '.html')) ("<!-- Generated from " + $pages[$id].source + " by docs/build.ps1. -->`n" + $html.TrimEnd() + "`n") -Encoding utf8 -NoNewline
}
