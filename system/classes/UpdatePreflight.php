<?php
namespace Geeklog;

require_once __DIR__ . '/UpdatePackage.php';

/**
 * Read-only preflight of an authenticated, locally staged code-only update.
 * This report is advisory: it is not a lock, backup or authorization to install.
 */
final class UpdatePreflight
{
    const RESERVE_BYTES = 16777216;

    private static function requireValid($condition, $message)
    {
        if (!$condition) {
            throw new \InvalidArgumentException($message);
        }
    }

    private static function roots(array $roots)
    {
        $names = array('public_html', 'system', 'plugins', 'language');
        self::requireValid(count($roots) === count($names), 'Invalid root map');
        $result = array();
        foreach ($names as $name) {
            self::requireValid(isset($roots[$name]) && is_string($roots[$name]) &&
                !is_link(rtrim($roots[$name], '/\\')), 'Missing or unsafe installation root');
            $real = realpath($roots[$name]);
            self::requireValid($real !== false && is_dir($real) && is_readable($real),
                'Unusable installation root');
            $suffix = DIRECTORY_SEPARATOR;
            $canonical = rtrim($real, '/\\') . $suffix;
            foreach ($result as $other) {
                // Conservative even on case-sensitive platforms.
                $a = strtolower($canonical);
                $b = strtolower($other);
                self::requireValid(strpos($a, $b) !== 0 && strpos($b, $a) !== 0,
                    'Installation roots overlap');
            }
            $result[$name] = $canonical;
        }
        return $result;
    }

    /**
     * Walk each component without following symlinks or accepting case aliases.
     * Nonexistent parent directories are deliberately out of scope.
     */
    private static function inspectFile(array $file, array $roots)
    {
        $parts = explode('/', $file['path']);
        $root = array_shift($parts);
        $parent = rtrim($roots[$root], '/\\');
        $last = array_pop($parts);
        $result = array('path' => $file['path'], 'action' => $file['action'],
            'status' => 'ready', 'before_bytes' => 0, 'after_bytes' => $file['bytes']);
        foreach (array_merge($parts, array($last)) as $i => $component) {
            $isLast = $i === count($parts);
            clearstatcache();
            $entries = @scandir($parent);
            if ($entries === false) {
                $result['status'] = 'parent_unreadable';
                return $result;
            }
            $exact = false;
            foreach ($entries as $entry) {
                if (strcasecmp($entry, $component) === 0) {
                    if ($entry !== $component) {
                        $result['status'] = 'case_collision';
                        return $result;
                    }
                    $exact = true;
                }
            }
            $path = $parent . DIRECTORY_SEPARATOR . $component;
            if (is_link($path)) {
                $result['status'] = 'symlink';
                return $result;
            }
            if (!$isLast) {
                if (!$exact || !is_dir($path)) {
                    $result['status'] = 'parent_missing_or_not_directory';
                    return $result;
                }
                $parent = $path;
                continue;
            }
            if ($file['action'] === 'add') {
                if ($exact || file_exists($path)) {
                    $result['status'] = 'target_exists';
                    return $result;
                }
            } else {
                if (!$exact || !is_file($path) || !is_readable($path)) {
                    $result['status'] = 'missing_or_nonregular';
                    return $result;
                }
                $stat = @stat($path);
                if ($stat === false || $stat['nlink'] !== 1 || $stat['size'] > UpdatePackage::MAX_FILE) {
                    $result['status'] = 'unsafe_existing_file';
                    return $result;
                }
                $result['before_bytes'] = $stat['size'];
                if (@hash_file('sha256', $path) !== $file['before']) {
                    $result['status'] = 'locally_modified';
                    return $result;
                }
                if (!is_writable($path)) {
                    $result['status'] = 'file_not_writable';
                    return $result;
                }
            }
            if (!is_writable($parent)) {
                $result['status'] = 'directory_not_writable';
                return $result;
            }
            $space = @disk_free_space($parent);
            $device = @stat($parent);
            if ($space === false || $device === false) {
                $result['status'] = 'disk_status_unavailable';
                return $result;
            }
            $result['device'] = (string) $device['dev'];
            $result['available_bytes'] = $space;
        }
        return $result;
    }

    /**
     * $update must be obtained from an authenticated catalog.
     * $sharedCode must explicitly be false for this first, single-site prototype.
     * The caller must map actual configured Geeklog roots (not guess paths).
     */
    public static function inspect($archivePath, array $update, array $rootMap,
        $installedCode, $installedSchema, $sharedCode)
    {
        $manifest = UpdatePackage::verify($archivePath, $update);
        $roots = self::roots($rootMap);
        $blockers = array();
        if ($installedCode !== $manifest['from'] || $installedSchema !== $manifest['from_schema']) {
            $blockers[] = 'installed_version_mismatch';
        }
        if ($sharedCode !== false) {
            $blockers[] = 'shared_code_or_multisite_unknown';
        }
        $files = array();
        $volumes = array();
        foreach ($manifest['files'] as $file) {
            $result = self::inspectFile($file, $roots);
            if ($result['status'] !== 'ready') {
                $blockers[] = $result['status'] . ': ' . $result['path'];
            } else {
                $device = $result['device'];
                $space = $result['available_bytes'];
                if (!isset($volumes[$device])) {
                    $volumes[$device] = array('required_bytes' => self::RESERVE_BYTES, 'available_bytes' => $space);
                }
                // Reserve the existing file backup and new staged bytes.
                $volumes[$device]['required_bytes'] += $result['before_bytes'] + $result['after_bytes'];
                $volumes[$device]['available_bytes'] = min($volumes[$device]['available_bytes'], $space);
                unset($result['device'], $result['available_bytes']);
            }
            $files[] = $result;
        }
        foreach ($volumes as $volume) {
            if ($volume['available_bytes'] < $volume['required_bytes']) {
                $blockers[] = 'insufficient_disk_space';
            }
        }
        return array('ready' => count($blockers) === 0, 'blockers' => $blockers,
            'files' => $files, 'volumes' => array_values($volumes),
            'update' => $manifest['id'], 'code_from' => $manifest['from'], 'code_to' => $manifest['version'],
            'schema' => $manifest['schema']);
    }
}
