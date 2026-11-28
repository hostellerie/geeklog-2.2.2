<?php
require_once dirname(__DIR__) . '/system/classes/UpdateCatalog.php';
require_once dirname(__DIR__) . '/system/classes/UpdateTransactionLab.php';
use Geeklog\UpdateCatalog;
use Geeklog\UpdateTransactionLab;
require_once dirname(__DIR__) . '/system/classes/UpdateHealthLab.php';
use Geeklog\UpdateHealthLab;

$fixture = $argv[1];
$archive = $fixture . '/geeklog-2.2.3.1.zip';
$metadata = json_decode(file_get_contents($fixture . '/metadata.json'), true);
$record = array('id' => 'geeklog-2.2.3.1', 'kind' => 'release',
    'from' => array('2.2.3'), 'from_schema' => array('2.2.3'), 'version' => '2.2.3.1',
    'schema' => '2.2.3', 'php_min' => '5.6.4', 'databases' => array('mysql'),
    'requires' => array(), 'conflicts' => array(), 'supersedes' => array(), 'issues' => array(1291),
    'security' => false, 'notes' => 'Disposable fixture',
    'package' => array('url' => 'https://updates.example.invalid/fixture.zip',
        'bytes' => $metadata['bytes'], 'sha256' => $metadata['sha256']));
$catalog = array('format' => 1, 'sequence' => 1, 'published_at' => '2026-12-01T00:00:00Z',
    'expires_at' => '2026-12-31T00:00:00Z', 'channel' => 'stable', 'updates' => array($record));
$json = json_encode($catalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$private = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
$public = openssl_pkey_get_details($private)['key'];
if (!openssl_sign($json, $signature, $private, OPENSSL_ALGO_SHA256)) {
    throw new RuntimeException('Fixture signing failed');
}
$verified = UpdateCatalog::fromSigned($json, $signature, $public, 'updates.example.invalid', 1796083200);
$updates = $verified->applicable('2.2.3', '2.2.3', PHP_VERSION, 'mysql');
$update = $updates[0];


UpdateHealthLab::lint($archive, $update);
echo "PASS: authenticated valid PHP linted\n";
$bad = json_decode(file_get_contents($fixture . '/invalid-php-metadata.json'), true);
$catalog['updates'][0]['package']['bytes'] = $bad['bytes'];
$catalog['updates'][0]['package']['sha256'] = $bad['sha256'];
$json = json_encode($catalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
openssl_sign($json, $signature, $private, OPENSSL_ALGO_SHA256);
$verified = UpdateCatalog::fromSigned($json, $signature, $public, 'updates.example.invalid', 1796083200);
$update = $verified->applicable('2.2.3', '2.2.3', PHP_VERSION, 'mysql')[0];
try {
    UpdateHealthLab::lint($fixture . '/invalid-php.zip', $update);
    echo "FAIL: invalid PHP accepted\n";
    exit(1);
} catch (RuntimeException $error) {
    if (strpos($error->getMessage(), 'PHP syntax check failed:') !== 0) { throw $error; }
}
echo "PASS: authenticated invalid PHP rejected\n";
echo "TOTAL: 2/2 syntax cases passed\n";
