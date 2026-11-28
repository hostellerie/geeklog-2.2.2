<?php
namespace Geeklog;

require_once __DIR__ . '/UpdatePreflight.php';

/**
 * Experimental code-only transaction, restricted to disposable laboratory trees.
 * No Core bootstrap, production integration, version writes, SQL or crash recovery.
 */
final class UpdateTransactionLab
{
    const MARKER = 'geeklog-update-laboratory-v1';

    private static function requireValid($condition, $message)
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    private static function checkedWrite($path, $bytes)
    {
        $handle = @fopen($path, 'xb');
        self::requireValid($handle !== false, 'Cannot create transaction file');
        try {
            $length = strlen($bytes);
            $offset = 0;
            while ($offset < $length) {
                $written = fwrite($handle, substr($bytes, $offset));
                self::requireValid($written !== false && $written > 0, 'Short transaction write');
                $offset += $written;
            }
            self::requireValid(fflush($handle), 'Cannot flush transaction file');
        } finally {
            fclose($handle);
        }
        self::requireValid(hash_file('sha256', $path) === hash('sha256', $bytes), 'Transaction file hash mismatch');
    }

    private static function randomName()
    {
        $bytes = openssl_random_pseudo_bytes(16, $strong);
        self::requireValid($bytes !== false && $strong, 'Secure random unavailable');
        return bin2hex($bytes);
    }

    private static function journal($handle, $event, $path = '')
    {
        $line = json_encode(array('event' => $event, 'path' => $path)) . "\n";
        self::requireValid(fwrite($handle, $line) === strlen($line) && fflush($handle), 'Journal write failed');
    }

    private static function destination($lab, $relative)
    {
        // The manifest was validated by UpdatePackage. Recheck every parent.
        $parts = explode('/', $relative);
        $path = $lab;
        foreach ($parts as $i => $part) {
            $path .= DIRECTORY_SEPARATOR . $part;
            clearstatcache();
            self::requireValid(!is_link($path), 'Symbolic path during transaction');
            if ($i < count($parts) - 1) {
                self::requireValid(is_dir($path), 'Missing transaction parent');
            }
        }
        return $path;
    }

    private static function matches($path, $digest)
    {
        clearstatcache();
        if ($digest === null) {
            return !file_exists($path) && !is_link($path);
        }
        $stat = @stat($path);
        return !is_link($path) && is_file($path) && $stat !== false &&
            $stat['nlink'] === 1 && $stat['size'] <= UpdatePackage::MAX_FILE &&
            @hash_file('sha256', $path) === $digest;
    }

