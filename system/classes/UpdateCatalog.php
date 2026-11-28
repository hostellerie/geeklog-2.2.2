<?php
namespace Geeklog;

/**
 * Read-only parser and selector for an authenticated Core update catalog.
 * No network or filesystem operations, no package execution or installation.
 */
final class UpdateCatalog
{
    const MAX_BYTES = 524288;
    const MAX_UPDATES = 200;
    private $catalog;
    private $digest;

    private function __construct(array $catalog, $digest)
    {
        $this->catalog = $catalog;
        $this->digest = $digest;
    }

    private static function requireValid($condition, $message)
    {
        if (!$condition) {
            throw new \InvalidArgumentException($message);
        }
    }

    private static function fields($object, array $required, array $optional = array())
    {
        self::requireValid(is_array($object), 'Expected object');
        foreach ($required as $name) {
            self::requireValid(array_key_exists($name, $object), 'Missing field: ' . $name);
        }
        foreach (array_keys($object) as $name) {
            self::requireValid(in_array($name, $required, true) || in_array($name, $optional, true),
                'Unknown field');
        }
    }

    private static function isList($value)
    {
        return is_array($value) && ($value === array() || array_keys($value) === range(0, count($value) - 1));
    }

    private static function validVersion($version)
    {
        return is_string($version) && preg_match('/^(?:0|[1-9][0-9]{0,5})\.(?:0|[1-9][0-9]{0,5})\.(?:0|[1-9][0-9]{0,5})(?:\.[1-9][0-9]{0,5})?$/D', $version) === 1;
    }

    private static function validId($id)
    {
        return is_string($id) && preg_match('/^[a-z0-9][a-z0-9._-]{0,79}$/D', $id) === 1;
    }

    private static function listOf($value, $validator, $allowEmpty = true)
    {
        self::requireValid(self::isList($value) && count($value) <= self::MAX_UPDATES &&
            ($allowEmpty || count($value) > 0), 'Invalid list');
        $seen = array();
        foreach ($value as $item) {
            self::requireValid(call_user_func($validator, $item), 'Invalid list item');
            self::requireValid(!in_array($item, $seen, true), 'Duplicate list item');
            $seen[] = $item;
        }
    }

    private static function timestamp($value)
    {
        self::requireValid(is_string($value), 'Invalid timestamp');
        $date = \DateTime::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        self::requireValid($date !== false && $date->format('Y-m-d\TH:i:s\Z') === $value, 'Invalid timestamp');
        return $date->getTimestamp();
    }

    private static function validateUpdate(array $update, $host)
    {
        self::fields($update, array('id', 'kind', 'from', 'from_schema', 'version', 'schema',
            'php_min', 'databases', 'requires', 'conflicts', 'supersedes', 'issues',
            'security', 'notes', 'package'), array('php_max'));
        self::requireValid(self::validId($update['id']), 'Invalid update ID');
        self::requireValid(in_array($update['kind'], array('release', 'patch'), true), 'Invalid update kind');
        self::listOf($update['from'], array(__CLASS__, 'validVersion'), false);
        self::listOf($update['from_schema'], array(__CLASS__, 'validVersion'), false);
        foreach (array('version', 'schema', 'php_min') as $field) {
            self::requireValid(self::validVersion($update[$field]), 'Invalid version: ' . $field);
        }
        if (array_key_exists('php_max', $update)) {
            self::requireValid(self::validVersion($update['php_max']) &&
                version_compare($update['php_max'], $update['php_min'], '>='), 'Invalid PHP range');
        }
        self::listOf($update['databases'], function ($db) {
            return in_array($db, array('mysql', 'pgsql'), true);
        }, false);
        foreach (array('requires', 'conflicts', 'supersedes') as $field) {
            self::listOf($update[$field], array(__CLASS__, 'validId'));
            self::requireValid(!in_array($update['id'], $update[$field], true), 'Self-reference');
        }
        self::requireValid(!array_intersect($update['requires'], $update['conflicts']), 'Conflicting requirements');
        self::listOf($update['issues'], function ($id) { return is_int($id) && $id > 0; });
        self::requireValid(is_bool($update['security']) && is_string($update['notes']) &&
            strlen($update['notes']) <= 8192, 'Invalid release notes');

        foreach ($update['from'] as $source) {
            self::requireValid($update['kind'] === 'release' ?
                version_compare($update['version'], $source, '>') : $update['version'] === $source,
                'Invalid code version transition');
        }
        foreach ($update['from_schema'] as $source) {
            self::requireValid($update['kind'] === 'release' ?
                version_compare($update['schema'], $source, '>=') : $update['schema'] === $source,
                'Invalid schema transition');
        }

        $package = $update['package'];
        self::fields($package, array('url', 'bytes', 'sha256'));
        self::requireValid(is_int($package['bytes']) && $package['bytes'] > 0 &&
            $package['bytes'] <= 1073741824, 'Invalid package size');
        self::requireValid(is_string($package['sha256']) &&
            preg_match('/^[0-9a-f]{64}$/D', $package['sha256']) === 1, 'Invalid package digest');
        self::requireValid(is_string($package['url']) && strlen($package['url']) <= 2048,
            'Invalid package URL');
        // The caller supplies an already trusted host, never one read from the catalog.
        $prefix = 'https://' . $host . '/';
        self::requireValid(strncmp($package['url'], $prefix, strlen($prefix)) === 0, 'Untrusted package origin');
        $path = substr($package['url'], strlen($prefix));
        self::requireValid(preg_match('/^[A-Za-z0-9_\/.-]+\.zip$/D', $path) === 1 &&
            !in_array('..', explode('/', $path), true) && !in_array('.', explode('/', $path), true) &&
            !in_array('', explode('/', $path), true), 'Invalid package path');
    }

