<?php
namespace Geeklog;

require_once __DIR__ . '/UpdatePreflight.php';

/**
 * Experimental code-only transaction, restricted to disposable laboratory trees.
 * No Core bootstrap, production integration, version writes, SQL or production integration.
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
                    self::requireValid(self::matches($backup, $file['before']), 'Backup integrity mismatch');
                    $bytes = self::readPrivate($backup, UpdatePackage::MAX_FILE);
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
    public static function run($labRoot, $archivePath, array $update, $code, $schema, $failAfter = null, $crashAfter = null)
    {
        self::requireValid(PHP_SAPI === 'cli', 'Laboratory transaction is CLI-only');
        self::requireValid(is_string($labRoot) && !is_link(rtrim($labRoot, '/\\')), 'Invalid lab root');
        $lab = realpath($labRoot);
        self::requireValid($lab !== false && is_dir($lab), 'Invalid lab root');
        $marker = $lab . '/.geeklog-update-lab';
        self::requireValid(!is_link($marker) && is_file($marker) &&
            file_get_contents($marker) === self::MARKER, 'Disposable lab marker required');
        self::requireValid($failAfter === null || (is_int($failAfter) && $failAfter > 0), 'Invalid failure injection');
        self::requireValid($crashAfter === null || (is_int($crashAfter) && abs($crashAfter) <= UpdatePackage::MAX_FILES && function_exists('posix_kill')), 'Invalid crash injection');
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
            self::requireValid(count(self::pending($lab)) === 0, 'Interrupted transaction requires recovery');
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
            if ($crashAfter === 0) {
                posix_kill(getmypid(), 9);
                exit(91);
            }
            try {
                foreach ($prepared as $entry) {
                    $file = $entry['file'];
                    $path = self::destination($lab, $file['path']);
                    $bytes = file_get_contents($tx . '/stage-' . $entry['index']);
                    self::requireValid(is_string($bytes) && hash('sha256', $bytes) === $file['after'],
                        'Staged file changed');
                    self::journal($journal, 'applying', $file['path']);
                    if ($crashAfter === -(count($applied) + 1)) {
                        posix_kill(getmypid(), 9);
                        exit(91);
                    }
                    self::replace($path, $bytes, $file['before'], $entry['mode']);
                    $applied[] = $entry;
                    if ($crashAfter !== null && count($applied) === $crashAfter) {
                        posix_kill(getmypid(), 9);
                        exit(91);
                    }
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


    private static function readPrivate($path, $limit)
    {
        clearstatcache();
        $stat = @stat($path);
        self::requireValid(!is_link($path) && is_file($path) && $stat !== false &&
            $stat['nlink'] === 1 && $stat['size'] <= $limit, 'Invalid transaction metadata');
        $bytes = file_get_contents($path);
        self::requireValid(is_string($bytes) && strlen($bytes) === $stat['size'], 'Cannot read transaction metadata');
        return $bytes;
    }

    private static function records($tx)
    {
        $bytes = self::readPrivate($tx . '/journal.jsonl', 1048576);
        self::requireValid(substr($bytes, -1) === "\n", 'Incomplete journal');
        $records = array();
        foreach (explode("\n", rtrim($bytes, "\n")) as $line) {
            $record = json_decode($line, true);
            self::requireValid(is_array($record) && count($record) === 2 &&
                isset($record['event'], $record['path']) && is_string($record['path']) &&
                in_array($record['event'], array('begin', 'staged', 'prepared', 'applying',
                    'applied', 'restored', 'committed', 'rolled_back', 'recovery_required'), true),
                'Invalid journal record');
            $records[] = $record;
        }
        self::requireValid(count($records) <= 5 * UpdatePackage::MAX_FILES + 10 &&
            $records[0]['event'] === 'begin' && $records[0]['path'] !== '', 'Invalid journal start or length');
        self::validateSequence($records);
        return $records;
    }


    // Validate even archived terminal journals; a terminal label alone is not
    // sufficient evidence that a transaction finished.
    private static function validateSequence(array $records)
    {
        $staged = array();
        $intent = array();
        $restored = array();
        $prepared = false;
        $restoring = false;
        $awaitApplied = false;
        $index = 0;
        $terminal = false;
        foreach (array_slice($records, 1) as $record) {
            $event = $record['event'];
            $path = $record['path'];
            self::requireValid(!$terminal, 'Record after terminal journal event');
            if (!$prepared) {
                if ($event === 'staged') {
                    self::requireValid($path !== '' && !in_array($path, $staged, true) &&
                        count($staged) < UpdatePackage::MAX_FILES, 'Invalid staged path');
                    $staged[] = $path;
                } elseif ($event === 'prepared') {
                    self::requireValid($path === '' && count($staged) > 0, 'Invalid prepared event');
                    $prepared = true;
                } else {
                    throw new \RuntimeException('Unexpected preparation event');
                }
            } elseif (!$restoring && $event === ($awaitApplied ? 'applied' : 'applying')) {
                self::requireValid(isset($staged[$index]) && $staged[$index] === $path, 'Invalid application order');
                $intent[$path] = true;
                if ($awaitApplied) {
                    $index++;
                }
                $awaitApplied = !$awaitApplied;
            } elseif ($event === 'restored') {
                self::requireValid(isset($intent[$path]) && !isset($restored[$path]), 'Invalid restoration event');
                $restored[$path] = true;
                $restoring = true;
            } elseif ($event === 'recovery_required') {
                self::requireValid($path === '', 'Invalid recovery marker');
                $restoring = true;
            } elseif ($event === 'committed') {
                self::requireValid($path === '' && !$restoring && !$awaitApplied &&
                    $index === count($staged), 'Premature commit marker');
                $terminal = true;
            } elseif ($event === 'rolled_back') {
                // A crash between intent and replacement may leave no change.
                // Every confirmed application must have been restored.
                self::requireValid($path === '', 'Invalid rollback marker');
                foreach (array_slice($staged, 0, $index) as $appliedPath) {
                    self::requireValid(isset($restored[$appliedPath]), 'Rollback missing confirmed file');
                }
                $terminal = true;
            } else {
                throw new \RuntimeException('Unexpected journal event');
            }
        }
        return $restored;
    }

    private static function pending($lab)
    {
        $pending = array();
        foreach (scandir($lab) as $name) {
            if (strpos($name, '.gl-transaction-') !== 0) {
                continue;
            }
            $tx = $lab . '/' . $name;
            self::requireValid(preg_match('/^\.gl-transaction-[a-f0-9]{32}$/D', $name) === 1 &&
                !is_link($tx) && is_dir($tx), 'Invalid transaction directory');
            $records = self::records($tx);
            $last = end($records);
            if (!in_array($last['event'], array('committed', 'rolled_back'), true) || $last['path'] !== '') {
                $pending[] = $tx;
            }
        }
        return $pending;
    }

    /**
     * Recover an interrupted prepared transaction, using the original signed
     * catalog record and verified archive. Never infer paths from the journal.
     * Unknown file bytes or incomplete metadata require manual intervention.
     */
    public static function recover($labRoot, $archivePath, array $update)
    {
        self::requireValid(PHP_SAPI === 'cli', 'Laboratory recovery is CLI-only');
        self::requireValid(is_string($labRoot) && !is_link(rtrim($labRoot, '/\\')), 'Invalid lab root');
        $lab = realpath($labRoot);
        self::requireValid($lab !== false && is_dir($lab), 'Invalid lab root');
        self::requireValid(self::readPrivate($lab . '/.geeklog-update-lab', 128) === self::MARKER,
            'Disposable lab marker required');
        $lockPath = $lab . '/.geeklog-update.lock';
        self::requireValid(!is_link($lockPath) && is_file($lockPath), 'Invalid lock path');
        $lock = @fopen($lockPath, 'r+b');
        self::requireValid($lock !== false, 'Cannot open lab lock');
        $journal = null;
        try {
            self::requireValid(flock($lock, LOCK_EX | LOCK_NB), 'Update already running');
            $pending = self::pending($lab);
            if (count($pending) === 0) {
                return array('status' => 'nothing_to_recover');
            }
            self::requireValid(count($pending) === 1, 'Multiple interrupted transactions require manual review');
            $tx = $pending[0];
            $manifest = UpdatePackage::verify($archivePath, $update);
            self::requireValid(json_decode(self::readPrivate($tx . '/manifest.json', UpdatePackage::MAX_MANIFEST), true)
                === $manifest, 'Stored manifest mismatch');
            $prepared = json_decode(self::readPrivate($tx . '/prepared.json', 524288), true);
            self::requireValid(is_array($prepared) && count($prepared) === count($manifest['files']), 'Incomplete preparation');
            $records = self::records($tx);
            $prefix = array(array('event' => 'begin', 'path' => $manifest['id']));
            foreach ($manifest['files'] as $file) {
                $prefix[] = array('event' => 'staged', 'path' => $file['path']);
            }
            $prefix[] = array('event' => 'prepared', 'path' => '');
            self::requireValid(array_slice($records, 0, count($prefix)) === $prefix, 'Invalid preparation journal');
            $tail = array_slice($records, count($prefix));
            $intent = array();
            $index = 0;
            $awaitApplied = false;
            $restoring = false;
            foreach ($tail as $record) {
                $event = $record['event'];
                $path = $record['path'];
                if ($event === 'restored' || $event === 'recovery_required') {
                    $restoring = true;
                    self::requireValid($event === 'restored' ? isset($intent[$path]) : $path === '', 'Invalid recovery journal');
                } elseif (!$restoring && $event === ($awaitApplied ? 'applied' : 'applying')) {
                    self::requireValid(isset($manifest['files'][$index]) &&
                        $path === $manifest['files'][$index]['path'], 'Unexpected journal path');
                    $intent[$path] = true;
                    if ($awaitApplied) {
                        $index++;
                    }
                    $awaitApplied = !$awaitApplied;
                } else {
                    throw new \RuntimeException('Unexpected journal sequence');
                }
            }
            $restored = self::validateSequence($records);
            $unchangedIntent = array();
            $changed = array();
            foreach ($manifest['files'] as $i => $file) {
                $entry = isset($prepared[$i]) ? $prepared[$i] : null;
                self::requireValid(is_array($entry) && count($entry) === 3 &&
                    isset($entry['index'], $entry['file'], $entry['mode']) &&
                    $entry['index'] === $i && $entry['file'] === $file &&
                    is_int($entry['mode']) && $entry['mode'] >= 0 && $entry['mode'] <= 0777,
                    'Invalid prepared entry');
                $path = self::destination($lab, $file['path']);
                if ($file['action'] === 'replace') {
                    self::requireValid(self::matches($tx . '/backup-' . $i, $file['before']), 'Backup integrity mismatch');
                }
                if (self::matches($path, $file['before'])) {
                    if ($file['action'] === 'replace') {
                        self::requireValid((fileperms($path) & 0777) === $entry['mode'], 'Original permissions changed');
                    }
                    if (isset($intent[$file['path']]) && !isset($restored[$file['path']])) {
                        $unchangedIntent[] = $file['path'];
                    }
                    continue;
                }
                self::requireValid(!isset($restored[$file['path']]), 'Previously restored file changed');
                self::requireValid(isset($intent[$file['path']]) && self::matches($path, $file['after']),
                    'Unknown destination state; recovery refused');
                self::requireValid((fileperms($path) & 0777) === $entry['mode'], 'Updated permissions changed');
                $changed[] = $entry;
            }
            // Validate everything before the first write.
            $journal = fopen($tx . '/journal.jsonl', 'ab');
            self::requireValid($journal !== false, 'Cannot reopen journal');
            foreach ($unchangedIntent as $path) {
                self::journal($journal, 'restored', $path);
            }
            $errors = self::restore($lab, $changed, $tx, $journal);
            $status = count($errors) === 0 ? 'rolled_back' : 'recovery_required';
            self::journal($journal, $status);
            return array('status' => $status, 'transaction' => $tx, 'rollback_errors' => $errors);
        } finally {
            if (is_resource($journal)) {
                fclose($journal);
            }
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
