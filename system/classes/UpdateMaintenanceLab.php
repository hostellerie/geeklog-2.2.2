<?php
namespace Geeklog;

require_once __DIR__ . '/UpdateTransactionLab.php';

/**
 * Experimental request-draining gate for a marked, disposable Linux lab.
 * Not called by the Core bootstrap. Every cooperating request must hold its
 * shared lease until completion; a durable marker remains after process death.
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
            $marker = @fopen(self::marker($root), 'xb');
            if ($marker === false) {
                throw new \RuntimeException('Cannot create maintenance marker');
            }
            try {
                $bytes = "geeklog-update-maintenance-v1\n";
                if (fwrite($marker, $bytes) !== strlen($bytes) || !fflush($marker)) {
                    throw new \RuntimeException('Cannot write maintenance marker');
                }
            } finally {
                fclose($marker);
            }
            $result = UpdateTransactionLab::run($root, $archive, $update, $code, $schema, $failAfter, $crashAfter);
            if ($result['status'] === 'rolled_back') {
                self::reopen($root);
            }
            // A committed code update remains offline until a future verified
            // version/health finalization step. Never reopen on exceptions.
            return $result;
        } finally {
            fclose($lease);
        }
    }

    private static function reopen($root)
    {
        $path = self::marker($root);
        if (is_link($path) || !is_file($path) ||
            file_get_contents($path) !== "geeklog-update-maintenance-v1\n" ||
            !unlink($path)) {
            throw new \RuntimeException('Cannot safely clear maintenance marker');
        }
    }

    public static function recover($root, $archive, array $update)
    {
        $root = self::root($root);
        $lease = self::acquire($root);
        try {
            if (!self::active($root)) {
                throw new \RuntimeException('Maintenance not active');
            }
            $result = UpdateTransactionLab::recover($root, $archive, $update);
            if ($result['status'] === 'rolled_back') {
                self::reopen($root);
            }
            // No pending transaction is ambiguous (e.g. crash after commit).
            // Preserve maintenance for manual/next-phase verification.
            return $result;
        } finally {
            fclose($lease);
        }
    }
}
