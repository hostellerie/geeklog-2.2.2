<?php
require_once dirname(__DIR__) . '/system/classes/UpdateCatalog.php';

use Geeklog\UpdateCatalog;
require_once dirname(__DIR__) . '/system/classes/UpdateCatalogStateLab.php';
use Geeklog\UpdateCatalogStateLab;
use Geeklog\UpdateTransactionLab;

$private = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
if ($private === false) {
    fwrite(STDERR, "Cannot generate test key\n");
    exit(1);
}
$public = openssl_pkey_get_details($private)['key'];
$host = 'updates.example.invalid';
$now = 1796083200; // 2026-12-01 00:00 UTC
$release = array(
    'id' => 'geeklog-2.2.3.1', 'kind' => 'release', 'from' => array('2.2.3'),
    'from_schema' => array('2.2.3'), 'version' => '2.2.3.1', 'schema' => '2.2.3',
    'php_min' => '5.6.4', 'php_max' => '8.5.99', 'databases' => array('mysql', 'pgsql'),
    'requires' => array(), 'conflicts' => array(), 'supersedes' => array('patch-a'),
    'issues' => array(1291), 'security' => false, 'notes' => 'Test fixture only',
    'package' => array('url' => 'https://' . $host . '/core/release.zip',
        'bytes' => 1234, 'sha256' => str_repeat('a', 64))
);
$patch = $release;
$patch['id'] = 'patch-a';
$patch['kind'] = 'patch';
$patch['version'] = '2.2.3';
$patch['supersedes'] = array();
$patch['requires'] = array('previous-patch');
$patch['conflicts'] = array('incompatible-patch');
$patch['package']['url'] = 'https://' . $host . '/core/patch.zip';
$catalog = array('format' => 1, 'sequence' => 10, 'published_at' => '2026-12-01T00:00:00Z',
    'expires_at' => '2026-12-31T00:00:00Z', 'channel' => 'stable', 'updates' => array($patch, $release));
function encode1291($value) { return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
function signed1291($json)
{
    if (!openssl_sign($json, $signature, $GLOBALS['private'], OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Unable to sign fixture');
    }
    return $signature;
}
function verify1291($json, $seq = 0, $digest = '', $signature = null)
{
    return UpdateCatalog::fromSigned($json, $signature === null ? signed1291($json) : $signature,
        $GLOBALS['public'], $GLOBALS['host'], $GLOBALS['now'], $seq, $digest);
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

$root = $argv[1] . '/catalog-state';
mkdir($root, 0700);
file_put_contents($root . '/.geeklog-update-lab', UpdateTransactionLab::MARKER);
function accept1291($catalog, $signature = null)
{
    $json = encode1291($catalog);
    return UpdateCatalogStateLab::accept($GLOBALS['root'], $json,
        $signature === null ? signed1291($json) : $signature,
        $GLOBALS['public'], $GLOBALS['host'], $GLOBALS['now']);
}
$file = $root . '/.gl-catalog-state';
$accepted = accept1291($catalog);
check1291('first authenticated catalog persisted', $accepted->getSequence() === 10 &&
    json_decode(file_get_contents($file), true)['digest'] === $accepted->getDigest());
check1291('private file', (fileperms($file) & 0777) === 0600);
$original = file_get_contents($file);
check1291('same catalog accepted idempotently', accept1291($catalog)->getSequence() === 10 && file_get_contents($file) === $original);
$older = $catalog;
$older['sequence'] = 9;
reject1291('older catalog rejected', function () use ($older) { accept1291($older); });
$changed = $catalog;
$changed['updates'][1]['notes'] = 'Changed';
reject1291('same sequence changed digest rejected', function () use ($changed) { accept1291($changed); });
$new = $catalog;
$new['sequence'] = 11;
reject1291('bad signature rejected', function () use ($new) { accept1291($new, 'bad'); });
check1291('rejections preserve state', file_get_contents($file) === $original);
check1291('new sequence accepted', accept1291($new)->getSequence() === 11);
reject1291('formerly valid catalog rejected', function () use ($catalog) { accept1291($catalog); });
$lock = fopen($file . '.lock', 'c+b');
flock($lock, LOCK_EX);
try { accept1291($new); check1291('locked state refused', false); }
catch (RuntimeException $e) { check1291('locked state refused', true); }
fclose($lock);
file_put_contents($file, 'invalid');
try { accept1291($new); check1291('corrupt state refused', false); }
catch (RuntimeException $e) { check1291('corrupt state refused', true); }
check1291('corrupt state not reset', file_get_contents($file) === 'invalid');
echo "TOTAL: $passed/$total catalog persistence cases passed\n";
exit($passed === $total ? 0 : 1);
