<?php
require_once dirname(__DIR__) . '/system/classes/UpdateCatalog.php';
require_once dirname(__DIR__) . '/system/classes/UpdateTransactionLab.php';
use Geeklog\UpdateCatalog;
use Geeklog\UpdateTransactionLab;

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

function makeLab1291($name, $marked = true)
{
    $lab = $GLOBALS['fixture'] . '/transaction-' . $name;
    foreach (array('public_html', 'system/classes', 'plugins', 'language') as $directory) {
        mkdir($lab . '/' . $directory, 0755, true);
    }
    file_put_contents($lab . '/public_html/example.php', "<?php // old\n");
    chmod($lab . '/public_html/example.php', 0640);
    file_put_contents($lab . '/public_html/siteconfig.php', 'protected');
    if ($marked) {
        file_put_contents($lab . '/.geeklog-update-lab', UpdateTransactionLab::MARKER);
    }
    return $lab;
}
function run1291($lab, $failure = null)
{
    return UpdateTransactionLab::run($lab, $GLOBALS['archive'], $GLOBALS['update'], '2.2.3', '2.2.3', $failure);
}
$total = $passed = 0;
function check1291($label, $ok)
{
    $GLOBALS['total']++;
    $GLOBALS['passed'] += $ok ? 1 : 0;
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
}
function refused1291($label, $callback)
{
    try { $callback(); check1291($label, false); }
    catch (RuntimeException $error) { check1291($label, true); }
}
function original1291($lab)
{
    clearstatcache();
    return file_get_contents($lab . '/public_html/example.php') === "<?php // old\n" &&
        !file_exists($lab . '/system/classes/Added.php') &&
        file_get_contents($lab . '/public_html/siteconfig.php') === 'protected' &&
        (fileperms($lab . '/public_html/example.php') & 0777) === 0640;
}
function journal1291($result, $event)
{
    $lines = file($result['transaction'] . '/journal.jsonl', FILE_IGNORE_NEW_LINES);
    $last = json_decode(end($lines), true);
    return $last['event'] === $event;
}

if (isset($argv[2]) && $argv[2] === '--child') {
    UpdateTransactionLab::run($argv[3], $archive, $update, '2.2.3', '2.2.3', null, (int) $argv[4]);
    exit(0);
}
function crash1291($name, $after)
{
    $lab = makeLab1291('recovery-' . $name);
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' .
        escapeshellarg($GLOBALS['fixture']) . ' --child ' . escapeshellarg($lab) . ' ' . (int) $after;
    exec($command . ' 2>&1', $output, $status);
    $transactions = glob($lab . '/.gl-transaction-*');
    check1291('child killed ' . $name, $status === 137 && count($transactions) === 1);
    return array($lab, $transactions[0]);
}
function recover1291($lab)
{
    return UpdateTransactionLab::recover($lab, $GLOBALS['archive'], $GLOBALS['update']);
}
foreach (array(1, 2) as $after) {
    list($lab, $tx) = crash1291('after-' . $after, $after);
    check1291('replacement installed before kill ' . $after,
        file_get_contents($lab . '/public_html/example.php') === "<?php // new\n");
    check1291('addition state before recovery ' . $after,
        file_exists($lab . '/system/classes/Added.php') === ($after === 2));
    refused1291('new update blocked ' . $after, function () use ($lab) { run1291($lab); });
    $result = recover1291($lab);
    check1291('recovery succeeded ' . $after, $result['status'] === 'rolled_back' && $result['rollback_errors'] === array());
    check1291('original tree restored ' . $after, original1291($lab));
    check1291('terminal journal ' . $after, journal1291($result, 'rolled_back'));
    check1291('recovery idempotent ' . $after, recover1291($lab)['status'] === 'nothing_to_recover');
    check1291('next update allowed ' . $after, run1291($lab)['status'] === 'committed');
}
list($lab, $tx) = crash1291('external', 2);
file_put_contents($lab . '/system/classes/Added.php', 'external change');
refused1291('external change blocks recovery', function () use ($lab) { recover1291($lab); });
check1291('external change and replacement untouched',
    file_get_contents($lab . '/system/classes/Added.php') === 'external change' &&
    file_get_contents($lab . '/public_html/example.php') === "<?php // new\n");
