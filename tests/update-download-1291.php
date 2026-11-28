<?php
require_once dirname(__DIR__) . '/system/classes/UpdateCatalog.php';
require_once dirname(__DIR__) . '/system/classes/UpdateTransactionLab.php';
use Geeklog\UpdateCatalog;
use Geeklog\UpdateTransactionLab;
require_once dirname(__DIR__) . '/system/classes/UpdateDownloadLab.php';
use Geeklog\UpdateDownloadLab;

$fixture = $argv[1];
$archive = $fixture . '/geeklog-2.2.3.1.zip';
$metadata = json_decode(file_get_contents($fixture . '/metadata.json'), true);
$record = array('id' => 'geeklog-2.2.3.1', 'kind' => 'release',
    'from' => array('2.2.3'), 'from_schema' => array('2.2.3'), 'version' => '2.2.3.1',
    'schema' => '2.2.3', 'php_min' => '5.6.4', 'databases' => array('mysql'),
    'requires' => array(), 'conflicts' => array(), 'supersedes' => array(), 'issues' => array(1291),
    'security' => false, 'notes' => 'Disposable fixture',
    'package' => array('url' => 'https://localhost/fixture.zip',
        'bytes' => $metadata['bytes'], 'sha256' => $metadata['sha256']));
$catalog = array('format' => 1, 'sequence' => 1, 'published_at' => '2026-12-01T00:00:00Z',
    'expires_at' => '2026-12-31T00:00:00Z', 'channel' => 'stable', 'updates' => array($record));
$json = json_encode($catalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$private = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
$public = openssl_pkey_get_details($private)['key'];
if (!openssl_sign($json, $signature, $private, OPENSSL_ALGO_SHA256)) {
    throw new RuntimeException('Fixture signing failed');
}
$verified = UpdateCatalog::fromSigned($json, $signature, $public, 'localhost', 1796083200);
$updates = $verified->applicable('2.2.3', '2.2.3', PHP_VERSION, 'mysql');
$update = $updates[0];

function makeLab1291($name, $marked = true)
{
    $lab = $GLOBALS['fixture'] . '/transaction-' . $name;
    foreach (array('public_html', 'system/classes', 'plugins', 'language') as $directory) {
        mkdir($lab . '/' . $directory, 0755, true);
    }
    file_put_contents($lab . '/public_html/example.php', "<?php // old\n");
    chmod($lab . '/public_html/example.php', 0640);
    file_put_contents($lab . '/public_html/siteconfig.php', "<?php\r\n// retained comment\r\ndefine('VERSION', '2.2.3');\r\n\$protected = 'unchanged';\r\n");
    chmod($lab . '/public_html/siteconfig.php', 0640);
    file_put_contents($lab . '/.gl-lab-version.json', json_encode(array('schema' => '2.2.3')));
    if ($marked) {
        file_put_contents($lab . '/.geeklog-update-lab', UpdateTransactionLab::MARKER);
    }
    return $lab;
}

function download1291($lab, $changes = array(), $id = 'geeklog-2.2.3.1')
{
    $catalog = $GLOBALS['catalog'];
    $catalog['updates'][0]['package']['url'] = 'https://localhost/good.zip';
    foreach ($changes as $key => $value) {
        $catalog['updates'][0]['package'][$key] = $value;
    }
    $json = json_encode($catalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    openssl_sign($json, $signature, $GLOBALS['private'], OPENSSL_ALGO_SHA256);
    $verified = UpdateCatalog::fromSigned($json, $signature, $GLOBALS['public'], 'localhost', 1796083200);
    return UpdateDownloadLab::stage($lab, $verified, $id, '2.2.3', '2.2.3', PHP_VERSION, 'mysql');
}
$total = $passed = 0;
function check1291($label, $ok)
{
    $GLOBALS['total']++;
    $GLOBALS['passed'] += $ok ? 1 : 0;
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
}
$lab = makeLab1291('download');
$path = download1291($lab);
check1291('verified HTTPS archive staged', hash_file('sha256', $path) === $metadata['sha256']);
check1291('private permissions', (fileperms($path) & 0777) === 0600 && (fileperms(dirname($path)) & 0777) === 0700);
foreach (array(
    'redirect' => array('url' => 'https://localhost/redirect.zip'),
    'short' => array('url' => 'https://localhost/short.zip'),
    'long' => array('url' => 'https://localhost/long.zip'),
    'tampered' => array('url' => 'https://localhost/bad.zip'),
    'not found' => array('url' => 'https://localhost/missing.zip'),
    'oversize' => array('bytes' => 73400321)
) as $label => $changes) {
    try { download1291($lab, $changes); check1291($label, false); }
    catch (Exception $e) { check1291($label, count(glob($lab . '/.gl-download-*')) === 1); }
}
try { download1291($lab, array(), 'missing'); check1291('unknown update', false); }
catch (RuntimeException $e) { check1291('unknown update', true); }
try { download1291(makeLab1291('download-unmarked', false)); check1291('unmarked lab', false); }
catch (RuntimeException $e) { check1291('unmarked lab', true); }
echo "TOTAL: $passed/$total HTTPS staging cases passed\n";
exit($passed === $total ? 0 : 1);
