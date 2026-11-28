<?php
require_once dirname(__DIR__) . '/system/classes/UpdateCatalog.php';
require_once dirname(__DIR__) . '/system/classes/UpdatePreflight.php';
use Geeklog\UpdateCatalog;
use Geeklog\UpdatePreflight;

$fixture = $argv[1];
$archive = $fixture . '/geeklog-2.2.3.1.zip';
$base = $fixture . '/base';
mkdir($base . '/plugins');
mkdir($base . '/language');
$roots = array('public_html' => $base . '/public_html', 'system' => $base . '/system',
    'plugins' => $base . '/plugins', 'language' => $base . '/language');
$private = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
$public = openssl_pkey_get_details($private)['key'];
$metadata = json_decode(file_get_contents($fixture . '/metadata.json'), true);
$record = array(
    'id' => 'geeklog-2.2.3.1', 'kind' => 'release', 'from' => array('2.2.3'),
    'from_schema' => array('2.2.3'), 'version' => '2.2.3.1', 'schema' => '2.2.3',
    'php_min' => '5.6.4', 'databases' => array('mysql'), 'requires' => array(),
    'conflicts' => array(), 'supersedes' => array(), 'issues' => array(1291),
    'security' => false, 'notes' => 'Fixture only',
    'package' => array('url' => 'https://updates.example.invalid/fixture.zip',
        'bytes' => $metadata['bytes'], 'sha256' => $metadata['sha256']));
$catalog = array('format' => 1, 'sequence' => 1, 'published_at' => '2026-12-01T00:00:00Z',
    'expires_at' => '2026-12-31T00:00:00Z', 'channel' => 'stable', 'updates' => array($record));
$json = json_encode($catalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (!openssl_sign($json, $signature, $private, OPENSSL_ALGO_SHA256)) {
    throw new RuntimeException('Unable to sign fixture');
}
$catalog = UpdateCatalog::fromSigned($json, $signature, $public, 'updates.example.invalid', 1796083200);
$updates = $catalog->applicable('2.2.3', '2.2.3', PHP_VERSION, 'mysql');
$update = $updates[0];
function inspect1291($code = '2.2.3', $schema = '2.2.3', $shared = false, $map = null)
{
    return UpdatePreflight::inspect($GLOBALS['archive'], $GLOBALS['update'],
        $map === null ? $GLOBALS['roots'] : $map, $code, $schema, $shared);
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
    try {
        $callback(); check1291($label, false);
    } catch (InvalidArgumentException $e) { check1291($label, true); }
}
function status1291($report, $path, $status)
{
    foreach ($report['files'] as $file) {
        if ($file['path'] === $path) {
            return !$report['ready'] && $file['status'] === $status;
        }
    }
    return false;
}
$old = $base . '/public_html/example.php';
$added = $base . '/system/classes/Added.php';
$original = file_get_contents($old);
$report = inspect1291();
check1291('compatible baseline ready', $report['ready'] && count($report['files']) === 2);
check1291('backup and staging space accounted', count($report['volumes']) === 1 &&
    $report['volumes'][0]['required_bytes'] === UpdatePreflight::RESERVE_BYTES +
    strlen($original) + strlen("<?php // new\n") + strlen("<?php // add\n"));
check1291('code mismatch blocked', in_array('installed_version_mismatch', inspect1291('2.2.2')['blockers'], true));
check1291('schema mismatch blocked', in_array('installed_version_mismatch', inspect1291('2.2.3', '2.2.2')['blockers'], true));
check1291('shared code blocked', in_array('shared_code_or_multisite_unknown', inspect1291('2.2.3', '2.2.3', true)['blockers'], true));
check1291('unknown multisite state blocked', in_array('shared_code_or_multisite_unknown', inspect1291('2.2.3', '2.2.3', null)['blockers'], true));
file_put_contents($old, 'local customization');
check1291('local modification blocked', status1291(inspect1291(), 'public_html/example.php', 'locally_modified'));
check1291('customized bytes not overwritten', file_get_contents($old) === 'local customization');
file_put_contents($old, $original);
file_put_contents($added, 'existing custom file');
check1291('add target already exists blocked', status1291(inspect1291(), 'system/classes/Added.php', 'target_exists'));
unlink($added);
symlink($old, $added);
check1291('add symlink blocked', status1291(inspect1291(), 'system/classes/Added.php', 'symlink'));
unlink($added);
file_put_contents($base . '/system/classes/added.php', 'case mismatch');
check1291('case collision blocked', status1291(inspect1291(), 'system/classes/Added.php', 'case_collision'));
unlink($base . '/system/classes/added.php');
rename($old, $old . '.saved');
check1291('missing replacement blocked', status1291(inspect1291(), 'public_html/example.php', 'missing_or_nonregular'));
symlink($old . '.saved', $old);
check1291('replacement symlink blocked', status1291(inspect1291(), 'public_html/example.php', 'symlink'));
unlink($old);
rename($old . '.saved', $old);
link($old, $old . '.hardlink');
check1291('hardlinked original blocked', status1291(inspect1291(), 'public_html/example.php', 'unsafe_existing_file'));
unlink($old . '.hardlink');
rename($base . '/system/classes', $base . '/system/classes.saved');
check1291('missing parent blocked', status1291(inspect1291(), 'system/classes/Added.php', 'parent_missing_or_not_directory'));
symlink($base . '/system/classes.saved', $base . '/system/classes');
check1291('symlink parent blocked', status1291(inspect1291(), 'system/classes/Added.php', 'symlink'));
unlink($base . '/system/classes');
rename($base . '/system/classes.saved', $base . '/system/classes');
chmod($old, 0444); clearstatcache();
if (!is_writable($old)) {
    check1291('nonwritable file blocked', status1291(inspect1291(), 'public_html/example.php', 'file_not_writable'));
} else {
    echo "SKIP: running with elevated filesystem privileges\n";
}
chmod($old, 0644);
chmod($base . '/system/classes', 0555); clearstatcache();
if (!is_writable($base . '/system/classes')) {
    check1291('nonwritable parent blocked', status1291(inspect1291(), 'system/classes/Added.php', 'directory_not_writable'));
} else {
    echo "SKIP: running with elevated filesystem privileges\n";
}
chmod($base . '/system/classes', 0755);
$bad = $roots;
$bad['plugins'] = $roots['system'];
refused1291('overlapping roots rejected', function () use ($bad) { inspect1291('2.2.3', '2.2.3', false, $bad); });
symlink($base . '/plugins', $base . '/plugins-link');
$bad = $roots;
$bad['plugins'] = $base . '/plugins-link/';
refused1291('symbolic root alias rejected', function () use ($bad) { inspect1291('2.2.3', '2.2.3', false, $bad); });
unlink($base . '/plugins-link');
$bad = $roots;
$bad['plugins'] = $base . '/missing';
refused1291('absent root rejected', function () use ($bad) { inspect1291('2.2.3', '2.2.3', false, $bad); });
check1291('baseline still ready after all fixtures', inspect1291()['ready']);
check1291('source code and configuration unchanged', file_get_contents($old) === $original &&
    !file_exists($added) && file_get_contents($base . '/public_html/siteconfig.php') === 'protected');
echo "TOTAL: $passed/$total preflight cases passed\n";
exit($passed === $total ? 0 : 1);
