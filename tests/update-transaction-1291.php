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
$lab = makeLab1291('unmarked', false);
refused1291('unmarked tree refused', function () use ($lab) { run1291($lab); });
check1291('unmarked tree unchanged', original1291($lab) && !file_exists($lab . '/.geeklog-update.lock'));
$lab = makeLab1291('locked');
$lock = fopen($lab . '/.geeklog-update.lock', 'c+b');
flock($lock, LOCK_EX);
refused1291('concurrent transaction refused', function () use ($lab) { run1291($lab); });
flock($lock, LOCK_UN);
fclose($lock);
check1291('locked tree unchanged', original1291($lab));
$lab = makeLab1291('customized');
file_put_contents($lab . '/public_html/example.php', 'local customization');
refused1291('local change blocks transaction', function () use ($lab) { run1291($lab); });
check1291('local customization untouched', file_get_contents($lab . '/public_html/example.php') === 'local customization');
foreach (array(1, 2) as $after) {
    $lab = makeLab1291('fail-' . $after);
    $result = run1291($lab, $after);
    check1291('failure after ' . $after . ' replacement(s) rolled back',
        $result['status'] === 'rolled_back' && $result['applied'] === $after &&
        $result['rollback_errors'] === array());
    check1291('original tree restored after failure ' . $after, original1291($lab));
    check1291('rollback journal completed ' . $after, journal1291($result, 'rolled_back'));
    check1291('verified original backup retained ' . $after,
        file_get_contents($result['transaction'] . '/backup-0') === "<?php // old\n");
    check1291('staged bytes retained ' . $after,
        file_get_contents($result['transaction'] . '/stage-1') === "<?php // add\n");
}
$lab = makeLab1291('success');
$result = run1291($lab);
check1291('transaction committed', $result['status'] === 'committed' && $result['applied'] === 2);
check1291('replacement content correct', file_get_contents($lab . '/public_html/example.php') === "<?php // new\n");
check1291('addition content correct', file_get_contents($lab . '/system/classes/Added.php') === "<?php // add\n");
clearstatcache();
check1291('replacement and addition modes', (fileperms($lab . '/public_html/example.php') & 0777) === 0640 &&
    (fileperms($lab . '/system/classes/Added.php') & 0777) === 0644);
check1291('configuration not touched', file_get_contents($lab . '/public_html/siteconfig.php') === 'protected');
check1291('commit journal completed', journal1291($result, 'committed'));
check1291('backup retained after successful commit', file_get_contents($result['transaction'] . '/backup-0') === "<?php // old\n");
refused1291('reapplying same package refused', function () use ($lab) { run1291($lab); });
check1291('committed files unchanged by replay attempt', file_get_contents($lab . '/public_html/example.php') === "<?php // new\n");
echo "TOTAL: $passed/$total transaction cases passed\n";
exit($passed === $total ? 0 : 1);
