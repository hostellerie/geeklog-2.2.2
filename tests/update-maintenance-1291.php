<?php
require_once dirname(__DIR__) . '/system/classes/UpdateCatalog.php';
require_once dirname(__DIR__) . '/system/classes/UpdateTransactionLab.php';
use Geeklog\UpdateCatalog;
use Geeklog\UpdateTransactionLab;
require_once dirname(__DIR__) . '/system/classes/UpdateMaintenanceLab.php';
use Geeklog\UpdateMaintenanceLab;

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
    file_put_contents($lab . '/public_html/siteconfig.php', "<?php\r\n// retained comment\r\ndefine('VERSION', '2.2.3');\r\n\$protected = 'unchanged';\r\n");
    chmod($lab . '/public_html/siteconfig.php', 0640);
    file_put_contents($lab . '/.gl-lab-version.json', json_encode(array('schema' => '2.2.3')));
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
        strpos(file_get_contents($lab . '/public_html/siteconfig.php'), "\$protected = 'unchanged';") !== false &&
        (fileperms($lab . '/public_html/example.php') & 0777) === 0640;
}
function journal1291($result, $event)
{
    $lines = file($result['transaction'] . '/journal.jsonl', FILE_IGNORE_NEW_LINES);
    $last = json_decode(end($lines), true);
    return $last['event'] === $event;
}


function maintenanceRun1291($lab, $fail = null, $crash = null)
{
    return UpdateMaintenanceLab::run($lab, $GLOBALS['archive'], $GLOBALS['update'], '2.2.3', '2.2.3', $fail, $crash);
}
function maintenanceRecover1291($lab)
{
    return UpdateMaintenanceLab::recover($lab, $GLOBALS['archive'], $GLOBALS['update']);
}
function online1291($lab)
{
    $lease = UpdateMaintenanceLab::tryRequest($lab);
    if (is_resource($lease)) {
        fclose($lease);
        return true;
    }
    return false;
}
if (isset($argv[2]) && $argv[2] === '--child') {
    maintenanceRun1291($argv[3], null, 1);
    exit(0);
}
$lab = makeLab1291('maintenance-drain');
$one = UpdateMaintenanceLab::tryRequest($lab);
$two = UpdateMaintenanceLab::tryRequest($lab);
check1291('two concurrent request leases', is_resource($one) && is_resource($two));
refused1291('writer blocked by readers', function () use ($lab) { maintenanceRun1291($lab); });
check1291('no maintenance marker while requests active', !file_exists($lab . '/.gl-maintenance-active'));
fclose($one);
refused1291('remaining reader blocks writer', function () use ($lab) { maintenanceRun1291($lab); });
fclose($two);
$result = maintenanceRun1291($lab, 1);
check1291('rollback returns site to service', $result['status'] === 'rolled_back' && online1291($lab));
check1291('rollback restores original', original1291($lab));
$result = maintenanceRun1291($lab);
check1291('successful code commit remains offline', $result['status'] === 'committed' && !online1291($lab));
refused1291('second maintenance refused', function () use ($lab) { maintenanceRun1291($lab); });
$result = maintenanceRecover1291($lab);
check1291('committed code cannot reopen without version finalization',
    $result['status'] === 'nothing_to_recover' && !online1291($lab));
$lab = makeLab1291('maintenance-crash');
$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' .
    escapeshellarg($fixture) . ' --child ' . escapeshellarg($lab);
exec($command . ' 2>&1', $output, $status);
check1291('maintenance child killed', $status === 137);
check1291('marker survives process death', !online1291($lab));
refused1291('new update blocked after crash', function () use ($lab) { maintenanceRun1291($lab); });
$result = maintenanceRecover1291($lab);
check1291('crash recovery reopens restored laboratory', $result['status'] === 'rolled_back' && online1291($lab) && original1291($lab));
$lab = makeLab1291('maintenance-invalid');
file_put_contents($lab . '/public_html/example.php', 'local change');
refused1291('preflight exception keeps maintenance active', function () use ($lab) { maintenanceRun1291($lab); });
check1291('failed preflight remains offline', !online1291($lab));
check1291('failed preflight preserves local bytes', file_get_contents($lab . '/public_html/example.php') === 'local change');
check1291('ambiguous recovery stays offline', maintenanceRecover1291($lab)['status'] === 'nothing_to_recover' && !online1291($lab));
$lab = makeLab1291('maintenance-bad-marker');
symlink('/missing', $lab . '/.gl-maintenance-active');
check1291('dangling marker blocks requests', !online1291($lab));
refused1291('dangling marker blocks update', function () use ($lab) { maintenanceRun1291($lab); });
$lab = makeLab1291('maintenance-unmarked', false);
refused1291('unmarked request refused', function () use ($lab) { online1291($lab); });
refused1291('unmarked writer refused', function () use ($lab) { maintenanceRun1291($lab); });
check1291('unmarked tree unchanged', original1291($lab) && !file_exists($lab . '/.gl-maintenance.lock'));