    /**
     * Verify exact catalog bytes with a trusted RSA public key before parsing.
     * State is supplied by caller; persistence and transport are deliberately out of scope.
     */
    public static function fromSigned($json, $signature, $publicKey, $trustedHost,
        $now, $previousSequence = 0, $previousDigest = '')
    {
        self::requireValid(is_string($json) && strlen($json) > 0 &&
            strlen($json) <= self::MAX_BYTES && is_string($signature) &&
            strlen($signature) > 0 && strlen($signature) <= 16384, 'Invalid catalog size');
        self::requireValid(is_string($trustedHost) && preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D', $trustedHost) === 1,
            'Invalid trusted host');
        self::requireValid(is_int($now) && is_int($previousSequence) && $previousSequence >= 0 &&
            ($previousSequence === 0 ? $previousDigest === '' :
                is_string($previousDigest) && preg_match('/^[0-9a-f]{64}$/D', $previousDigest) === 1),
            'Invalid replay state');
        self::requireValid(function_exists('openssl_verify') && function_exists('openssl_pkey_get_public'),
            'OpenSSL unavailable');
        self::requireValid(is_string($publicKey) && strlen($publicKey) <= 16384 &&
            strpos($publicKey, '-----BEGIN PUBLIC KEY-----') === 0, 'Invalid trusted key');
        $key = @openssl_pkey_get_public($publicKey);
        self::requireValid($key !== false, 'Invalid trusted key');
        $details = openssl_pkey_get_details($key);
        self::requireValid(is_array($details) && $details['type'] === OPENSSL_KEYTYPE_RSA &&
            $details['bits'] >= 2048, 'Unsupported trusted key');
        self::requireValid(openssl_verify($json, $signature, $key, OPENSSL_ALGO_SHA256) === 1,
            'Invalid catalog signature');

        $catalog = json_decode($json, true, 32);
        // Exact compact encoding is required by catalog v1. Re-encoding detects
        // duplicate keys, alternate encodings and ambiguous/noncanonical input.
        self::requireValid(json_last_error() === JSON_ERROR_NONE && is_array($catalog) &&
            json_encode($catalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) === $json,
            'Invalid or noncanonical JSON');
        self::fields($catalog, array('format', 'sequence', 'published_at', 'expires_at', 'channel', 'updates'));
        self::requireValid($catalog['format'] === 1 && $catalog['channel'] === 'stable', 'Unsupported catalog');
        self::requireValid(is_int($catalog['sequence']) && $catalog['sequence'] > 0, 'Invalid sequence');
        $digest = hash('sha256', $json);
        self::requireValid($catalog['sequence'] > $previousSequence ||
            ($catalog['sequence'] === $previousSequence && $digest === $previousDigest), 'Catalog replay');
        $published = self::timestamp($catalog['published_at']);
        $expires = self::timestamp($catalog['expires_at']);
        self::requireValid($published <= $now && $expires > $now && $expires > $published, 'Catalog outside validity period');
        self::requireValid(self::isList($catalog['updates']) && count($catalog['updates']) <= self::MAX_UPDATES,
            'Invalid updates list');
        $ids = array();
        foreach ($catalog['updates'] as $update) {
            self::requireValid(is_array($update), 'Invalid update');
            self::validateUpdate($update, $trustedHost);
            self::requireValid(!in_array($update['id'], $ids, true), 'Duplicate update ID');
            $ids[] = $update['id'];
        }
        return new self($catalog, $digest);
    }

    public function getSequence() { return $this->catalog['sequence']; }
    public function getDigest() { return $this->digest; }

    /**
     * Return compatible candidates, not an installation decision.
     * Applied IDs include superseded patches recorded by the caller.
     */
    public function applicable($codeVersion, $schemaVersion, $phpVersion, $database, array $applied = array())
    {
        self::requireValid(self::validVersion($codeVersion) && self::validVersion($schemaVersion) &&
            is_string($phpVersion) && preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[a-zA-Z0-9.+-]*)$/D', $phpVersion) === 1,
            'Invalid installed version');
        if ($database === 'mysql-innodb') {
            $database = 'mysql';
        }
        self::requireValid(in_array($database, array('mysql', 'pgsql'), true), 'Unsupported database');
        self::listOf($applied, array(__CLASS__, 'validId'));
        $matches = array();
        foreach ($this->catalog['updates'] as $update) {
            if (!in_array($codeVersion, $update['from'], true) ||
                !in_array($schemaVersion, $update['from_schema'], true) ||
                !in_array($database, $update['databases'], true) ||
                version_compare($phpVersion, $update['php_min'], '<') ||
                (isset($update['php_max']) && version_compare($phpVersion, $update['php_max'], '>')) ||
                in_array($update['id'], $applied, true) ||
                array_diff($update['requires'], $applied) || array_intersect($update['conflicts'], $applied)) {
                continue;
            }
            $matches[] = $update;
        }
        usort($matches, function ($a, $b) {
            if ($a['kind'] !== $b['kind']) {
                return $a['kind'] === 'release' ? -1 : 1;
            }
            $order = version_compare($b['version'], $a['version']);
            return $order === 0 ? strcmp($a['id'], $b['id']) : $order;
        });
        return $matches;
    }
}
