<?php

declare(strict_types=1);

/**
 * Ecosystem helpers for tools/check-languages.php.
 *
 * This file is not an independent checker. It only provides remote plugin
 * discovery, structural key parsing and Markdown report generation.
 */

function GL_LANG_githubJson(string $url)
{
    $headers = [
        'Accept: application/vnd.github+json',
        'User-Agent: geeklog-language-checker',
        'X-GitHub-Api-Version: 2022-11-28',
    ];
    $token = getenv('GITHUB_TOKEN');
    if (is_string($token) && $token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $context = stream_context_create([
        'http' => [
            'header' => implode("\r\n", $headers),
            'timeout' => 30,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        throw new RuntimeException("GitHub request failed: {$url}");
    }
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $m)) {
            $status = (int) $m[1];
        }
    }
    if ($status >= 400) {
        throw new RuntimeException("GitHub request failed with HTTP {$status}: {$url}");
    }
    return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
}

function GL_LANG_githubRaw(string $url): string
{
    $headers = ['User-Agent: geeklog-language-checker'];
    $token = getenv('GITHUB_TOKEN');
    if (is_string($token) && $token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $context = stream_context_create([
        'http' => ['header' => implode("\r\n", $headers), 'timeout' => 30],
    ]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        throw new RuntimeException("Unable to download: {$url}");
    }
    return $body;
}

/** @return array<string,true> */
function GL_LANG_parseKeys(string $source): array
{
    $keys = [];
    if (preg_match_all('/\$(LANG[A-Za-z0-9_]+)\s*\[\s*([\'\"])(.*?)\2\s*\]\s*=/', $source, $m, PREG_SET_ORDER)) {
        foreach ($m as $match) {
            $keys[$match[1] . ':' . $match[3]] = true;
        }
    }

    if (!preg_match_all('/\$(LANG[A-Za-z0-9_]+)\s*=\s*(?:array\s*\(|\[)/', $source, $m, PREG_OFFSET_CAPTURE)) {
        return $keys;
    }

    foreach ($m[0] as $idx => $full) {
        $var = $m[1][$idx][0];
        $start = $full[1] + strlen($full[0]) - 1;
        $open = $source[$start];
        $close = $open === '(' ? ')' : ']';
        $depth = 1;
        $quote = null;
        $escape = false;
        $end = null;

        for ($i = $start + 1, $len = strlen($source); $i < $len; ++$i) {
            $ch = $source[$i];
            if ($quote !== null) {
                if ($escape) {
                    $escape = false;
                } elseif ($ch === '\\') {
                    $escape = true;
                } elseif ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"') {
                $quote = $ch;
                continue;
            }
            if ($ch === $open) {
                ++$depth;
            } elseif ($ch === $close) {
                --$depth;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }

        if ($end === null) {
            continue;
        }
        $body = substr($source, $start + 1, $end - $start - 1);
        if (preg_match_all('/(?:^|,)\s*(?:([\'\"])(.*?)\1|(-?\d+))\s*=>/ms', $body, $km, PREG_SET_ORDER)) {
            foreach ($km as $entry) {
                $key = $entry[2] !== '' ? $entry[2] : (string) ((int) $entry[3]);
                $keys[$var . ':' . $key] = true;
            }
        }
    }

    return $keys;
}

function GL_LANG_findFile(array $files, array $prefixes): ?array
{
    $candidates = [];
    foreach ($files as $file) {
        $name = strtolower((string) ($file['name'] ?? ''));
        if (!str_ends_with($name, '.php')) {
            continue;
        }
        $stem = substr($name, 0, -4);
        foreach ($prefixes as $prefix) {
            $prefix = strtolower(str_replace('-', '_', (string) $prefix));
            $normalized = str_replace('-', '_', $stem);
            if ($normalized === $prefix || str_starts_with($normalized, $prefix . '_')) {
                $candidates[] = $file;
                break;
            }
        }
    }
    if ($candidates === []) {
        return null;
    }
    usort($candidates, static function (array $a, array $b): int {
        $an = strtolower((string) $a['name']);
        $bn = strtolower((string) $b['name']);
        $au = str_contains($an, 'utf-8') || str_contains($an, 'utf8') ? 0 : 1;
        $bu = str_contains($bn, 'utf-8') || str_contains($bn, 'utf8') ? 0 : 1;
        return [$au, strlen($an), $an] <=> [$bu, strlen($bn), $bn];
    });
    return $candidates[0];
}

function GL_LANG_ecosystemLanguages(array $recommendations): array
{
    $languages = [
        'fr' => ['name' => 'French', 'prefixes' => ['french_france', 'french'], 'core' => true],
        'de' => ['name' => 'German', 'prefixes' => ['german'], 'core' => true],
        'es' => ['name' => 'Spanish', 'prefixes' => ['spanish'], 'core' => true],
        'it' => ['name' => 'Italian', 'prefixes' => ['italian'], 'core' => true],
        'ja' => ['name' => 'Japanese', 'prefixes' => ['japanese'], 'core' => true],
        'ru' => ['name' => 'Russian', 'prefixes' => ['russian'], 'core' => true],
        'zh_cn' => ['name' => 'Chinese (Simplified)', 'prefixes' => ['chinese_simplified'], 'core' => true],
        'zh_tw' => ['name' => 'Chinese (Traditional)', 'prefixes' => ['chinese_traditional'], 'core' => true],
        'he' => ['name' => 'Hebrew', 'prefixes' => ['hebrew'], 'core' => true],
        'fa' => ['name' => 'Persian', 'prefixes' => ['persian'], 'core' => true],
    ];
    foreach ($recommendations as $code => $def) {
        if (!isset($languages[$code])) {
            $languages[$code] = [
                'name' => (string) ($def['name'] ?? $code),
                'prefixes' => array_values($def['prefixes'] ?? []),
                'core' => false,
            ];
        }
    }
    return $languages;
}

function GL_LANG_auditPlugins(string $org, array $recommendations): array
{
    $repos = GL_LANG_githubJson('https://api.github.com/orgs/' . rawurlencode($org) . '/repos?type=public&per_page=100');
    $langs = GL_LANG_ecosystemLanguages($recommendations);
    $summary = [];
    foreach ($langs as $code => $def) {
        $summary[$code] = ['name' => $def['name'], 'core' => $def['core'], 'complete' => 0, 'partial' => 0, 'missing' => 0, 'present' => 0];
    }

    $plugins = [];
    $audited = 0;

    foreach ($repos as $repo) {
        if (($repo['fork'] ?? false) || ($repo['archived'] ?? false) || ($repo['name'] ?? '') === 'language-audit') {
            continue;
        }
        $name = (string) $repo['name'];
        $branch = (string) ($repo['default_branch'] ?? 'master');
        try {
            $files = GL_LANG_githubJson(
                'https://api.github.com/repos/' . rawurlencode($org) . '/' . rawurlencode($name)
                . '/contents/language?ref=' . rawurlencode($branch)
            );
        } catch (Throwable $e) {
            continue;
        }

        $english = GL_LANG_findFile($files, ['english']);
        if ($english === null || empty($english['download_url'])) {
            continue;
        }
        $reference = GL_LANG_parseKeys(GL_LANG_githubRaw((string) $english['download_url']));
        if ($reference === []) {
            continue;
        }

        ++$audited;
        foreach ($langs as $code => $def) {
            $file = GL_LANG_findFile($files, $def['prefixes']);
            if ($file === null || empty($file['download_url'])) {
                $plugins[$name][$code] = ['state' => 'missing', 'missing' => count($reference), 'coverage' => 0.0];
                ++$summary[$code]['missing'];
                continue;
            }

            ++$summary[$code]['present'];
            $translated = GL_LANG_parseKeys(GL_LANG_githubRaw((string) $file['download_url']));
            $missing = count(array_diff_key($reference, $translated));
            $coverage = count($reference) > 0 ? ((count($reference) - $missing) / count($reference)) * 100 : 100.0;
            $state = $missing === 0 ? 'complete' : 'partial';
            ++$summary[$code][$state];
            $plugins[$name][$code] = ['state' => $state, 'missing' => $missing, 'coverage' => $coverage];
        }
    }

    return ['organization' => $org, 'audited' => $audited, 'languages' => $summary, 'plugins' => $plugins];
}

function GL_LANG_writeReport(string $path, array $coreResults, ?array $pluginAudit, array $recommendations): void
{
    $lines = [
        '# Geeklog Language Coverage',
        '',
        '> Generated by tools/check-languages.php. Do not edit manually.',
        '',
        '## Core languages',
        '',
        '| Language file | Status | Errors | Review | Info |',
        '|---|---:|---:|---:|---:|',
    ];
    foreach ($coreResults as $r) {
        $status = $r['errors'] > 0 ? '❌' : ($r['reviews'] > 0 ? '⚠️' : '✅');
        $lines[] = '| ' . $r['file'] . ' | ' . $status . ' | ' . $r['errors'] . ' | ' . $r['reviews'] . ' | ' . $r['infos'] . ' |';
    }

    $lines[] = '';
    $lines[] = '## Suggested languages';
    $lines[] = '';
    $lines[] = '| Priority | Language | Locale | Direction | Existing plugin translations |';
    $lines[] = '|---:|---|---|---|---:|';
    foreach ($recommendations as $code => $def) {
        $presence = $pluginAudit !== null && isset($pluginAudit['languages'][$code])
            ? $pluginAudit['languages'][$code]['present']
            : '—';
        $lines[] = '| ' . ($def['priority'] ?? '—') . ' | ' . ($def['name'] ?? $code)
            . ' | ' . ($def['locale'] ?? $def['iso'] ?? $code)
            . ' | ' . ($def['direction'] ?? 'ltr') . ' | ' . $presence . ' |';
    }

    if ($pluginAudit !== null) {
        $lines[] = '';
        $lines[] = '## Geeklog-Plugins coverage';
        $lines[] = '';
        $lines[] = 'Plugins audited: **' . $pluginAudit['audited'] . '**';
        $lines[] = '';
        $lines[] = '| Language | Core | Complete | Partial | Missing |';
        $lines[] = '|---|---:|---:|---:|---:|';
        foreach ($pluginAudit['languages'] as $status) {
            $lines[] = '| ' . $status['name'] . ' | ' . ($status['core'] ? '✅' : '❌')
                . ' | ' . $status['complete'] . ' | ' . $status['partial'] . ' | ' . $status['missing'] . ' |';
        }

        $lines[] = '';
        $lines[] = '## Plugin translation gaps';
        $lines[] = '';
        foreach ($pluginAudit['languages'] as $code => $language) {
            $gaps = [];
            foreach ($pluginAudit['plugins'] as $plugin => $statuses) {
                $st = $statuses[$code] ?? null;
                if ($st === null || $st['state'] === 'complete') {
                    continue;
                }
                $label = $st['state'] === 'missing'
                    ? '❌ missing'
                    : '⚠️ ' . $st['missing'] . ' missing (' . number_format($st['coverage'], 1) . '%)';
                $gaps[] = '- [' . $plugin . '](https://github.com/' . $pluginAudit['organization'] . '/' . $plugin . ') — ' . $label;
            }
            if ($gaps !== []) {
                $lines[] = '### ' . $language['name'];
                $lines[] = '';
                array_push($lines, ...$gaps);
                $lines[] = '';
            }
        }
    }

    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL);
}
