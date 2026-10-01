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

if (!is_file($referenceFile)) {
    fwrite(STDERR, "Reference file not found: {$referenceFile}\n");
    exit(2);
}

/**
 * Load one Geeklog language file and return its metadata and language arrays.
 *
 * @return array{charset:mixed,iso:mixed,arrays:array<string,array>}
 */
function loadLanguageFile(string $file): array
{
    $loader = static function (string $__file): array {
        // Some language strings interpolate common Geeklog globals/constants.
        // Harmless defaults are provided so files can be evaluated for auditing.
        $_CONF = [
            'site_url' => '',
            'site_admin_url' => '',
            'site_name' => '',
        ];

        if (!defined('XHTML')) {
            define('XHTML', ' /');
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
 * Return printf-style placeholders in appearance order.
 *
 * Literal %% is ignored because it consumes no argument.
 *
 * @param mixed $value
 * @return list<string>
 */
function placeholders($value): array
{
    if (!is_string($value)) {
        return [];
    }

    preg_match_all(
        '/%(?:(\d+)\$)?[-+0\' #]*\d*(?:\.\d+)?[bcdeEfFgGosuxX]/',
        $value,
        $matches
    );

    return $matches[0] ?? [];
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
$totalNotices = 0;

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
    $notices = [];

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
                $notices[] = 'Same as English at ' . displayKey($arrayName, $key);
            }
        }

        foreach (array_diff_key($translatedValues, $referenceValues) as $key => $_value) {
            $notices[] = 'Extra key ' . displayKey($arrayName, $key);
        }
    }

    foreach ($errors as $message) {
        echo '[ERROR] ' . $message . PHP_EOL;
    }

    foreach ($notices as $message) {
        echo '[NOTICE] ' . $message . PHP_EOL;
    }

    echo 'Summary: '
        . count($errors) . ' error(s), '
        . count($notices) . ' notice(s)'
        . PHP_EOL;

    $totalErrors += count($errors);
    $totalNotices += count($notices);
}

echo PHP_EOL
    . 'Checked ' . count($targets) . ' file(s): '
    . $totalErrors . ' error(s), '
    . $totalNotices . ' notice(s)'
    . PHP_EOL;

exit($totalErrors > 0 ? 1 : 0);
