<?php
require_once dirname(__DIR__) . '/system/classes/UpdateCatalog.php';
require_once dirname(__DIR__) . '/system/classes/UpdatePackage.php';

use Geeklog\UpdateCatalog;
use Geeklog\UpdatePackage;

$root = $argv[1];
$private = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
$public = openssl_pkey_get_details($private)['key'];
$host = 'updates.example.invalid';
$now = 1796083200;
function signedUpdate1291($file)
{
    $update = array(
        'id' => 'geeklog-2.2.3.1', 'kind' => 'release',
        'from' => array('2.2.3'), 'from_schema' => array('2.2.3'),
        'version' => '2.2.3.1', 'schema' => '2.2.3',
        'php_min' => '5.6.4', 'databases' => array('mysql'),
        'requires' => array(), 'conflicts' => array(), 'supersedes' => array(),
        'issues' => array(1291), 'security' => false, 'notes' => 'Disposable fixture',
        'package' => array('url' => 'https://' . $GLOBALS['host'] . '/core/fixture.zip',
            'bytes' => filesize($file), 'sha256' => hash_file('sha256', $file))
    );
    $catalog = array('format' => 1, 'sequence' => 1,
        'published_at' => '2026-12-01T00:00:00Z', 'expires_at' => '2026-12-31T00:00:00Z',
        'channel' => 'stable', 'updates' => array($update));
    $json = json_encode($catalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!openssl_sign($json, $signature, $GLOBALS['private'], OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Unable to sign fixture');
    }
    $verified = UpdateCatalog::fromSigned($json, $signature, $GLOBALS['public'], $GLOBALS['host'], $GLOBALS['now']);
    $candidates = $verified->applicable('2.2.3', '2.2.3', PHP_VERSION, 'mysql');
    return $candidates[0];
}
$total = 0;
$passed = 0;
function check1291($label, $ok)
{
    $GLOBALS['total']++;
    $GLOBALS['passed'] += $ok ? 1 : 0;
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
}
function reject1291($label, $callback)
{
    try {
        $callback();
        check1291($label, false);
    } catch (InvalidArgumentException $e) {
        check1291($label, true);
    }
}
$file = $root . '/geeklog-2.2.3.1.zip';
$update = signedUpdate1291($file);
$metadata = json_decode(file_get_contents($root . '/metadata.json'), true);
check1291('builder digest and size match signed catalog',
    $metadata['bytes'] === $update['package']['bytes'] && $metadata['sha256'] === $update['package']['sha256']);
$manifest = UpdatePackage::verify($file, $update);
check1291('valid archive verified', $manifest['id'] === $update['id'] && count($manifest['files']) === 2);
check1291('replace original hash retained', $manifest['files'][0]['before'] === hash_file('sha256', $root . '/base/public_html/example.php'));
check1291('add action retained', $manifest['files'][1]['action'] === 'add' && $manifest['files'][1]['before'] === null);
reject1291('wrong signed archive hash refused', function () use ($file, $update) {
    $update['package']['sha256'] = str_repeat('b', 64);
    UpdatePackage::verify($file, $update);
});
reject1291('wrong declared archive size refused', function () use ($file, $update) {
    $update['package']['bytes']++;
    UpdatePackage::verify($file, $update);
});
reject1291('mismatched update identity refused', function () use ($file, $update) {
    $update['id'] = 'different'; UpdatePackage::verify($file, $update);
});
foreach (array('payload-tampered', 'extra', 'traversal', 'duplicate', 'symlink', 'missing', 'protected', 'case', 'schema') as $name) {
    $bad = $root . '/' . $name . '.zip';
    reject1291($name . ' archive refused even with matching signed archive hash', function () use ($bad) {
        UpdatePackage::verify($bad, signedUpdate1291($bad));
    });
}
check1291('source files unchanged', file_get_contents($root . '/base/public_html/example.php') === "<?php // old\n" &&
    !file_exists($root . '/base/system/classes/Added.php'));
echo "TOTAL: $passed/$total package cases passed\n";
exit($passed === $total ? 0 : 1);
