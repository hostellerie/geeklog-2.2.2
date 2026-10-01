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