    /**
     * Stage a temporary sibling, verify bytes, then install. An addition uses
     * link() so an existing destination cannot be silently overwritten.
     */
    private static function replace($destination, $bytes, $expected, $mode)
    {
        $tmp = dirname($destination) . DIRECTORY_SEPARATOR . '.gl-update-' . self::randomName();
        try {
            self::checkedWrite($tmp, $bytes);
            self::requireValid(chmod($tmp, $mode), 'Cannot set staged permissions');
            self::requireValid(self::matches($destination, $expected), 'Destination changed after preflight');
            if ($expected === null) {
                self::requireValid(@link($tmp, $destination), 'Cannot create absent target');
                // The destination is committed once link succeeds. The caller
                // records it before any further fallible operation.
                @unlink($tmp);
            } else {
                self::requireValid(@rename($tmp, $destination), 'Atomic replacement failed');
            }
        } finally {
            if (is_file($tmp) && !is_link($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private static function restore($lab, array $applied, $tx, $journal)
    {
        $errors = array();
        foreach (array_reverse($applied) as $entry) {
            try {
                $file = $entry['file'];
                $path = self::destination($lab, $file['path']);
                self::requireValid(self::matches($path, $file['after']), 'Updated file changed; refuse unsafe rollback');
                if ($file['action'] === 'add') {
                    self::requireValid(@unlink($path), 'Cannot remove added file');
                    self::requireValid(self::matches($path, null), 'Added file still exists');
                } else {
                    $backup = $tx . '/backup-' . $entry['index'];
                    $bytes = file_get_contents($backup);
                    self::requireValid(is_string($bytes) && hash('sha256', $bytes) === $file['before'],
                        'Backup integrity mismatch');
                    self::replace($path, $bytes, $file['after'], $entry['mode']);
                    self::requireValid(self::matches($path, $file['before']), 'Restored file integrity mismatch');
                }
                self::journal($journal, 'restored', $file['path']);
            } catch (\Exception $error) {
                $errors[] = $entry['file']['path'] . ': ' . $error->getMessage();
            } catch (\Throwable $error) {
                $errors[] = $entry['file']['path'] . ': ' . $error->getMessage();
            }
        }
        return $errors;
    }

    /**
     * $update is from a verified catalog. $failAfter is a laboratory-only
     * fault injection count; no production entrypoint calls this class.
     */
    public static function run($labRoot, $archivePath, array $update, $code, $schema, $failAfter = null)
    {
        self::requireValid(is_string($labRoot) && !is_link(rtrim($labRoot, '/\\')), 'Invalid lab root');
        $lab = realpath($labRoot);
        self::requireValid($lab !== false && is_dir($lab), 'Invalid lab root');
        $marker = $lab . '/.geeklog-update-lab';
        self::requireValid(!is_link($marker) && is_file($marker) &&
            file_get_contents($marker) === self::MARKER, 'Disposable lab marker required');
        self::requireValid($failAfter === null || (is_int($failAfter) && $failAfter > 0), 'Invalid failure injection');
        $lockPath = $lab . '/.geeklog-update.lock';
        self::requireValid(!is_link($lockPath) && (!file_exists($lockPath) || is_file($lockPath)), 'Invalid lock path');
        $lock = @fopen($lockPath, 'c+b');
        self::requireValid($lock !== false, 'Cannot open lab lock');
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new \RuntimeException('Update already running');
        }
        $journal = null;
        $zip = null;
        try {
            $roots = array();
            foreach (array('public_html', 'system', 'plugins', 'language') as $root) {
                $roots[$root] = $lab . '/' . $root;
            }
            $report = UpdatePreflight::inspect($archivePath, $update, $roots, $code, $schema, false);
            self::requireValid($report['ready'], 'Preflight blocked: ' . implode(', ', $report['blockers']));
            $manifest = UpdatePackage::verify($archivePath, $update);
            $tx = $lab . '/.gl-transaction-' . self::randomName();
            self::requireValid(mkdir($tx, 0700), 'Cannot create private transaction');
            $journal = fopen($tx . '/journal.jsonl', 'xb');
            self::requireValid($journal !== false, 'Cannot create journal');
            self::journal($journal, 'begin', $manifest['id']);
            self::checkedWrite($tx . '/manifest.json', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $zip = new \ZipArchive();
            self::requireValid($zip->open($archivePath, \ZipArchive::CHECKCONS) === true, 'Cannot reopen verified archive');
            $prepared = array();
            foreach ($manifest['files'] as $i => $file) {
                $path = self::destination($lab, $file['path']);
                self::requireValid(self::matches($path, $file['before']), 'Original changed while staging');
                $mode = 0644;
                if ($file['action'] === 'replace') {
                    $mode = fileperms($path) & 0777;
                    $original = file_get_contents($path);
                    self::requireValid(is_string($original) && hash('sha256', $original) === $file['before'],
                        'Original hash mismatch');
                    self::checkedWrite($tx . '/backup-' . $i, $original);
                }
                $bytes = $zip->getFromName('files/' . $file['path'], $file['bytes'] + 1);
                self::requireValid(is_string($bytes) && strlen($bytes) === $file['bytes'] &&
                    hash('sha256', $bytes) === $file['after'], 'Staged payload hash mismatch');
                self::checkedWrite($tx . '/stage-' . $i, $bytes);
                $prepared[] = array('index' => $i, 'file' => $file, 'mode' => $mode);
                self::journal($journal, 'staged', $file['path']);
            }
            $zip->close();
            $zip = null;
            // Record the mode/indices needed for later manual recovery.
            self::checkedWrite($tx . '/prepared.json', json_encode($prepared, JSON_UNESCAPED_SLASHES));
            self::journal($journal, 'prepared');
            $applied = array();
            try {
                foreach ($prepared as $entry) {
                    $file = $entry['file'];
                    $path = self::destination($lab, $file['path']);
                    $bytes = file_get_contents($tx . '/stage-' . $entry['index']);
                    self::requireValid(is_string($bytes) && hash('sha256', $bytes) === $file['after'],
                        'Staged file changed');
                    self::journal($journal, 'applying', $file['path']);
                    self::replace($path, $bytes, $file['before'], $entry['mode']);
                    $applied[] = $entry;
                    self::requireValid(self::matches($path, $file['after']), 'Installed file hash mismatch');
                    self::journal($journal, 'applied', $file['path']);
                    if ($failAfter !== null && count($applied) === $failAfter) {
                        throw new \RuntimeException('Simulated failure');
                    }
                }
                self::journal($journal, 'committed');
                return array('status' => 'committed', 'transaction' => $tx, 'applied' => count($applied));
            } catch (\Exception $error) {
                return self::failed($lab, $applied, $tx, $journal, $error);
            } catch (\Throwable $error) {
                return self::failed($lab, $applied, $tx, $journal, $error);
            }
        } finally {
            if ($zip !== null) {
                @$zip->close();
            }
            if (is_resource($journal)) {
                fclose($journal);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function failed($lab, array $applied, $tx, $journal, $error)
    {
        $errors = self::restore($lab, $applied, $tx, $journal);
        $status = count($errors) === 0 ? 'rolled_back' : 'recovery_required';
        self::journal($journal, $status);
        return array('status' => $status, 'transaction' => $tx, 'applied' => count($applied),
            'error' => $error->getMessage(), 'rollback_errors' => $errors);
    }
}
