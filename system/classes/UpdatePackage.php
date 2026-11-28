<?php
namespace Geeklog;

/**
 * Read-only integrity verifier for the experimental code-only update ZIP.
 * The update record must come from an authenticated UpdateCatalog instance.
 * Does not extract, apply, or perform migrations.
 */
final class UpdatePackage
{
    const MAX_FILE = 8388608;
    const MAX_TOTAL = 67108864;
    const MAX_ARCHIVE = 73400320;
    const MAX_MANIFEST = 262144;
    const MAX_FILES = 200;

    private static function requireValid($condition, $message)
    {
        if (!$condition) {
            throw new \InvalidArgumentException($message);
        }
    }

    private static function fields($value, array $fields)
    {
        self::requireValid(is_array($value) && count($value) === count($fields), 'Invalid object fields');
        foreach ($fields as $field) {
            self::requireValid(array_key_exists($field, $value), 'Missing field: ' . $field);
        }
    }

    private static function digest($value)
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private static function allowedPath($path)
    {
        self::requireValid(is_string($path) && strlen($path) <= 240, 'Invalid relative path');
        $parts = explode('/', $path);
        self::requireValid(count($parts) >= 2 && in_array($parts[0],
            array('public_html', 'system', 'plugins', 'language'), true), 'Invalid package root');
        foreach ($parts as $part) {
            self::requireValid(preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]*$/D', $part) === 1 &&
                substr($part, -1) !== '.' &&
                preg_match('/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\..*)?$/Di', $part) !== 1,
                'Unsafe package path');
        }
        $lower = strtolower($path);
        foreach (array('images', 'backend', 'layout', 'data', 'logs', 'backups') as $directory) {
            self::requireValid(strpos($lower, 'public_html/' . $directory . '/') !== 0, 'Protected public directory');
        }
        foreach (array_slice($parts, 1) as $part) {
            self::requireValid(!in_array(strtolower($part),
                array('db-config.php', 'siteconfig.php', 'config.php', 'data', 'logs', 'backups'), true),
                'Protected configuration or data');
        }
    }

    private static function regularEntry(\ZipArchive $zip, $index)
    {
        $os = 0;
        $attributes = 0;
        self::requireValid(method_exists($zip, 'getExternalAttributesIndex') &&
            $zip->getExternalAttributesIndex($index, $os, $attributes) &&
            $os === 3 && (($attributes >> 16) & 0170000) === 0100000,
            'Nonregular or unverifiable ZIP entry');
    }

    private static function entryBytes(\ZipArchive $zip, $index, $size, $limit)
    {
        self::requireValid(is_int($size) && $size >= 0 && $size <= $limit, 'Invalid entry size');
        $bytes = $zip->getFromIndex($index, $size + 1);
        self::requireValid(is_string($bytes) && strlen($bytes) === $size, 'Unable to read ZIP entry');
        return $bytes;
    }

    /**
     * Verify a locally staged artifact against authenticated catalog metadata.
     * Caller must keep the staged file private and immutable before installation.
     */
    public static function verify($archivePath, array $update)
    {
        self::requireValid(class_exists('ZipArchive'), 'ZIP extension unavailable');
        self::requireValid(is_string($archivePath) && is_file($archivePath) && !is_link($archivePath),
            'Invalid staged archive');
        self::requireValid(isset($update['package'], $update['id'], $update['kind'], $update['version'],
            $update['schema'], $update['from'], $update['from_schema']), 'Incomplete update metadata');
        self::fields($update['package'], array('url', 'bytes', 'sha256'));
        $size = filesize($archivePath);
        self::requireValid(is_int($size) && $size > 0 && $size <= self::MAX_ARCHIVE &&
            $size === $update['package']['bytes'] && self::digest($update['package']['sha256']) &&
            hash_file('sha256', $archivePath) === $update['package']['sha256'], 'Archive integrity mismatch');

        $zip = new \ZipArchive();
        self::requireValid($zip->open($archivePath, \ZipArchive::CHECKCONS) === true, 'Invalid ZIP archive');
        try {
            self::requireValid($zip->numFiles >= 2 && $zip->numFiles <= self::MAX_FILES + 1,
                'Invalid ZIP entry count');
            $entries = array();
            $folded = array();
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                self::requireValid(is_array($stat) && isset($stat['name'], $stat['size']), 'Invalid ZIP entry');
                $name = $stat['name'];
                self::requireValid(!isset($folded[strtolower($name)]), 'Duplicate ZIP entry');
                $folded[strtolower($name)] = true;
                self::regularEntry($zip, $i);
                if ($name !== 'manifest.json') {
                    self::requireValid(strpos($name, 'files/') === 0, 'Unexpected ZIP entry');
                    self::allowedPath(substr($name, 6));
                }
                $entries[$name] = array($i, $stat['size']);
            }
            self::requireValid(isset($entries['manifest.json']), 'Missing manifest');
            $entry = $entries['manifest.json'];
            $raw = self::entryBytes($zip, $entry[0], $entry[1], self::MAX_MANIFEST);
            $manifest = json_decode($raw, true, 16);
            self::requireValid(json_last_error() === JSON_ERROR_NONE && is_array($manifest) &&
                json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) === $raw,
                'Invalid or ambiguous manifest');
            self::fields($manifest, array('format', 'id', 'kind', 'from', 'version', 'from_schema', 'schema', 'files'));
            self::requireValid($manifest['format'] === 1 &&
                $manifest['id'] === $update['id'] && $manifest['kind'] === $update['kind'] &&
                $manifest['version'] === $update['version'] && $manifest['schema'] === $update['schema'] &&
                in_array($manifest['from'], $update['from'], true) &&
                in_array($manifest['from_schema'], $update['from_schema'], true) &&
                $manifest['from_schema'] === $manifest['schema'], 'Manifest does not match code-only update');
            $files = $manifest['files'];
            self::requireValid(is_array($files) && count($files) >= 1 && count($files) <= self::MAX_FILES &&
                array_keys($files) === range(0, count($files) - 1), 'Invalid manifest file list');
            $used = array();
            $total = 0;
            foreach ($files as $file) {
                self::fields($file, array('path', 'action', 'before', 'after', 'bytes'));
                self::allowedPath($file['path']);
                $path = strtolower($file['path']);
                self::requireValid(!isset($used[$path]), 'Duplicate manifest path');
                foreach (array_keys($used) as $other) {
                    self::requireValid(strpos($path, $other . '/') !== 0 && strpos($other, $path . '/') !== 0,
                        'Conflicting file and directory path');
                }
                $used[$path] = true;
                self::requireValid(($file['action'] === 'add' && $file['before'] === null) ||
                    ($file['action'] === 'replace' && self::digest($file['before']) && $file['before'] !== $file['after']),
                    'Invalid file action');
                self::requireValid(self::digest($file['after']) && is_int($file['bytes']) &&
                    $file['bytes'] >= 0 && $file['bytes'] <= self::MAX_FILE, 'Invalid file metadata');
                $name = 'files/' . $file['path'];
                self::requireValid(isset($entries[$name]) && $entries[$name][1] === $file['bytes'],
                    'Missing or mismatched payload');
                $total += $file['bytes'];
                self::requireValid($total <= self::MAX_TOTAL, 'Payload too large');
                $entry = $entries[$name];
                $bytes = self::entryBytes($zip, $entry[0], $entry[1], self::MAX_FILE);
                self::requireValid(hash('sha256', $bytes) === $file['after'], 'Payload digest mismatch');
                unset($entries[$name]);
            }
            self::requireValid(count($entries) === 1 && isset($entries['manifest.json']), 'Unlisted payload');
            return $manifest;
        } finally {
            $zip->close();
        }
    }
}
