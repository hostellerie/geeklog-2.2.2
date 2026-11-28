<?php
namespace Geeklog;

require_once __DIR__ . '/UpdatePackage.php';

/** Syntax-only preinstallation check. It does not execute update payloads. */
final class UpdateHealthLab
{
    public static function lint($archive, array $update)
    {
        if (PHP_SAPI !== 'cli' || !function_exists('exec') || !is_file(PHP_BINARY)) {
            throw new \RuntimeException('CLI syntax checker unavailable');
        }
        $manifest = UpdatePackage::verify($archive, $update);
        $zip = new \ZipArchive();
        if ($zip->open($archive, \ZipArchive::CHECKCONS) !== true) {
            throw new \RuntimeException('Cannot reopen verified archive');
        }
        try {
            foreach ($manifest['files'] as $file) {
                if (!preg_match('/\.(php[0-9]*|phtml|inc)$/iD', $file['path'])) {
                    continue;
                }
                $bytes = $zip->getFromName('files/' . $file['path']);
                if (!is_string($bytes) || hash('sha256', $bytes) !== $file['after']) {
                    throw new \RuntimeException('Payload changed before syntax check');
                }
                $temp = tmpfile();
                if ($temp === false) {
                    throw new \RuntimeException('Cannot stage PHP syntax check');
                }
                try {
                    $meta = stream_get_meta_data($temp);
                    if (fwrite($temp, $bytes) !== strlen($bytes) || !fflush($temp) ||
                        !chmod($meta['uri'], 0600)) {
                        throw new \RuntimeException('Cannot write syntax fixture');
                    }
                    $output = array();
                    $status = 1;
                    exec(escapeshellarg(PHP_BINARY) . ' -n -l ' . escapeshellarg($meta['uri']) . ' 2>&1',
                        $output, $status);
                    if ($status !== 0) {
                        throw new \RuntimeException('PHP syntax check failed: ' . $file['path']);
                    }
                } finally {
                    fclose($temp);
                }
            }
        } finally {
            $zip->close();
        }
    }
}
