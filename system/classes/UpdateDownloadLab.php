<?php
namespace Geeklog;

require_once __DIR__ . '/UpdateCatalog.php';
require_once __DIR__ . '/UpdatePackage.php';
require_once __DIR__ . '/UpdateTransactionLab.php';

/** HTTPS staging for a disposable CLI laboratory. No installation. */
final class UpdateDownloadLab
{
    public static function stage($root, UpdateCatalog $catalog, $id, $code, $schema, $php, $database, array $applied = array())
    {
        if (PHP_SAPI !== 'cli' || !function_exists('curl_init') || !is_string($root) ||
            is_link(rtrim($root, '/\\')) || !is_dir($root)) {
            throw new \RuntimeException('Unsupported download laboratory');
        }
        $root = realpath($root);
        $marker = $root . '/.geeklog-update-lab';
        if (is_link($marker) || !is_file($marker) || file_get_contents($marker) !== UpdateTransactionLab::MARKER) {
            throw new \RuntimeException('Disposable laboratory required');
        }
        $selected = null;
        foreach ($catalog->applicable($code, $schema, $php, $database, $applied) as $update) {
            if ($update['id'] === $id) {
                $selected = $update;
                break;
            }
        }
        if ($selected === null || $selected['package']['bytes'] > UpdatePackage::MAX_ARCHIVE) {
            throw new \RuntimeException('Update unavailable or archive too large');
        }
        $random = openssl_random_pseudo_bytes(16, $strong);
        if ($random === false || !$strong) {
            throw new \RuntimeException('Cannot generate staging identifier');
        }
        $directory = $root . '/.gl-download-' . bin2hex($random);
        if (!mkdir($directory, 0700)) {
            throw new \RuntimeException('Cannot create private staging directory');
        }
        $path = $directory . '/package.zip';
        $file = null;
        $curl = null;
        $success = false;
        try {
            $file = fopen($path, 'xb');
            if ($file === false || !chmod($path, 0600)) {
                throw new \RuntimeException('Cannot open private staging file');
            }
            $package = $selected['package'];
            $received = 0;
            $curl = curl_init($package['url']);
            if ($curl === false || !curl_setopt_array($curl, array(
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_PROXY => '',
                CURLOPT_HTTPHEADER => array('Accept: application/zip', 'Accept-Encoding: identity'),
                CURLOPT_WRITEFUNCTION => function ($handle, $bytes) use ($file, $package, &$received) {
                    $length = strlen($bytes);
                    if ($length > $package['bytes'] - $received || fwrite($file, $bytes) !== $length) {
                        return 0;
                    }
                    $received += $length;
                    return $length;
                }
            ))) {
                throw new \RuntimeException('Cannot configure secure transport');
            }
            if (curl_exec($curl) !== true || curl_getinfo($curl, CURLINFO_HTTP_CODE) !== 200 ||
                $received !== $package['bytes'] || !fflush($file)) {
                throw new \RuntimeException('Package download failed');
            }
            fclose($file);
            $file = null;
            clearstatcache(true, $path);
            UpdatePackage::verify($path, $selected);
            $success = true;
            return $path;
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
            if ($curl !== null && $curl !== false && PHP_VERSION_ID < 80500) {
                curl_close($curl);
            }
            if (!$success) {
                if (is_file($path)) {
                    unlink($path);
                }
                rmdir($directory);
            }
        }
    }
}