function finalize1291($lab)
{
    return UpdateMaintenanceLab::finalize($lab, $GLOBALS['archive'], $GLOBALS['update']);
}
function version1291($lab)
{
    $schema = json_decode(file_get_contents($lab . '/.gl-lab-version.json'), true);
    return array('code' => Geeklog\UpdateSiteVersionLab::read($lab), 'schema' => $schema['schema']);
}
$old = array('code' => '2.2.3', 'schema' => '2.2.3');
$new = array('code' => '2.2.3.1', 'schema' => '2.2.3');
$lab = makeLab1291('finalize-success');
$result = maintenanceRun1291($lab);
$state = json_decode(file_get_contents($lab . '/.gl-maintenance-active'), true);
check1291('marker bound to exact transaction', basename($result['transaction']) === '.gl-transaction-' . $state['transaction']);
check1291('version unchanged until finalization', version1291($lab) === $old && !online1291($lab));
$beforeConfig = file_get_contents($lab . '/public_html/siteconfig.php');
$beforeSchema = file_get_contents($lab . '/.gl-lab-version.json');
$result = finalize1291($lab);
check1291('only version literal changed', file_get_contents($lab . '/public_html/siteconfig.php') === str_replace("'2.2.3'", "'2.2.3.1'", $beforeConfig));
check1291('schema bytes and siteconfig mode unchanged', file_get_contents($lab . '/.gl-lab-version.json') === $beforeSchema && (fileperms($lab . '/public_html/siteconfig.php') & 0777) === 0640);
check1291('verified commit finalized', $result['status'] === 'finalized' && version1291($lab) === $new && online1291($lab));
check1291('schema and siteconfig preserved', version1291($lab)['schema'] === '2.2.3' &&
    strpos(file_get_contents($lab . '/public_html/siteconfig.php'), "\$protected = 'unchanged';") !== false);
refused1291('finalization replay refused', function () use ($lab) { finalize1291($lab); });
$lab = makeLab1291('finalize-tamper');
maintenanceRun1291($lab);
file_put_contents($lab . '/system/classes/Added.php', 'custom change');
refused1291('changed installed payload blocks finalization', function () use ($lab) { finalize1291($lab); });
check1291('unverified code stays offline and version unchanged', !online1291($lab) && version1291($lab) === $old);
$lab = makeLab1291('finalize-wrong-version');
maintenanceRun1291($lab);
Geeklog\UpdateSiteVersionLab::write($lab, '2.2.3', '2.2.4');
refused1291('unexpected source version refused', function () use ($lab) { finalize1291($lab); });
check1291('unexpected version preserved offline', version1291($lab)['code'] === '2.2.4' && !online1291($lab));
$lab = makeLab1291('finalize-resume');
maintenanceRun1291($lab);
// Simulate death after atomic version write, but before marker removal.
Geeklog\UpdateSiteVersionLab::write($lab, '2.2.3', '2.2.3.1');
check1291('resume after version write', finalize1291($lab)['status'] === 'finalized' && online1291($lab));
$lab = makeLab1291('finalize-wrong-tx');
maintenanceRun1291($lab);
$state = json_decode(file_get_contents($lab . '/.gl-maintenance-active'), true);
$state['transaction'] = str_repeat('a', 32);
file_put_contents($lab . '/.gl-maintenance-active', json_encode($state));
refused1291('unrelated transaction refused', function () use ($lab) { finalize1291($lab); });
check1291('unrelated transaction remains offline', !online1291($lab) && version1291($lab) === $old);
foreach (array('duplicate' => "<?php define('VERSION', '2.2.3'); define('VERSION', '2.2.3');",
    'dynamic' => "<?php define('VERSION', \$version);",
    'missing' => "<?php // define('VERSION', '2.2.3');") as $name => $bytes) {
    $lab = makeLab1291('siteconfig-' . $name);
    file_put_contents($lab . '/public_html/siteconfig.php', $bytes);
    refused1291('invalid siteconfig ' . $name, function () use ($lab) { maintenanceRun1291($lab); });
    check1291('invalid siteconfig untouched ' . $name, file_get_contents($lab . '/public_html/siteconfig.php') === $bytes);
}

