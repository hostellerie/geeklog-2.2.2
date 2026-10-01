<?php

declare(strict_types=1);

/**
 * Bundled-plugin language audit helpers.
 *
 * Loaded only by tools/check-languages.php. This is not a second checker.
 */

function GL_LANG_auditBundledPlugins(string $root, array $allowlist = []): array
{
    $coreFiles = glob($root . '/language/*_utf-8.php') ?: [];
    $languages = [];

    foreach ($coreFiles as $file) {
        $base = basename($file);
        if ($base !== 'english_utf-8.php') {
            $languages[] = $base;
        }
    }
    sort($languages);

    $pluginDirs = glob($root . '/plugins/*/language', GLOB_ONLYDIR) ?: [];
    sort($pluginDirs);
    $plugins = [];

    foreach ($pluginDirs as $languageDir) {
        $plugin = basename(dirname($languageDir));
        $referenceFile = $languageDir . '/english_utf-8.php';
        if (!is_file($referenceFile)) {
            continue;
        }

        $referenceSource = file_get_contents($referenceFile);
        if ($referenceSource === false) {
            continue;
        }

        $referenceKeys = GL_LANG_parseKeys($referenceSource);
        $referenceValues = GL_LANG_parseValues($referenceSource);
        if ($referenceKeys === []) {
            continue;
        }

        $result = [
            'ready' => true,
            'blocking' => 0,
            'reference_keys' => count($referenceKeys),
            'languages' => [],
        ];

        foreach ($languages as $languageFile) {
            $file = $languageDir . '/' . $languageFile;
            $allowKey = $plugin . '/' . $languageFile;
            $allowed = $allowlist[$allowKey] ?? [];

            if (!is_file($file)) {
                $result['languages'][$languageFile] = [
                    'state' => 'missing_file',
                    'missing' => count($referenceKeys),
                    'placeholder_errors' => 0,
                    'reviews' => 0,
                    'coverage' => 0.0,
                ];
                $result['ready'] = false;
                ++$result['blocking'];
                continue;
            }

            $source = file_get_contents($file);
            if ($source === false) {
                $result['languages'][$languageFile] = [
                    'state' => 'unreadable',
                    'missing' => count($referenceKeys),
                    'placeholder_errors' => 0,
                    'reviews' => 0,
                    'coverage' => 0.0,
                ];
                $result['ready'] = false;
                ++$result['blocking'];
                continue;
            }

            $translatedKeys = GL_LANG_parseKeys($source);
            $translatedValues = GL_LANG_parseValues($source);
            $missing = count(array_diff_key($referenceKeys, $translatedKeys));
            $coverage = count($referenceKeys) > 0
                ? ((count($referenceKeys) - $missing) / count($referenceKeys)) * 100
                : 100.0;

            $placeholderErrors = 0;
            $reviews = 0;

            foreach ($referenceValues as $key => $referenceValue) {
                if (!array_key_exists($key, $translatedValues)) {
                    continue;
                }

                $translatedValue = $translatedValues[$key];

                if (GL_LANG_placeholders($referenceValue) !== GL_LANG_placeholders($translatedValue)) {
                    ++$placeholderErrors;
                }

                if (
                    $referenceValue !== ''
                    && $translatedValue === $referenceValue
                    && !in_array($key, $allowed, true)
                ) {
                    ++$reviews;
                }
            }

            $state = ($missing === 0 && $placeholderErrors === 0 && $reviews === 0)
                ? 'complete'
                : 'partial';

            if ($state !== 'complete') {
                $result['ready'] = false;
                ++$result['blocking'];
            }

            $result['languages'][$languageFile] = [
                'state' => $state,
                'missing' => $missing,
                'placeholder_errors' => $placeholderErrors,
                'reviews' => $reviews,
                'coverage' => $coverage,
            ];
        }

        $plugins[$plugin] = $result;
    }

    ksort($plugins);

    return [
        'count' => count($plugins),
        'languages' => $languages,
        'plugins' => $plugins,
    ];
}

function GL_LANG_appendBundledReport(string $path, array $audit): void
{
    $lines = [
        '',
        '## Bundled plugins',
        '',
        'These plugins ship with Geeklog itself. A plugin is **Ready for PR** only when every UTF-8 language supported by the core exists for that plugin and has no missing keys, placeholder errors, or unreviewed strings identical to English.',
        '',
        '| Plugin | Ready for PR | Blocking languages |',
        '|---|---:|---:|',
    ];

    foreach ($audit['plugins'] as $plugin => $pluginResult) {
        $lines[] = '| ' . $plugin . ' | '
            . ($pluginResult['ready'] ? '✅' : '❌')
            . ' | ' . $pluginResult['blocking'] . ' |';
    }

    foreach ($audit['plugins'] as $plugin => $pluginResult) {
        $lines[] = '';
        $lines[] = '### Bundled plugin: ' . $plugin;
        $lines[] = '';
        $lines[] = '**Ready for PR:** ' . ($pluginResult['ready'] ? '✅ Yes' : '❌ No');
        $lines[] = '';
        $lines[] = '| Language file | Status | Missing keys | Placeholder errors | Review | Coverage |';
        $lines[] = '|---|---:|---:|---:|---:|---:|';

        foreach ($pluginResult['languages'] as $languageFile => $status) {
            if ($status['state'] === 'complete') {
                $icon = '✅';
            } elseif ($status['state'] === 'missing_file') {
                $icon = '❌ missing file';
            } else {
                $icon = '⚠️';
            }

            $lines[] = '| ' . $languageFile . ' | ' . $icon
                . ' | ' . $status['missing']
                . ' | ' . $status['placeholder_errors']
                . ' | ' . $status['reviews']
                . ' | ' . number_format($status['coverage'], 1) . '% |';
        }
    }

    file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL, FILE_APPEND);
}
