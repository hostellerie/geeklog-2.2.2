<?php

/**
 * Geeklog language consistency checker.
 *
 * Compares UTF-8 language files against language/english_utf-8.php.
 *
 * Usage:
 *   php tools/check-languages.php
 *   php tools/check-languages.php language/french_france_utf-8.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$referenceFile = $root . '/language/english_utf-8.php';
$benchmarkFile = $root . '/language/french_france_utf-8.php';
$identicalAllowlistFile = $root . '/tools/language-identical-allowlist.php';

if (!is_file($referenceFile)) {
    fwrite(STDERR, "Reference file not found: {$referenceFile}\n");
    exit(2);
}

/**
 * Load one Geeklog language file and return its metadata and language arrays.
 *
 * Language files contain a few interpolated Geeklog configuration values.
 * The checker builds harmless placeholders for those values before including
 * the file, so it can audit language files without bootstrapping Geeklog.
 *
 * @return array{charset:mixed,iso:mixed,arrays:array<string,array>}
 */
function loadLanguageFile(string $file): array
{
    $loader = static function (string $__file): array {
        $source = file_get_contents($__file);

        if ($source === false) {
            throw new RuntimeException("Unable to read language file: {$__file}");
        }

        // Build empty values for array-style Geeklog globals referenced by
        // interpolated strings, e.g. $_CONF['site_url'].
        if (preg_match_all(
            '/\$_([A-Z][A-Z0-9_]*)\[[\'"]([^\'"]+)[\'"]\]/',
            $source,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $match) {
                $variableName = '_' . $match[1];
                $key = $match[2];

                if (!isset($$variableName) || !is_array($$variableName)) {
                    $$variableName = [];
                }

                $$variableName[$key] = '';
            }
        }

        // Scalar Geeklog value used by a few language strings.
        $_DB_dbms = '';

        // Language files also concatenate a small number of Geeklog constants
        // (for example XHTML, VERSION, TOPIC_ALL_OPTION). Detect uppercase
        // constant tokens and provide neutral values when Geeklog itself has
        // not been bootstrapped. PHP/runtime constants already defined are left
        // untouched.
        foreach (token_get_all($source) as $token) {
            if (!is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            $constantName = $token[1];

            if (
                preg_match('/^[A-Z][A-Z0-9_]*$/', $constantName)
                && !defined($constantName)
            ) {
                define($constantName, '');
            }
        }

        include $__file;

        $vars = get_defined_vars();
        $arrays = [];

        foreach ($vars as $name => $value) {
            if (preg_match('/^LANG[0-9A-Z_]+$/', $name) && is_array($value)) {
                $arrays[$name] = $value;
            }
        }

        ksort($arrays);

        return [
            'charset' => $vars['LANG_CHARSET'] ?? null,
            'iso' => $vars['LANG_ISO639_1'] ?? null,
            'arrays' => $arrays,
        ];
    };

    return $loader($file);
}

/**
 * Return a normalized placeholder signature.
 *
 * printf placeholders are normalized by argument position and conversion type,
 * so a translation may reorder arguments safely when positional placeholders
 * such as %2$d and %1$s are used. Literal %% is ignored.
 *
 * Geeklog also uses symbolic placeholders such as %n, %i and %t in a few
 * language strings. These are tracked separately.
 *
 * The expression deliberately rejects a space as a printf flag and rejects a
 * conversion immediately followed by a hexadecimal digit. This avoids common
 * false positives such as "100% secure" and URL percent-encoding like "%E3".
 *
 * @param mixed $value
 * @return list<string>
 */
function placeholders($value): array
{
    if (!is_string($value)) {
        return [];
    }

    $signature = [];
    $nextArgument = 1;

    preg_match_all(
        '/%(?:(\\d+)\\$)?[-+0#]*\\d*(?:\\.\\d+)?([bcdeEfFgGosuxX])(?![0-9A-Fa-f])/',
        $value,
        $matches,
        PREG_SET_ORDER
    );

    foreach ($matches as $match) {
        $position = $match[1] !== '' ? (int) $match[1] : $nextArgument++;
        $signature[] = 'arg:' . $position . ':' . strtolower($match[2]);
    }

    preg_match_all('/%([nit])(?![A-Za-z0-9_])/', $value, $customMatches);

    foreach ($customMatches[1] ?? [] as $placeholder) {
        $signature[] = 'geeklog:' . $placeholder;
    }

    sort($signature);

    return $signature;
}

/**
 * Build a compact key label for output.
 *
 * @param int|string $key
 */
function displayKey(string $arrayName, $key): string
{
    return '$' . $arrayName . '[' . var_export($key, true) . ']';
}

$reference = loadLanguageFile($referenceFile);
$benchmark = is_file($benchmarkFile) ? loadLanguageFile($benchmarkFile) : null;
$identicalAllowlist = is_file($identicalAllowlistFile)
    ? include $identicalAllowlistFile
    : [];

$recommendationsFile = $root . '/tools/language-recommendations.php';
$pluginOrg = getenv('GEEKLOG_PLUGIN_ORG') ?: 'Geeklog-Plugins';
$reportPath = null;
$checkPlugins = false;
$positionalArgs = [];

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--plugins') {
        $checkPlugins = true;
        continue;
    }
    if (str_starts_with($argument, '--report=')) {
        $reportPath = substr($argument, strlen('--report='));
        continue;
    }
    if ($argument === '--report') {
        $reportPath = 'docs/language-status.md';
        continue;
    }
    $positionalArgs[] = $argument;
}

