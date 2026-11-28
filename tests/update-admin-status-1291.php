<?php
// Execute the actual admin page against a minimal isolated bootstrap fixture.
// This is not a full Geeklog authentication or HTTP integration test.
$root = $argv[1] . '/admin-status';
mkdir($root . '/admin', 0700, true);
copy(dirname(__DIR__) . '/public_html/admin/coreupdates.php', $root . '/admin/coreupdates.php');
file_put_contents($root . '/admin/auth.inc.php', '<?php // fixture');
file_put_contents($root . '/lib-common.php', <<<'BOOT'
<?php
$_CONF = array('core_update_status' => $argv[1] === 'enabled');
$_TABLES = array('vars' => 'gl_vars');
$_SERVER['REQUEST_METHOD'] = $argv[3];
$MESSAGE = array(29 => 'Denied', 30 => 'Error');
define('VERSION', '2.2.3.1');
function SEC_inGroup($group) { return $group === 'Root' && $GLOBALS['argv'][2] === 'root'; }
function COM_accessLog($message) {}
function COM_output($message) { echo $message; }
function COM_createHTMLDocument($message, $options) { return $message; }
function COM_showMessageText($message, $title) { return $message; }
function COM_getLanguage() { return $GLOBALS['argv'][4]; }
function DB_getItem($table, $field, $where) { echo '[DB read]'; return '2.2.3'; }
function COM_startBlock($title, $a, $b) { return $title; }
function COM_endBlock($a) { return ''; }
function COM_getBlockTemplate($a, $b) { return ''; }
register_shutdown_function(function () { echo '[HTTP:' . http_response_code() . ']'; });
BOOT
);
chdir($root . '/admin');
$total = $passed = 0;
function check1291($label, $ok)
{
    $GLOBALS['total']++;
    $GLOBALS['passed'] += $ok ? 1 : 0;
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
}
function page1291($enabled, $role, $method, $language)
{
    $args = array(PHP_BINARY, 'coreupdates.php', $enabled, $role, $method, $language);
    exec(implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $lines, $status);
    if ($status !== 0) { throw new RuntimeException(implode("\n", $lines)); }
    return implode("\n", $lines);
}
$page = page1291('enabled', 'root', 'GET', 'english');
check1291('Root opted-in status is readable', strpos($page, 'Core updates') !== false && strpos($page, '2.2.3.1') !== false && strpos($page, '[DB read]') !== false);
$page = page1291('enabled', 'root', 'GET', 'french');
check1291('French status', strpos($page, 'Mises à jour du noyau') !== false);
foreach (array(array('disabled', 'root'), array('enabled', 'member')) as $case) {
    $page = page1291($case[0], $case[1], 'GET', 'english');
    check1291('denied ' . implode('/', $case), strpos($page, 'Denied') !== false && strpos($page, '[DB read]') === false);
}
$page = page1291('enabled', 'root', 'POST', 'english');
check1291('POST refused without SQL read', strpos($page, '[HTTP:405]') !== false && strpos($page, '[DB read]') === false);
echo "TOTAL: $passed/$total admin status cases passed\n";
exit($passed === $total ? 0 : 1);
