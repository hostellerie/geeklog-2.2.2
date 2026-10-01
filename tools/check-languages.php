<?php

/**
 * Geeklog language consistency checker.
 *
 * Compares UTF-8 language files against language/english_utf-8.php.
 *
 * Usage:
 *   php tools/check-languages.php
 *   php tools/check-languages.php language/french_france_utf-8.php
 *   php tools/check-languages.php --plugins
 *   php tools/check-languages.php --plugins --report=docs/language-status.md
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$referenceFile = $root . '/language/english_utf-8.php';
$benchmarkFile = $root . '/language/french_france_utf-8.php';
$identicalAllowlistFile = $root . '/tools/language-identical-allowlist.php';
$ecosystemHelperFile = $root . '/tools/language-ecosystem.php';
$recommendationsFile = $root . '/tools/language-recommendations.php';
$bundledHelperFile = $root . '/tools/bundled-language-audit.php';
$bundledAllowlistFile = $root . '/tools/bundled-plugin-identical-allowlist.php';

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
$checkPlugins = false;
$reportPath = null;
$pluginOrg = getenv('GEEKLOG_PLUGIN_ORG') ?: 'Geeklog-Plugins';
$positionalArgs = [];

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--plugins') {
        $checkPlugins = true;
        continue;
    }
    if ($argument === '--report') {
        $reportPath = 'docs/language-status.md';
        continue;
    }
    if (str_starts_with($argument, '--report=')) {
        $reportPath = substr($argument, strlen('--report='));
        continue;
    }
    $positionalArgs[] = $argument;
}

$targets = [];

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
$recommendations = is_file($recommendationsFile) ? include $recommendationsFile : [];

if ($checkPlugins || $reportPath !== null) {
    if (!is_file($ecosystemHelperFile)) {
        fwrite(STDERR, "[ERROR] Ecosystem helper not found: {$ecosystemHelperFile}\n");
        ++$totalErrors;
    } else {
        require_once $ecosystemHelperFile;
        if (is_file($bundledHelperFile)) {
            require_once $bundledHelperFile;
        }
    }
}

if ($checkPlugins && function_exists('GL_LANG_auditPlugins')) {
    echo PHP_EOL . '== Plugin ecosystem audit ==' . PHP_EOL;
    try {
        $pluginAudit = GL_LANG_auditPlugins($pluginOrg, $recommendations);
        echo 'Audited ' . $pluginAudit['audited'] . ' plugin(s) in ' . $pluginOrg . PHP_EOL;
    } catch (Throwable $e) {
        fwrite(STDERR, '[ERROR] Plugin ecosystem audit failed: ' . $e->getMessage() . PHP_EOL);
        ++$totalErrors;
    }
}

$bundledAudit = null;
if (($checkPlugins || $reportPath !== null) && function_exists('GL_LANG_auditBundledPlugins')) {
    $bundledAllowlist = is_file($bundledAllowlistFile) ? include $bundledAllowlistFile : [];
    try {
        $bundledAudit = GL_LANG_auditBundledPlugins($root, $bundledAllowlist);
        echo 'Audited ' . $bundledAudit['count'] . ' bundled plugin(s)' . PHP_EOL;
    } catch (Throwable $e) {
        fwrite(STDERR, '[ERROR] Bundled plugin audit failed: ' . $e->getMessage() . PHP_EOL);
        ++$totalErrors;
    }
}

if ($reportPath !== null && function_exists('GL_LANG_writeReport')) {
    if (!str_starts_with($reportPath, DIRECTORY_SEPARATOR)) {
        $reportPath = $root . DIRECTORY_SEPARATOR . $reportPath;
    }
    try {
        GL_LANG_writeReport($reportPath, $results, $pluginAudit, $recommendations);
        if ($bundledAudit !== null && function_exists('GL_LANG_appendBundledReport')) {
            GL_LANG_appendBundledReport($reportPath, $bundledAudit);
        }
        echo 'Wrote report: ' . $reportPath . PHP_EOL;
    } catch (Throwable $e) {
        fwrite(STDERR, '[ERROR] Report generation failed: ' . $e->getMessage() . PHP_EOL);
        ++$totalErrors;
    }
}

exit($totalErrors > 0 ? 1 : 0);