$recommendations = is_file($recommendationsFile) ? include $recommendationsFile : [];

function githubJson(string $url)
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
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $match)) {
            $status = (int) $match[1];
        }
    }
    if ($status >= 400) {
        throw new RuntimeException("GitHub request failed with HTTP {$status}: {$url}");
    }
    return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
}

function githubRawText(string $url): string
{
    $headers = ['User-Agent: geeklog-language-checker'];
    $token = getenv('GITHUB_TOKEN');
    if (is_string($token) && $token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $context = stream_context_create([
        'http' => [
            'header' => implode("\r\n", $headers),
            'timeout' => 30,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        throw new RuntimeException("Unable to download language file: {$url}");
    }
    return $body;
}

/** @return array<string,true> */
function parseLanguageKeysFromSource(string $source): array
{
    $tokens = token_get_all($source);
    $keys = [];
    $count = count($tokens);

    for ($i = 0; $i < $count; ++$i) {
        $token = $tokens[$i];
        if (!is_array($token) || $token[0] !== T_VARIABLE) {
            continue;
        }
        $variable = ltrim($token[1], '
if ($positionalArgs !== []) {
    foreach ($positionalArgs as $argument) {
        $file = $argument;

        if (!str_starts_with($file, DIRECTORY_SEPARATOR)) {
            $file = $root . DIRECTORY_SEPARATOR . $file;
        }

        $targets[] = $file;
    }
} else {
    $targets = glob($root . '/language/*_utf-8.php') ?: [];

    $targets = array_values(array_filter(
        $targets,
        static fn(string $file): bool => realpath($file) !== realpath($referenceFile)
    ));

    sort($targets);
}

if ($targets === []) {
    fwrite(STDERR, "No language files found to check.\n");
    exit(2);
}

$totalErrors = 0;
$totalReviews = 0;
$totalInfos = 0;
$results = [];

foreach ($targets as $file) {
    echo PHP_EOL . '== ' . basename($file) . ' ==' . PHP_EOL;

    if (!is_file($file)) {
        echo "[ERROR] File not found: {$file}" . PHP_EOL;
        ++$totalErrors;
        continue;
    }

    try {
        $language = loadLanguageFile($file);
    } catch (Throwable $e) {
        echo '[ERROR] Unable to load file: ' . $e->getMessage() . PHP_EOL;
        ++$totalErrors;
        continue;
    }

    $errors = [];
    $reviews = [];
    $infos = [];

    if (strtolower((string) $language['charset']) !== 'utf-8') {
        $errors[] = '$LANG_CHARSET should be utf-8; found '
            . var_export($language['charset'], true);
    }

    if (!is_string($language['iso']) || strlen($language['iso']) < 2) {
        $errors[] = '$LANG_ISO639_1 is missing or invalid';
    }

    $referenceArrays = $reference['arrays'];
    $languageArrays = $language['arrays'];

    foreach (array_diff(array_keys($referenceArrays), array_keys($languageArrays)) as $arrayName) {
        $errors[] = "Missing array ${$arrayName}";
    }

    foreach (array_diff(array_keys($languageArrays), array_keys($referenceArrays)) as $arrayName) {
        $notices[] = "Extra array ${$arrayName}";
    }

    foreach ($referenceArrays as $arrayName => $referenceValues) {
        if (!isset($languageArrays[$arrayName])) {
            continue;
        }

        $translatedValues = $languageArrays[$arrayName];

        foreach ($referenceValues as $key => $referenceValue) {
            if (!array_key_exists($key, $translatedValues)) {
                $errors[] = 'Missing key ' . displayKey($arrayName, $key);
                continue;
            }

            $translatedValue = $translatedValues[$key];
            $referencePlaceholders = placeholders($referenceValue);
            $translatedPlaceholders = placeholders($translatedValue);

            if ($referencePlaceholders !== $translatedPlaceholders) {
                $errors[] = 'Placeholder mismatch at '
                    . displayKey($arrayName, $key)
                    . ' (reference: ' . json_encode($referencePlaceholders)
                    . ', translation: ' . json_encode($translatedPlaceholders) . ')';
            }

            if (
                is_string($referenceValue)
                && $referenceValue !== ''
                && is_string($translatedValue)
                && $translatedValue === $referenceValue
            ) {
                $benchmarkValue = null;
                $benchmarkHasValue = false;

                if (
                    $benchmark !== null
                    && isset($benchmark['arrays'][$arrayName])
                    && array_key_exists($key, $benchmark['arrays'][$arrayName])
                ) {
                    $benchmarkHasValue = true;
                    $benchmarkValue = $benchmark['arrays'][$arrayName][$key];
                }

                $allowlistKey = $arrayName . ':' . (string) $key;
                $allowedIdentical = in_array(
                    $allowlistKey,
                    $identicalAllowlist[basename($file)] ?? [],
                    true
                );

                if ($allowedIdentical) {
                    $infos[] = 'Same as English (explicitly allowed) at '
                        . displayKey($arrayName, $key);
                } elseif (
                    basename($file) !== basename($benchmarkFile)
                    && $benchmarkHasValue
                    && is_string($benchmarkValue)
                    && $benchmarkValue !== $referenceValue
                ) {
                    $reviews[] = 'Likely untranslated at '
                        . displayKey($arrayName, $key);
                } else {
                    $infos[] = 'Same as English (benchmark-compatible) at '
                        . displayKey($arrayName, $key);
                }
            }
        }

        foreach (array_diff_key($translatedValues, $referenceValues) as $key => $_value) {
            $infos[] = 'Extra key ' . displayKey($arrayName, $key);
        }
    }

    foreach ($errors as $message) {
        echo '[ERROR] ' . $message . PHP_EOL;
    }

    foreach ($reviews as $message) {
        echo '[REVIEW] ' . $message . PHP_EOL;
    }

    foreach ($infos as $message) {
        echo '[INFO] ' . $message . PHP_EOL;
    }

    echo 'Summary: '
        . count($errors) . ' error(s), '
        . count($reviews) . ' review item(s), '
        . count($infos) . ' info item(s)'
        . PHP_EOL;

    $errorCount = count($errors);
    $reviewCount = count($reviews);
    $infoCount = count($infos);

    $results[] = [
        'file' => basename($file),
        'errors' => $errorCount,
        'reviews' => $reviewCount,
        'infos' => $infoCount,
    ];

    $totalErrors += $errorCount;
    $totalReviews += $reviewCount;
    $totalInfos += $infoCount;
}

echo PHP_EOL . '== Language summary ==' . PHP_EOL;

$maxFileLength = 0;
foreach ($results as $result) {
    $maxFileLength = max($maxFileLength, strlen($result['file']));
}

foreach ($results as $result) {
    printf(
        "%-{$maxFileLength}s  %4d error(s)  %5d review item(s)  %5d info item(s)\n",
        $result['file'],
        $result['errors'],
        $result['reviews'],
        $result['infos']
    );
}

echo PHP_EOL
    . 'Checked ' . count($targets) . ' file(s): '
    . $totalErrors . ' error(s), '
    . $totalReviews . ' review item(s), '
    . $totalInfos . ' info item(s)'
    . PHP_EOL;


$pluginAudit = null;

if ($checkPlugins) {
    echo PHP_EOL . '== Plugin ecosystem audit ==' . PHP_EOL;
    try {
        $pluginAudit = auditPluginLanguages($pluginOrg, $recommendations);
        echo 'Audited ' . $pluginAudit['audited'] . ' plugin(s) in ' . $pluginOrg . PHP_EOL;
    } catch (Throwable $e) {
        fwrite(STDERR, '[ERROR] Plugin ecosystem audit failed: ' . $e->getMessage() . PHP_EOL);
        ++$totalErrors;
    }
}

if ($reportPath !== null) {
    if (!str_starts_with($reportPath, DIRECTORY_SEPARATOR)) {
        $reportPath = $root . DIRECTORY_SEPARATOR . $reportPath;
    }
    try {
        writeLanguageReport($reportPath, $results, $pluginAudit, $recommendations);
        echo 'Wrote report: ' . $reportPath . PHP_EOL;
    } catch (Throwable $e) {
        fwrite(STDERR, '[ERROR] Unable to write report: ' . $e->getMessage() . PHP_EOL);
        ++$totalErrors;
    }
}

exit($totalErrors > 0 ? 1 : 0);

);
        if (!preg_match('/^LANG[0-9A-Z_]+$/i', $variable)) {
            continue;
        }

        $j = $i + 1;
        while ($j < $count && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            ++$j;
        }

        if ($j < $count && $tokens[$j] === '[') {
            ++$j;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                ++$j;
            }
            $keyToken = $tokens[$j] ?? null;
            $key = null;
            if (is_array($keyToken) && $keyToken[0] === T_CONSTANT_ENCAPSED_STRING) {
                $key = stripcslashes(substr($keyToken[1], 1, -1));
            } elseif (is_array($keyToken) && $keyToken[0] === T_LNUMBER) {
                $key = (string) ((int) $keyToken[1]);
            }
            if ($key !== null) {
                $keys[$variable . ':' . $key] = true;
            }
            continue;
        }

        if (($tokens[$j] ?? null) !== '=') {
            continue;
        }
        ++$j;
        while ($j < $count && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            ++$j;
        }

        $style = null;
        if (is_array($tokens[$j] ?? null) && $tokens[$j][0] === T_ARRAY) {
            $style = 'paren';
            ++$j;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                ++$j;
            }
            if (($tokens[$j] ?? null) !== '(') {
                continue;
            }
        } elseif (($tokens[$j] ?? null) === '[') {
            $style = 'bracket';
        } else {
            continue;
        }

        $open = $style === 'paren' ? '(' : '[';
        $close = $style === 'paren' ? ')' : ']';
        $depth = 0;
        $implicitKey = 0;
        $elementTokens = [];

        for (; $j < $count; ++$j) {
            $current = $tokens[$j];
            if ($current === $open) {
                ++$depth;
                if ($depth === 1) {
                    continue;
                }
            } elseif ($current === $close) {
                if ($depth === 1) {
                    if ($elementTokens !== []) {
                        $parsed = parseArrayElementKey($elementTokens, $implicitKey);
                        if ($parsed !== null) {
                            $keys[$variable . ':' . $parsed] = true;
                        }
                    }
                    break;
                }
                --$depth;
            }

            if ($depth === 1 && $current === ',') {
                if ($elementTokens !== []) {
                    $parsed = parseArrayElementKey($elementTokens, $implicitKey);
                    if ($parsed !== null) {
                        $keys[$variable . ':' . $parsed] = true;
                    }
                }
                $elementTokens = [];
                continue;
            }

            if ($depth >= 1) {
                $elementTokens[] = $current;
            }
        }
    }

    return $keys;
}

/** @param array<int,mixed> $tokens */
function parseArrayElementKey(array $tokens, int &$implicitKey): ?string
{
    $arrow = null;
    foreach ($tokens as $index => $token) {
        if (is_array($token) && $token[0] === T_DOUBLE_ARROW) {
            $arrow = $index;
            break;
        }
    }
    if ($arrow === null) {
        return (string) $implicitKey++;
    }

    $text = '';
    foreach (array_slice($tokens, 0, $arrow) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $text .= $token[1];
        } else {
            $text .= $token;
        }
    }
    $text = trim($text);
    if (preg_match('/^[\'"](.*)[\'"]$/s', $text, $match)) {
        return stripcslashes($match[1]);
    }
    if (preg_match('/^-?\d+$/', $text)) {
        $value = (string) ((int) $text);
        $implicitKey = max($implicitKey, ((int) $value) + 1);
        return $value;
    }
    return null;
}