list($lab, $tx) = crash1291('backup', 2);
file_put_contents($tx . '/backup-0', 'tampered');
refused1291('tampered backup blocks recovery', function () use ($lab) { recover1291($lab); });
check1291('tampered backup does not partially roll back',
    file_exists($lab . '/system/classes/Added.php') &&
    file_get_contents($lab . '/public_html/example.php') === "<?php // new\n");
list($lab, $tx) = crash1291('journal', 1);
file_put_contents($tx . '/journal.jsonl', '{"event":', FILE_APPEND);
refused1291('torn journal blocks recovery', function () use ($lab) { recover1291($lab); });
check1291('torn journal preserves installed file',
    file_get_contents($lab . '/public_html/example.php') === "<?php // new\n");
$lab = makeLab1291('recovery-unmarked', false);
refused1291('unmarked recovery refused', function () use ($lab) { recover1291($lab); });
check1291('unmarked lab untouched', original1291($lab));

list($lab, $tx) = crash1291('fake-commit', 1);
file_put_contents($tx . '/journal.jsonl', "{\"event\":\"committed\",\"path\":\"\"}\n", FILE_APPEND);
refused1291('premature commit marker blocks new update', function () use ($lab) { run1291($lab); });
refused1291('premature commit marker blocks recovery', function () use ($lab) { recover1291($lab); });
check1291('fake terminal marker preserves bytes',
    file_get_contents($lab . '/public_html/example.php') === "<?php // new\n");
list($lab, $tx) = crash1291('mode', 2);
chmod($lab . '/public_html/example.php', 0600);
refused1291('external permission change blocks recovery', function () use ($lab) { recover1291($lab); });
clearstatcache();
check1291('changed mode and added file preserved',
    (fileperms($lab . '/public_html/example.php') & 0777) === 0600 &&
    file_exists($lab . '/system/classes/Added.php'));
list($lab, $tx) = crash1291('partial-restore', 2);
// Simulate interruption immediately after unlink, before journal confirmation.
unlink($lab . '/system/classes/Added.php');
$result = recover1291($lab);
check1291('resume after unrecorded restoration', $result['status'] === 'rolled_back' && original1291($lab));
check1291('resumed rollback journal accepted', recover1291($lab)['status'] === 'nothing_to_recover');
list($lab, $tx) = crash1291('restored-confirmed', 2);
unlink($lab . '/system/classes/Added.php');
file_put_contents($tx . '/journal.jsonl', "{\"event\":\"restored\",\"path\":\"system/classes/Added.php\"}\n", FILE_APPEND);
$result = recover1291($lab);
check1291('resume after recorded restoration', $result['status'] === 'rolled_back' && original1291($lab));
check1291('recorded restoration not duplicated', recover1291($lab)['status'] === 'nothing_to_recover');
list($lab, $tx) = crash1291('fake-rollback', 2);
file_put_contents($tx . '/journal.jsonl', "{\"event\":\"rolled_back\",\"path\":\"\"}\n", FILE_APPEND);
refused1291('rollback missing confirmed file blocks new update', function () use ($lab) { run1291($lab); });
foreach (array(0, -1, -2) as $after) {
    list($lab, $tx) = crash1291('boundary-' . $after, $after);
    check1291('expected pre-write state ' . $after,
        file_get_contents($lab . '/public_html/example.php') === ($after === -2 ? "<?php // new\n" : "<?php // old\n") &&
        !file_exists($lab . '/system/classes/Added.php'));
    refused1291('pending boundary blocks update ' . $after, function () use ($lab) { run1291($lab); });
    $result = recover1291($lab);
    check1291('boundary recovery ' . $after, $result['status'] === 'rolled_back' && original1291($lab));
    check1291('boundary journal valid ' . $after, recover1291($lab)['status'] === 'nothing_to_recover');
}
echo "TOTAL: $passed/$total recovery cases passed\n";
exit($passed === $total ? 0 : 1);
