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
    'schema' => '2.2.3', 'php_min' => '5.6.4', 'databases' => array('mysql', 'pgsql'),
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

$driver = $argv[2];
$pdo = new PDO($driver === 'mysql' ? 'mysql:host=127.0.0.1;port=3306;dbname=geeklog_lab' :
    'pgsql:host=127.0.0.1;port=5432;dbname=geeklog_lab', 'geeklog', 'geeklog_test_only',
    array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$pdo->exec('CREATE TEMPORARY TABLE gl_vars (name VARCHAR(40), value VARCHAR(80))');
$pdo->exec("INSERT INTO gl_vars VALUES ('database_version', '2.2.3'), ('sentinel', 'preserved')");
$probe = new Geeklog\UpdateSchemaLab($pdo, 'gl_vars');
check1291('actual schema read', $probe->read() === '2.2.3');
refused1291('invalid table identifier refused', function () use ($pdo) { new Geeklog\UpdateSchemaLab($pdo, 'gl_vars; DROP'); });
$pdo->exec("INSERT INTO gl_vars VALUES ('database_version', '2.2.3')");
refused1291('duplicate schema row refused', function () use ($probe) { $probe->read(); });
$pdo->exec("DELETE FROM gl_vars WHERE name = 'database_version'");
refused1291('absent schema row refused', function () use ($probe) { $probe->read(); });
$pdo->exec("INSERT INTO gl_vars VALUES ('database_version', 'invalid')");
refused1291('invalid schema value refused', function () use ($probe) { $probe->read(); });
$pdo->exec("UPDATE gl_vars SET value = '2.2.3' WHERE name = 'database_version'");
$lab = makeLab1291('sql-' . $driver);
$result = UpdateMaintenanceLab::run($lab, $archive, $update, '2.2.3', '2.2.3', null, null, $probe);
check1291('code applied while real schema unchanged', $result['status'] === 'committed' && $probe->read() === '2.2.3');
refused1291('cannot switch SQL probe to fixture', function () use ($lab, $archive, $update) {
    UpdateMaintenanceLab::finalize($lab, $archive, $update);
});
$pdo->exec("UPDATE gl_vars SET value = '2.2.4' WHERE name = 'database_version'");
refused1291('changed SQL schema blocks finalization', function () use ($lab, $archive, $update, $probe) {
    UpdateMaintenanceLab::finalize($lab, $archive, $update, $probe);
});
check1291('siteconfig remains old and site offline', Geeklog\UpdateSiteVersionLab::read($lab) === '2.2.3' && !online1291($lab));
$pdo->exec("UPDATE gl_vars SET value = '2.2.3' WHERE name = 'database_version'");
$result = UpdateMaintenanceLab::finalize($lab, $archive, $update, $probe);
check1291('real schema unchanged after finalization', $result['status'] === 'finalized' &&
    Geeklog\UpdateSiteVersionLab::read($lab) === '2.2.3.1' && $probe->read() === '2.2.3' && online1291($lab));
check1291('unrelated SQL data preserved', $pdo->query("SELECT value FROM gl_vars WHERE name = 'sentinel'")->fetchColumn() === 'preserved');
echo "TOTAL: $passed/$total SQL schema cases passed\n";
exit($passed === $total ? 0 : 1);