/** @param list<array<string,mixed>> $files @param list<string> $prefixes */
function findRemoteLanguageFile(array $files, array $prefixes): ?array
{
    $candidates = [];
    foreach ($files as $file) {
        $name = strtolower((string) ($file['name'] ?? ''));
        if (!str_ends_with($name, '.php')) {
            continue;
        }
        $stem = substr($name, 0, -4);
        $normalized = str_replace('-', '_', $stem);
        foreach ($prefixes as $prefix) {
            $prefix = strtolower(str_replace('-', '_', (string) $prefix));
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
        $aName = strtolower((string) $a['name']);
        $bName = strtolower((string) $b['name']);
        $aUtf = str_contains($aName, 'utf-8') || str_contains($aName, 'utf8') ? 0 : 1;
        $bUtf = str_contains($bName, 'utf-8') || str_contains($bName, 'utf8') ? 0 : 1;
        return [$aUtf, strlen($aName), $aName] <=> [$bUtf, strlen($bName), $bName];
    });
    return $candidates[0];
}

function ecosystemLanguages(array $recommendations): array
{
    $languages = [
        'fr' => ['name' => 'French', 'prefixes' => ['french_france', 'french'], 'core' => true, 'priority' => null],
        'de' => ['name' => 'German', 'prefixes' => ['german'], 'core' => true, 'priority' => null],
        'es' => ['name' => 'Spanish', 'prefixes' => ['spanish'], 'core' => true, 'priority' => null],
        'it' => ['name' => 'Italian', 'prefixes' => ['italian'], 'core' => true, 'priority' => null],
        'ja' => ['name' => 'Japanese', 'prefixes' => ['japanese'], 'core' => true, 'priority' => null],
        'ru' => ['name' => 'Russian', 'prefixes' => ['russian'], 'core' => true, 'priority' => null],
        'zh_cn' => ['name' => 'Chinese (Simplified)', 'prefixes' => ['chinese_simplified'], 'core' => true, 'priority' => null],
        'zh_tw' => ['name' => 'Chinese (Traditional)', 'prefixes' => ['chinese_traditional'], 'core' => true, 'priority' => null],
        'he' => ['name' => 'Hebrew', 'prefixes' => ['hebrew'], 'core' => true, 'priority' => null],
        'fa' => ['name' => 'Persian', 'prefixes' => ['persian'], 'core' => true, 'priority' => null],
    ];
    foreach ($recommendations as $code => $definition) {
        if (!isset($languages[$code])) {
            $languages[$code] = [
                'name' => (string) ($definition['name'] ?? $code),
                'prefixes' => array_values($definition['prefixes'] ?? []),
                'core' => false,
                'priority' => isset($definition['priority']) ? (int) $definition['priority'] : null,
            ];
        }
    }
    return $languages;
}

