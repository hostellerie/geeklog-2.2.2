<?php
require_once dirname(__DIR__) . '/system/classes/UpdateCatalog.php';
require_once dirname(__DIR__) . '/system/classes/UpdateTransactionLab.php';
use Geeklog\UpdateCatalog;
use Geeklog\UpdateTransactionLab;
require_once dirname(__DIR__) . '/system/classes/UpdateRunnerLab.php';
use Geeklog\UpdateRunnerLab;
use Geeklog\UpdateSchemaLab;
use Geeklog\UpdateSiteVersionLab;
use Geeklog\UpdateHistoryLab;

$fixture = $argv[1];
$archive = $fixture . '/geeklog-2.2.3.1.zip';
$metadata = json_decode(file_get_contents($fixture . '/metadata.json'), true);
$record = array('id' => 'geeklog-2.2.3.1', 'kind' => 'release',
    'from' => array('2.2.3'), 'from_schema' => array('2.2.3'), 'version' => '2.2.3.1',
    'schema' => '2.2.3', 'php_min' => '5.6.4', 'databases' => array('mysql'),
    'requires' => array(), 'conflicts' => array(), 'supersedes' => array(), 'issues' => array(1291),
    'security' => false, 'notes' => 'Disposable fixture',
    'package' => array('url' => 'https://localhost/good.zip',
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


$total = $passed = 0;
function check1291($label, $ok)
{
    $GLOBALS['total']++;
    $GLOBALS['passed'] += $ok ? 1 : 0;
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
}
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=geeklog_lab', 'geeklog', 'geeklog_test_only', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$pdo->exec('CREATE TEMPORARY TABLE gl_vars (name VARCHAR(80), value VARCHAR(80))');
$pdo->exec("INSERT INTO gl_vars VALUES ('database_version', '2.2.3'), ('sentinel', 'unchanged')");
$probe = new UpdateSchemaLab($pdo, 'gl_vars');
$lab = makeLab1291('runner');
$result = UpdateRunnerLab::install($lab, $json, $signature, $public, 'localhost', 1796083200, $record['id'], $probe);
check1291('full signed update finalized', $result['status'] === 'finalized');
check1291('code version advanced', UpdateSiteVersionLab::read($lab) === '2.2.3.1');
check1291('SQL preserved', $probe->read() === '2.2.3' && $pdo->query("SELECT value FROM gl_vars WHERE name = 'sentinel'")->fetchColumn() === 'unchanged');
check1291('history recorded', UpdateHistoryLab::applied(UpdateHistoryLab::read($lab)) === array($record['id']));
check1291('archive retained', count(glob($lab . '/.gl-download-*/package.zip')) === 1);
check1291('site unlocked', !file_exists($lab . '/.gl-maintenance-active'));
try { UpdateRunnerLab::install($lab, $json, $signature, $public, 'localhost', 1796083200, $record['id'], $probe); check1291('replay refused', false); }
catch (RuntimeException $e) { check1291('replay refused', count(glob($lab . '/.gl-download-*/package.zip')) === 1); }
echo "TOTAL: $passed/$total end-to-end cases passed\n";
exit($passed === $total ? 0 : 1);
