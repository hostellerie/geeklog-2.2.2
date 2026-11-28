<?php
namespace Geeklog;

require_once __DIR__ . '/UpdateTransactionLab.php';

/**
 * Experimental request-draining gate for a marked, disposable Linux lab.
 * Not called by the Core bootstrap. Every cooperating request must hold its
 * shared lease until completion; a persistent marker remains after process death.
 */
final class UpdateMaintenanceLab
{
    private static function root($root)
    {
        if (!is_string($root) || is_link(rtrim($root, '/\\')) || !is_dir($root)) {
            throw new \RuntimeException('Invalid laboratory root');
        }
        $root = realpath($root);
        $marker = $root . '/.geeklog-update-lab';
        if (is_link($marker) || !is_file($marker) ||
            file_get_contents($marker) !== UpdateTransactionLab::MARKER) {
            throw new \RuntimeException('Disposable laboratory required');
        }
        return $root;
    }

    private static function lock($root, $mode)
    {
        $path = $root . '/.gl-maintenance.lock';
        clearstatcache();
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new \RuntimeException('Invalid maintenance lock');
        }
        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open maintenance lock');
        }
        $stat = fstat($handle);
        if ($stat === false || $stat['nlink'] !== 1 || !flock($handle, $mode | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        return $handle;
    }

    private static function marker($root)
    {
        return $root . '/.gl-maintenance-active';
    }

    private static function active($root)
    {
        clearstatcache();
        $path = self::marker($root);
        // Any object at this path blocks requests, including malformed markers.
        return file_exists($path) || is_link($path);
    }

    /**
     * A null lease means service unavailable. Caller must hold the returned
     * resource for the entire request, not only during bootstrap.
     */
    public static function tryRequest($root)
    {
        $root = self::root($root);
        $lease = self::lock($root, LOCK_SH);
        if ($lease === null) {
            return null;
        }
        if (self::active($root)) {
            fclose($lease);
            return null;
        }
        return $lease;
    }

    private static function acquire($root)
    {
        if (PHP_SAPI !== 'cli') {
            throw new \RuntimeException('Maintenance laboratory is CLI-only');
        }
        $lease = self::lock($root, LOCK_EX);
        if ($lease === null) {
            throw new \RuntimeException('Requests or maintenance still active');
        }
        return $lease;
    }

    public static function run($root, $archive, array $update, $code, $schema, $failAfter = null, $crashAfter = null)
    {
        $root = self::root($root);
        $lease = self::acquire($root);
        try {
            if (self::active($root)) {
                throw new \RuntimeException('Maintenance already active');
            }
            $random = openssl_random_pseudo_bytes(16, $strong);
            if ($random === false || !$strong) {
                throw new \RuntimeException('Cannot generate transaction identifier');
            }
            $id = bin2hex($random);
            $state = array('format' => 1, 'transaction' => $id, 'update' => $update['id'],
                'sha256' => $update['package']['sha256'], 'code' => $code, 'schema' => $schema);
            $marker = @fopen(self::marker($root), 'xb');
            if ($marker === false) {
                throw new \RuntimeException('Cannot create maintenance marker');
            }
            try {
                $bytes = json_encode($state);
                if (fwrite($marker, $bytes) !== strlen($bytes) || !fflush($marker)) {
                    throw new \RuntimeException('Cannot write maintenance marker');
                }
            } finally {
                fclose($marker);
            }
            $result = UpdateTransactionLab::run($root, $archive, $update, $code, $schema, $failAfter, $crashAfter, $id);
            if ($result['status'] === 'rolled_back') {
                self::reopen($root, $state);
            }
            // A committed code update remains offline until a future verified
            // version/health finalization step. Never reopen on exceptions.
            return $result;
        } finally {
            fclose($lease);
        }
    }

    private static function readState($path)
    {
        clearstatcache();
        $stat = @stat($path);
        if (is_link($path) || !is_file($path) || $stat === false ||
            $stat['nlink'] !== 1 || $stat['size'] > 4096) {
            throw new \RuntimeException('Invalid state file');
        }
        $state = json_decode(file_get_contents($path), true);
        if (!is_array($state)) {
            throw new \RuntimeException('Malformed state file');
        }
        return $state;
    }

    private static function state($root, array $update)
    {
        $state = self::readState(self::marker($root));
        if (count($state) !== 6 || !isset($state['format'], $state['transaction'], $state['update'],
            $state['sha256'], $state['code'], $state['schema']) || $state['format'] !== 1 ||
            !is_string($state['transaction']) || preg_match('/^[a-f0-9]{32}$/D', $state['transaction']) !== 1 ||
            $state['update'] !== $update['id'] || $state['sha256'] !== $update['package']['sha256'] ||
            !in_array($state['code'], $update['from'], true) ||
            !in_array($state['schema'], $update['from_schema'], true)) {
            throw new \RuntimeException('Maintenance marker does not match update');
        }
        return $state;
    }

    private static function reopen($root, array $state)
    {
        $path = self::marker($root);
        if (self::readState($path) !== $state || !unlink($path)) {
            throw new \RuntimeException('Cannot safely clear maintenance marker');
        }
    }

    /**
     * Finalize code-only releases in the laboratory version-state fixture.
     * Real siteconfig/database writes and runtime health checks are not wired.
     */
    public static function finalize($root, $archive, array $update)
    {
        $root = self::root($root);
        $lease = self::acquire($root);
        try {
            $state = self::state($root, $update);
            if ($update['kind'] !== 'release' || $update['schema'] !== $state['schema']) {
                throw new \RuntimeException('Only code-only releases can be finalized');
            }
            UpdateTransactionLab::verifyCommitted($root, $archive, $update, $state['transaction']);
            $path = $root . '/.gl-lab-version.json';
            $old = array('code' => $state['code'], 'schema' => $state['schema']);
            $new = array('code' => $update['version'], 'schema' => $state['schema']);
            $current = self::readState($path);
            if ($current !== $old && $current !== $new) {
                throw new \RuntimeException('Unexpected laboratory version');
            }
            if ($current === $old) {
                $random = openssl_random_pseudo_bytes(16, $strong);
                if ($random === false || !$strong) {
                    throw new \RuntimeException('Cannot stage version state');
                }
                $tmp = $path . '.' . bin2hex($random);
                $handle = @fopen($tmp, 'xb');
                if ($handle === false) {
                    throw new \RuntimeException('Cannot stage version state');
                }
                try {
                    $bytes = json_encode($new);
                    if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) {
                        throw new \RuntimeException('Cannot write version state');
                    }
                } finally {
                    fclose($handle);
                }
                try {
                    if (self::readState($path) !== $old || self::readState($tmp) !== $new ||
                        !chmod($tmp, fileperms($path) & 0777) || !rename($tmp, $path)) {
                        throw new \RuntimeException('Cannot replace version state');
                    }
                } finally {
                    if (is_file($tmp) && !is_link($tmp)) {
                        @unlink($tmp);
                    }
                }
            }
            if (self::readState($path) !== $new) {
                throw new \RuntimeException('Version verification failed');
            }
            self::reopen($root, $state);
            return array('status' => 'finalized', 'version' => $new);
        } finally {
            fclose($lease);
        }
    }

    public static function recover($root, $archive, array $update)
    {
        $root = self::root($root);
        $lease = self::acquire($root);
        try {
            $state = self::state($root, $update);
            $result = UpdateTransactionLab::recover($root, $archive, $update, $state['transaction']);
            if ($result['status'] === 'rolled_back') {
                self::reopen($root, $state);
            }
            // No pending transaction is ambiguous (e.g. crash after commit).
            // Preserve maintenance for manual/next-phase verification.
            return $result;
        } finally {
            fclose($lease);
        }
    }
}