function auditPluginLanguages(string $organization, array $recommendations): array
{
    $repos = [];
    for ($page = 1; ; ++$page) {
        $batch = githubJson('https://api.github.com/orgs/' . rawurlencode($organization) . '/repos?type=public&per_page=100&page=' . $page);
        if (!is_array($batch) || $batch === []) {
            break;
        }
        foreach ($batch as $repo) {
            if (($repo['fork'] ?? false) || ($repo['archived'] ?? false) || ($repo['name'] ?? '') === 'language-audit') {
                continue;
            }
            $repos[] = $repo;
        }
        if (count($batch) < 100) {
            break;
        }
    }

    usort($repos, static fn(array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));
    $languages = ecosystemLanguages($recommendations);
    $summary = [];
    foreach ($languages as $code => $definition) {
        $summary[$code] = [
            'name' => $definition['name'],
            'core' => $definition['core'],
            'priority' => $definition['priority'],
            'complete' => 0,
            'partial' => 0,
            'missing' => 0,
            'available_without_core' => 0,
        ];
    }

    $plugins = [];
    $audited = 0;

    foreach ($repos as $repo) {
        $name = (string) $repo['name'];
        $branch = (string) ($repo['default_branch'] ?? 'master');
        $contentsUrl = 'https://api.github.com/repos/' . rawurlencode($organization) . '/' . rawurlencode($name)
            . '/contents/language?ref=' . rawurlencode($branch);

        try {
            $files = githubJson($contentsUrl);
        } catch (Throwable $e) {
            continue;
        }
        if (!is_array($files)) {
            continue;
        }

        $english = findRemoteLanguageFile($files, ['english']);
        if ($english === null || empty($english['download_url'])) {
            continue;
        }

        try {
            $referenceKeys = parseLanguageKeysFromSource(githubRawText((string) $english['download_url']));
        } catch (Throwable $e) {
            continue;
        }
        if ($referenceKeys === []) {
            continue;
        }

        ++$audited;
        $pluginStatus = [];

        foreach ($languages as $code => $definition) {
            $languageFile = findRemoteLanguageFile($files, $definition['prefixes']);
            if ($languageFile === null || empty($languageFile['download_url'])) {
                $pluginStatus[$code] = [
                    'state' => 'missing',
                    'missing' => count($referenceKeys),
                    'total' => count($referenceKeys),
                    'coverage' => 0.0,
                    'file' => null,
                ];
                ++$summary[$code]['missing'];
                continue;
            }

            try {
                $translatedKeys = parseLanguageKeysFromSource(githubRawText((string) $languageFile['download_url']));
            } catch (Throwable $e) {
                $translatedKeys = [];
            }

            $missingCount = count(array_diff_key($referenceKeys, $translatedKeys));
            $total = count($referenceKeys);
            $coverage = $total > 0 ? (($total - $missingCount) / $total) * 100 : 100.0;
            $state = $missingCount === 0 ? 'complete' : 'partial';
            ++$summary[$code][$state];

            if (!$definition['core']) {
                ++$summary[$code]['available_without_core'];
            }

            $pluginStatus[$code] = [
                'state' => $state,
                'missing' => $missingCount,
                'total' => $total,
                'coverage' => $coverage,
                'file' => (string) ($languageFile['name'] ?? ''),
            ];
        }
        $plugins[$name] = $pluginStatus;
    }

    return [
        'organization' => $organization,
        'audited' => $audited,
        'languages' => $summary,
        'plugins' => $plugins,
    ];
}

