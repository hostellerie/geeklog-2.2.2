<?php
namespace Geeklog;

require_once __DIR__ . '/UpdateCatalog.php';
require_once __DIR__ . '/UpdateTransactionLab.php';

/** Persist authenticated catalog sequence and digest in a disposable CLI lab. */
final class UpdateCatalogStateLab
{
    public static function accept($root, $json, $signature, $publicKey, $host, $now)
    {
        if (PHP_SAPI !== 'cli' || !is_string($root) || !is_dir($root) ||
            is_link(rtrim($root, '/\\'))) {
            throw new \RuntimeException('Disposable CLI laboratory required');
        }
        $root = realpath($root);
        $marker = $root . '/.geeklog-update-lab';
        if (is_link($marker) || !is_file($marker) ||
            file_get_contents($marker) !== UpdateTransactionLab::MARKER) {
            throw new \RuntimeException('Disposable CLI laboratory required');
        }
        $path = $root . '/.gl-catalog-state';
        $lockPath = $path . '.lock';
        if (is_link($lockPath) || (file_exists($lockPath) && !is_file($lockPath))) {
            throw new \RuntimeException('Invalid catalog lock');
        }
        $lock = fopen($lockPath, 'c+b');
        if ($lock === false) {
            throw new \RuntimeException('Cannot open catalog lock');
        }
        try {
            $stat = fstat($lock);
            if ($stat === false || $stat['nlink'] !== 1 || !chmod($lockPath, 0600) ||
                !flock($lock, LOCK_EX | LOCK_NB)) {
                throw new \RuntimeException('Catalog state unavailable');
            }
            $state = self::read($path);
            $catalog = UpdateCatalog::fromSigned($json, $signature, $publicKey, $host, $now,
                $state['sequence'], $state['digest']);
            $next = array('sequence' => $catalog->getSequence(), 'digest' => $catalog->getDigest());
            if ($state !== $next) {
                $bytes = json_encode($next);
                $random = openssl_random_pseudo_bytes(16, $strong);
                if ($random === false || !$strong) {
                    throw new \RuntimeException('Cannot generate catalog state identifier');
                }
                $tmp = $path . '.' . bin2hex($random);
                $file = fopen($tmp, 'xb');
                if ($file === false) {
                    throw new \RuntimeException('Cannot stage catalog state');
                }
                try {
                    try {
                        if (!chmod($tmp, 0600) || fwrite($file, $bytes) !== strlen($bytes) || !fflush($file)) {
                            throw new \RuntimeException('Cannot save catalog state');
                        }
                    } finally {
                        fclose($file);
                    }
                    if (self::read($path) !== $state || !rename($tmp, $path) || self::read($path) !== $next) {
                        throw new \RuntimeException('Cannot publish catalog state');
                    }
                } finally {
                    if (is_file($tmp) && !is_link($tmp)) {
                        unlink($tmp);
                    }
                }
            }
            return $catalog;
        } finally {
            fclose($lock);
        }
    }

    private static function read($path)
    {
        clearstatcache(true, $path);
        if (!file_exists($path) && !is_link($path)) {
            return array('sequence' => 0, 'digest' => '');
        }
        $stat = @stat($path);
        if (is_link($path) || !is_file($path) || $stat === false || $stat['nlink'] !== 1 ||
            $stat['size'] > 256 || ($stat['mode'] & 0077) !== 0) {
            throw new \RuntimeException('Invalid catalog state file');
        }
        $bytes = file_get_contents($path);
        $state = json_decode($bytes, true);
        if (!is_array($state) || array_keys($state) !== array('sequence', 'digest') ||
            json_encode($state) !== $bytes || !is_int($state['sequence']) || $state['sequence'] <= 0 ||
            !is_string($state['digest']) || preg_match('/^[0-9a-f]{64}$/D', $state['digest']) !== 1) {
            throw new \RuntimeException('Malformed catalog state');
        }
        return $state;
    }
}
