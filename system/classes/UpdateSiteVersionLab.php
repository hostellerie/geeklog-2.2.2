<?php
namespace Geeklog;

require_once __DIR__ . '/UpdateTransactionLab.php';

/**
 * Read/replace the canonical VERSION declaration of a disposable Geeklog
 * siteconfig.php without evaluating PHP or changing any other bytes.
 */
final class UpdateSiteVersionLab
{
    private static function valid($version)
    {
        return is_string($version) && preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:\.[0-9]+)?$/D', $version) === 1;
    }

    private static function inspect($root)
    {
        if (PHP_SAPI !== 'cli' || !is_string($root) || realpath($root) !== $root ||
            is_link($root . '/.geeklog-update-lab') ||
            !is_file($root . '/.geeklog-update-lab') ||
            file_get_contents($root . '/.geeklog-update-lab') !== UpdateTransactionLab::MARKER ||
            is_link($root . '/public_html') || !is_dir($root . '/public_html')) {
            throw new \RuntimeException('Disposable CLI laboratory required');
        }
        $path = $root . '/public_html/siteconfig.php';
        clearstatcache();
        $stat = @stat($path);
        if (is_link($path) || !is_file($path) || $stat === false ||
            $stat['nlink'] !== 1 || $stat['size'] > 1048576) {
            throw new \RuntimeException('Invalid siteconfig');
        }
        $bytes = file_get_contents($path);
        if (!is_string($bytes) || strlen($bytes) !== $stat['size']) {
            throw new \RuntimeException('Cannot read siteconfig');
        }
        $tokens = array();
        $offset = 0;
        foreach (token_get_all($bytes) as $token) {
            $value = is_array($token) ? $token[1] : $token;
            if (!is_array($token) || !in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                $tokens[] = array('type' => is_array($token) ? $token[0] : null,
                    'value' => $value, 'offset' => $offset);
            }
            $offset += strlen($value);
        }
        $found = array();
        foreach ($tokens as $i => $token) {
            if ($token['type'] !== T_CONSTANT_ENCAPSED_STRING ||
                !in_array($token['value'], array("'VERSION'", '"VERSION"'), true)) {
                continue;
            }
            // Refuse ambiguous references rather than interpreting dynamic PHP.
            if ($i < 2 || !isset($tokens[$i + 4]) ||
                strtolower($tokens[$i - 2]['value']) !== 'define' ||
                $tokens[$i - 1]['value'] !== '(' || $tokens[$i + 1]['value'] !== ',' ||
                $tokens[$i + 2]['type'] !== T_CONSTANT_ENCAPSED_STRING ||
                $tokens[$i + 3]['value'] !== ')' || $tokens[$i + 4]['value'] !== ';') {
                throw new \RuntimeException('Noncanonical VERSION declaration');
            }
            $literal = $tokens[$i + 2]['value'];
            $version = substr($literal, 1, -1);
            if (!self::valid($version)) {
                throw new \RuntimeException('Invalid VERSION value');
            }
            $found[] = array('version' => $version, 'offset' => $tokens[$i + 2]['offset'] + 1,
                'length' => strlen($version));
        }
        if (count($found) !== 1) {
            throw new \RuntimeException('Exactly one VERSION declaration required');
        }
        return array('path' => $path, 'bytes' => $bytes, 'mode' => $stat['mode'] & 0777,
            'declaration' => $found[0]);
    }

    public static function read($root)
    {
        $state = self::inspect($root);
        return $state['declaration']['version'];
    }

    // Caller holds the exclusive maintenance lease.
    public static function write($root, $expected, $target)
    {
        if (!self::valid($expected) || !self::valid($target) || !version_compare($target, $expected, '>')) {
            throw new \RuntimeException('Invalid version transition');
        }
        $state = self::inspect($root);
        $declaration = $state['declaration'];
        if ($declaration['version'] === $target) {
            return; // Resume after a completed atomic rename.
        }
        if ($declaration['version'] !== $expected) {
            throw new \RuntimeException('Unexpected installed version');
        }
        $bytes = substr_replace($state['bytes'], $target, $declaration['offset'], $declaration['length']);
        $random = openssl_random_pseudo_bytes(16, $strong);
        if ($random === false || !$strong) {
            throw new \RuntimeException('Cannot stage siteconfig');
        }
        $tmp = $state['path'] . '.gl-version-' . bin2hex($random);
        $handle = @fopen($tmp, 'xb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot create staged siteconfig');
        }
        try {
            try {
                if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) {
                    throw new \RuntimeException('Cannot write staged siteconfig');
                }
            } finally {
                fclose($handle);
            }
            if (!chmod($tmp, $state['mode']) || file_get_contents($tmp) !== $bytes ||
                self::inspect($root) !== $state || !rename($tmp, $state['path'])) {
                throw new \RuntimeException('Cannot atomically replace siteconfig');
            }
            if (self::read($root) !== $target || file_get_contents($state['path']) !== $bytes) {
                throw new \RuntimeException('Siteconfig verification failed');
            }
        } finally {
            if (is_file($tmp) && !is_link($tmp)) {
                @unlink($tmp);
            }
        }
    }
}