function reportStatusIcon(array $result): string
{
    if (($result['errors'] ?? 0) > 0) {
        return '❌';
    }
    if (($result['reviews'] ?? 0) > 0) {
        return '⚠️';
    }
    return '✅';
}

function writeLanguageReport(string $path, array $coreResults, ?array $pluginAudit, array $recommendations): void
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

    foreach ($coreResults as $result) {
        $lines[] = '| ' . $result['file'] . ' | ' . reportStatusIcon($result)
            . ' | ' . $result['errors'] . ' | ' . $result['reviews'] . ' | ' . $result['infos'] . ' |';
    }

    $lines[] = '';
    $lines[] = '## Suggested languages not yet in the core';
    $lines[] = '';
    $lines[] = '| Priority | Language | Locale | Direction | Existing plugin translations |';
    $lines[] = '|---:|---|---|---|---:|';

    $coreNames = array_map(static fn(array $r): string => strtolower((string) $r['file']), $coreResults);
    foreach ($recommendations as $code => $definition) {
        $present = false;
        foreach ($coreNames as $fileName) {
            foreach (($definition['prefixes'] ?? []) as $prefix) {
                if ($fileName === strtolower((string) $prefix) . '_utf-8.php') {
                    $present = true;
                    break 2;
                }
            }
        }
        if ($present) {
            continue;
        }

        $pluginPresence = '—';
        if ($pluginAudit !== null && isset($pluginAudit['languages'][$code])) {
            $pluginPresence = (string) $pluginAudit['languages'][$code]['available_without_core'];
        }

        $lines[] = '| ' . ($definition['priority'] ?? '—')
            . ' | ' . ($definition['name'] ?? $code)
            . ' | ' . ($definition['locale'] ?? $definition['iso'] ?? $code)
            . ' | ' . ($definition['direction'] ?? 'ltr')
            . ' | ' . $pluginPresence . ' |';
    }

    if ($pluginAudit !== null) {
        $lines[] = '';
        $lines[] = '## Plugin ecosystem';
        $lines[] = '';
        $lines[] = 'Organization: **' . $pluginAudit['organization'] . '**  ';
        $lines[] = 'Plugins audited: **' . $pluginAudit['audited'] . '**';
        $lines[] = '';
        $lines[] = '| Language | Core | Complete plugins | Partial | Missing | Plugin translations without core support |';
        $lines[] = '|---|---:|---:|---:|---:|---:|';

        foreach ($pluginAudit['languages'] as $status) {
            $lines[] = '| ' . $status['name']
                . ' | ' . ($status['core'] ? '✅' : '❌')
                . ' | ' . $status['complete']
                . ' | ' . $status['partial']
                . ' | ' . $status['missing']
                . ' | ' . ($status['core'] ? '—' : $status['available_without_core'])
                . ' |';
        }

        $lines[] = '';
        $lines[] = '## Plugin translation gaps';
        $lines[] = '';
        $lines[] = 'Only incomplete or missing translations are listed below.';
        $lines[] = '';

        foreach ($pluginAudit['languages'] as $code => $languageStatus) {
            $gaps = [];
            foreach ($pluginAudit['plugins'] as $plugin => $statuses) {
                $status = $statuses[$code] ?? null;
                if ($status === null || $status['state'] === 'complete') {
                    continue;
                }
                $label = $status['state'] === 'missing'
                    ? '❌ missing'
                    : '⚠️ ' . $status['missing'] . ' missing (' . number_format($status['coverage'], 1) . '%)';
                $gaps[] = '[' . $plugin . '](https://github.com/' . $pluginAudit['organization'] . '/' . $plugin . ') — ' . $label;
            }
            if ($gaps === []) {
                continue;
            }
            $lines[] = '### ' . $languageStatus['name'];
            $lines[] = '';
            foreach ($gaps as $gap) {
                $lines[] = '- ' . $gap;
            }
            $lines[] = '';
        }
    } else {
        $lines[] = '';
        $lines[] = '> Plugin coverage was not generated. Run with --plugins --report=docs/language-status.md.';
        $lines[] = '';
    }

    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException("Unable to create report directory: {$directory}");
    }
    file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL);
}

