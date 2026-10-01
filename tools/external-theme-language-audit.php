<?php

declare(strict_types=1);

function GL_LANG_auditExternalComponents(array $components): array
{
    $results = [];
    foreach ($components as $id => $component) {
        $repository = (string) $component['repository'];
        $branch = (string) ($component['branch'] ?? 'main');
        $languagePath = trim((string) $component['language_path'], '/');
        $referenceName = (string) ($component['reference'] ?? 'english.php');
        $files = GL_LANG_githubJson('https://api.github.com/repos/' . $repository . '/contents/' . $languagePath . '?ref=' . rawurlencode($branch));
        $byName = [];
        foreach ($files as $file) { if (($file['type'] ?? '') === 'file') { $byName[(string) $file['name']] = $file; } }
        if (!isset($byName[$referenceName]) || empty($byName[$referenceName]['download_url'])) { continue; }
        $referenceSource = GL_LANG_githubRaw((string) $byName[$referenceName]['download_url']);
        $referenceKeys = GL_LANG_parseKeys($referenceSource);
        $referenceValues = GL_LANG_parseValues($referenceSource);
        $result = ['name'=>(string)($component['name'] ?? $id),'repository'=>$repository,'branch'=>$branch,'language_path'=>$languagePath,'ready'=>true,'reference_keys'=>count($referenceKeys),'languages'=>[]];
        foreach (($component['file_map'] ?? []) as $coreFile => $targetFile) {
            if (!isset($byName[$targetFile]) || empty($byName[$targetFile]['download_url'])) {
                $result['languages'][$targetFile] = ['core_file'=>$coreFile,'state'=>'missing_file','missing'=>count($referenceKeys),'placeholder_errors'=>0,'identical'=>0,'coverage'=>0.0];
                $result['ready'] = false;
                continue;
            }
            $translatedSource = GL_LANG_githubRaw((string) $byName[$targetFile]['download_url']);
            $translatedKeys = GL_LANG_parseKeys($translatedSource);
            $translatedValues = GL_LANG_parseValues($translatedSource);
            $missing = count(array_diff_key($referenceKeys, $translatedKeys));
            $coverage = count($referenceKeys) > 0 ? ((count($referenceKeys) - $missing) / count($referenceKeys)) * 100 : 100.0;
            $placeholderErrors = 0; $identical = 0;
            foreach ($referenceValues as $key => $referenceValue) {
                if (!array_key_exists($key, $translatedValues)) { continue; }
                $translatedValue = $translatedValues[$key];
                if (GL_LANG_placeholders($referenceValue) !== GL_LANG_placeholders($translatedValue)) { ++$placeholderErrors; }
                if ($referenceValue !== '' && $translatedValue === $referenceValue) { ++$identical; }
            }
            $state = ($missing === 0 && $placeholderErrors === 0) ? 'complete' : 'partial';
            if ($state !== 'complete') { $result['ready'] = false; }
            $result['languages'][$targetFile] = ['core_file'=>$coreFile,'state'=>$state,'missing'=>$missing,'placeholder_errors'=>$placeholderErrors,'identical'=>$identical,'coverage'=>$coverage];
        }
        $results[$id] = $result;
    }
    return $results;
}

function GL_LANG_appendExternalComponentsReport(string $path, array $components): void
{
    if ($components === []) { return; }
    $lines = ['', '## External themes and components', ''];
    foreach ($components as $component) {
        $lines[] = '### ' . $component['name'];
        $lines[] = '';
        $lines[] = 'Repository: https://github.com/' . $component['repository'];
        $lines[] = 'Language directory: ' . $component['language_path'];
        $lines[] = '**Ready for language PR:** ' . ($component['ready'] ? '✅ Yes' : '❌ No');
        $lines[] = '';
        $lines[] = '| Language file | Status | Missing keys | Placeholder errors | Identical to English | Coverage |';
        $lines[] = '|---|---:|---:|---:|---:|---:|';
        foreach ($component['languages'] as $file => $status) {
            $icon = $status['state'] === 'complete' ? '✅' : ($status['state'] === 'missing_file' ? '❌ missing file' : '⚠️');
            $lines[] = '| ' . $file . ' | ' . $icon . ' | ' . $status['missing'] . ' | ' . $status['placeholder_errors'] . ' | ' . $status['identical'] . ' | ' . number_format($status['coverage'], 1) . '% |';
        }
        $lines[] = '';
    }
    file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL, FILE_APPEND);
}