function signedPatch1291(array $changes, array $applied = array())
{
    $record = $GLOBALS['record'];
    $metadata = json_decode(file_get_contents($GLOBALS['fixture'] . '/patch-metadata.json'), true);
    $record['id'] = 'patch-1291';
    $record['kind'] = 'patch';
    $record['version'] = '2.2.3';
    $record['package']['bytes'] = $metadata['bytes'];
    $record['package']['sha256'] = $metadata['sha256'];
    $record = array_replace($record, $changes);
    $catalog = $GLOBALS['catalog'];
    $catalog['updates'] = array($record);
    $json = json_encode($catalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!openssl_sign($json, $signature, $GLOBALS['private'], OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Signing failed');
    }
    $verified = UpdateCatalog::fromSigned($json, $signature, $GLOBALS['public'], 'updates.example.invalid', 1796083200);
    $updates = $verified->applicable('2.2.3', '2.2.3', PHP_VERSION, 'mysql', $applied);
    return $updates[0];
}
$patchArchive = $fixture . '/patch-1291.zip';
$patch = signedPatch1291(array('supersedes' => array('patch-old')));
$lab = makeLab1291('patch-success');
$before = file_get_contents($lab . '/public_html/siteconfig.php');
$result = UpdateMaintenanceLab::run($lab, $patchArchive, $patch, '2.2.3', '2.2.3');
check1291('patch commit remains offline', $result['status'] === 'committed' && !online1291($lab));
$result = UpdateMaintenanceLab::finalize($lab, $patchArchive, $patch);
check1291('patch finalization keeps version and schema', $result['status'] === 'finalized' && version1291($lab) === $old && online1291($lab));
check1291('patch leaves entire siteconfig untouched', file_get_contents($lab . '/public_html/siteconfig.php') === $before);
$history = Geeklog\UpdateHistoryLab::read($lab);
check1291('patch and superseded identifier retained', Geeklog\UpdateHistoryLab::applied($history) === array('patch-1291', 'patch-old'));
refused1291('patch replay refused before maintenance', function () use ($lab, $patchArchive, $patch) {
    UpdateMaintenanceLab::run($lab, $patchArchive, $patch, '2.2.3', '2.2.3');
});
check1291('replay does not take site offline', online1291($lab));
$lab = makeLab1291('patch-require');
$requires = signedPatch1291(array('requires' => array('prerequisite')), array('prerequisite'));
refused1291('missing dependency blocked using installed history', function () use ($lab, $patchArchive, $requires) {
    UpdateMaintenanceLab::run($lab, $patchArchive, $requires, '2.2.3', '2.2.3');
});
check1291('missing dependency causes no file changes', online1291($lab) && original1291($lab));
$lab = makeLab1291('patch-conflict');
file_put_contents($lab . '/.gl-lab-history.json', json_encode($history));
$conflict = signedPatch1291(array('id' => 'another-patch', 'conflicts' => array('patch-old')));
refused1291('conflict with superseded identifier blocked', function () use ($lab, $patchArchive, $conflict) {
    UpdateMaintenanceLab::run($lab, $patchArchive, $conflict, '2.2.3', '2.2.3');
});
check1291('conflict leaves site online', online1291($lab) && original1291($lab));
$lab = makeLab1291('patch-resume');
UpdateMaintenanceLab::run($lab, $patchArchive, $patch, '2.2.3', '2.2.3');
$state = json_decode(file_get_contents($lab . '/.gl-maintenance-active'), true);
Geeklog\UpdateHistoryLab::record($lab, $patch, $state['transaction'], $state['history']);
check1291('resume after receipt publication', UpdateMaintenanceLab::finalize($lab, $patchArchive, $patch)['status'] === 'finalized' &&
    count(Geeklog\UpdateHistoryLab::read($lab)) === 1 && online1291($lab));
$lab = makeLab1291('patch-rollback');
$result = UpdateMaintenanceLab::run($lab, $patchArchive, $patch, '2.2.3', '2.2.3', 1);
check1291('failed patch has no receipt', $result['status'] === 'rolled_back' &&
    Geeklog\UpdateHistoryLab::read($lab) === array() && original1291($lab) && online1291($lab));
$lab = makeLab1291('patch-history-corrupt');
file_put_contents($lab . '/.gl-lab-history.json', 'bad');
refused1291('corrupted history blocks patch before maintenance', function () use ($lab, $patchArchive, $patch) {
    UpdateMaintenanceLab::run($lab, $patchArchive, $patch, '2.2.3', '2.2.3');
});
check1291('corrupted history leaves files intact', original1291($lab) && online1291($lab));
echo "TOTAL: $passed/$total maintenance cases passed\n";
exit($passed === $total ? 0 : 1);