$targets = [];

if ($argc > 1) {
    foreach (array_slice($argv, 1) as $argument) {
        $file = $argument;

        if (!str_starts_with($file, DIRECTORY_SEPARATOR)) {
            $file = $root . DIRECTORY_SEPARATOR . $file;
        }

        $targets[] = $file;
    }
} else {
    $targets = glob($root . '/language/*_utf-8.php') ?: [];

    $targets = array_values(array_filter(
        $targets,
        static fn(string $file): bool => realpath($file) !== realpath($referenceFile)
    ));

    sort($targets);
}

if ($targets === []) {
    fwrite(STDERR, "No language files found to check.\n");
    exit(2);
}

$totalErrors = 0;
$totalReviews = 0;
$totalInfos = 0;
$results = [];

foreach ($targets as $file) {
    echo PHP_EOL . '== ' . basename($file) . ' ==' . PHP_EOL;

    if (!is_file($file)) {
        echo "[ERROR] File not found: {$file}" . PHP_EOL;
        ++$totalErrors;
        continue;
    }

    try {
        $language = loadLanguageFile($file);
    } catch (Throwable $e) {
        echo '[ERROR] Unable to load file: ' . $e->getMessage() . PHP_EOL;
        ++$totalErrors;
        continue;
    }

    $errors = [];
    $reviews = [];
    $infos = [];

    if (strtolower((string) $language['charset']) !== 'utf-8') {
        $errors[] = '$LANG_CHARSET should be utf-8; found '
            . var_export($language['charset'], true);
    }

    if (!is_string($language['iso']) || strlen($language['iso']) < 2) {
        $errors[] = '$LANG_ISO639_1 is missing or invalid';
    }

    $referenceArrays = $reference['arrays'];
    $languageArrays = $language['arrays'];

    foreach (array_diff(array_keys($referenceArrays), array_keys($languageArrays)) as $arrayName) {
        $errors[] = "Missing array ${$arrayName}";
    }

    foreach (array_diff(array_keys($languageArrays), array_keys($referenceArrays)) as $arrayName) {
        $notices[] = "Extra array ${$arrayName}";
    }

    foreach ($referenceArrays as $arrayName => $referenceValues) {
        if (!isset($languageArrays[$arrayName])) {
            continue;
        }

        $translatedValues = $languageArrays[$arrayName];

        foreach ($referenceValues as $key => $referenceValue) {
            if (!array_key_exists($key, $translatedValues)) {
                $errors[] = 'Missing key ' . displayKey($arrayName, $key);
                continue;
            }

            $translatedValue = $translatedValues[$key];
            $referencePlaceholders = placeholders($referenceValue);
            $translatedPlaceholders = placeholders($translatedValue);

            if ($referencePlaceholders !== $translatedPlaceholders) {
                $errors[] = 'Placeholder mismatch at '
                    . displayKey($arrayName, $key)
                    . ' (reference: ' . json_encode($referencePlaceholders)
                    . ', translation: ' . json_encode($translatedPlaceholders) . ')';
            }

            if (
                is_string($referenceValue)
                && $referenceValue !== ''
                && is_string($translatedValue)
                && $translatedValue === $referenceValue
            ) {
                $benchmarkValue = null;
                $benchmarkHasValue = false;

                if (
                    $benchmark !== null
                    && isset($benchmark['arrays'][$arrayName])
                    && array_key_exists($key, $benchmark['arrays'][$arrayName])
                ) {
                    $benchmarkHasValue = true;
                    $benchmarkValue = $benchmark['arrays'][$arrayName][$key];
                }

                $allowlistKey = $arrayName . ':' . (string) $key;
                $allowedIdentical = in_array(
                    $allowlistKey,
                    $identicalAllowlist[basename($file)] ?? [],
                    true
                );

                if ($allowedIdentical) {
                    $infos[] = 'Same as English (explicitly allowed) at '
                        . displayKey($arrayName, $key);
                } elseif (
                    basename($file) !== basename($benchmarkFile)
                    && $benchmarkHasValue
                    && is_string($benchmarkValue)
                    && $benchmarkValue !== $referenceValue
                ) {
                    $reviews[] = 'Likely untranslated at '
                        . displayKey($arrayName, $key);
                } else {
                    $infos[] = 'Same as English (benchmark-compatible) at '
                        . displayKey($arrayName, $key);
                }
            }
        }

        foreach (array_diff_key($translatedValues, $referenceValues) as $key => $_value) {
            $infos[] = 'Extra key ' . displayKey($arrayName, $key);
        }
    }

    foreach ($errors as $message) {
        echo '[ERROR] ' . $message . PHP_EOL;
    }

    foreach ($reviews as $message) {
        echo '[REVIEW] ' . $message . PHP_EOL;
    }

    foreach ($infos as $message) {
        echo '[INFO] ' . $message . PHP_EOL;
    }

    echo 'Summary: '
        . count($errors) . ' error(s), '
        . count($reviews) . ' review item(s), '
        . count($infos) . ' info item(s)'
        . PHP_EOL;

    $errorCount = count($errors);
    $reviewCount = count($reviews);
    $infoCount = count($infos);

    $results[] = [
        'file' => basename($file),
        'errors' => $errorCount,
        'reviews' => $reviewCount,
        'infos' => $infoCount,
    ];

    $totalErrors += $errorCount;
    $totalReviews += $reviewCount;
    $totalInfos += $infoCount;
}

echo PHP_EOL . '== Language summary ==' . PHP_EOL;

$maxFileLength = 0;
foreach ($results as $result) {
    $maxFileLength = max($maxFileLength, strlen($result['file']));
}

foreach ($results as $result) {
    printf(
        "%-{$maxFileLength}s  %4d error(s)  %5d review item(s)  %5d info item(s)\n",
        $result['file'],
        $result['errors'],
        $result['reviews'],
        $result['infos']
    );
}

echo PHP_EOL
    . 'Checked ' . count($targets) . ' file(s): '
    . $totalErrors . ' error(s), '
    . $totalReviews . ' review item(s), '
    . $totalInfos . ' info item(s)'
    . PHP_EOL;

exit($totalErrors > 0 ? 1 : 0);
