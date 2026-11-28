<?php
/**
 * Exercise real Common::setVersion() using disposable files and a DB spy.
 */
require_once dirname(__DIR__) . '/public_html/admin/install/classes/Common.php';

function DB_escapeString($value) { return $value; }
function DB_change($table, $field, $value, $key, $name)
{
    $GLOBALS['dbWrites'][] = array($table, $field, $value, $key, $name);
    if (!empty($GLOBALS['invalidChild'])) {
        file_put_contents($GLOBALS['marker'], 'SQL write');
    }
}
class VersionWriter1291 extends Geeklog\Install\Common
{
    const GL_VERSION = '2.2.3.1';
    public function write($file) { $this->setVersion($file); }
}
class SchemaWriter1291 extends VersionWriter1291
{
    const GL_VERSION = '2.2.3.2';
    const GL_DATABASE_VERSION = '2.2.3.1';
}
$_TABLES = array('vars' => 'test_vars');
$dbWrites = array();
Geeklog\Install\Common::$LANG['INSTALL'][26] = 'Version write failed';
Geeklog\Install\Common::$LANG['INSTALL'][28] = 'Check siteconfig';

if (isset($argv[1]) && $argv[1] === '--invalid') {
    $invalidChild = true;
    $marker = $argv[3];
    $writer = new VersionWriter1291();
    $writer->write($argv[2]);
    exit(0);
}
$total = 0;
$passed = 0;
function checkWrite1291($label, $condition)
{
    $GLOBALS['total']++;
    $GLOBALS['passed'] += $condition ? 1 : 0;
    echo ($condition ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
}
$file = tempnam(sys_get_temp_dir(), 'gl1291');
$marker = $file . '.marker';
$original = "<?php\n// retained comment\n" .
    "define('VERSION', '2.2.3');\n" .
    "\$_CONF['path'] = '/example/private/';\n";
try {
    file_put_contents($file, $original);
    $writer = new VersionWriter1291();
    $writer->write($file);
    checkWrite1291('code version updated and other bytes retained',
        file_get_contents($file) === str_replace("'2.2.3'", "'2.2.3.1'", $original));
    checkWrite1291('code-only patch keeps schema',
        $dbWrites === array(array('test_vars', 'value', '2.2.3', 'name', 'database_version')));
    $writer = new SchemaWriter1291();
    $writer->write($file);
    checkWrite1291('schema patch has independent code version',
        file_get_contents($file) === str_replace("'2.2.3'", "'2.2.3.2'", $original));
    checkWrite1291('explicit schema revision recorded',
        $dbWrites[1] === array('test_vars', 'value', '2.2.3.1', 'name', 'database_version'));
    // Negative tests isolate the installer's intentional exit(1).
    foreach (array(
        'missing declaration' => "<?php\n// no version\n",
        'duplicate declaration' => $original . "define('VERSION', '2.2.3');\n"
    ) as $label => $invalid) {
        file_put_contents($file, $invalid);
        $output = array();
        $status = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) .
            ' --invalid ' . escapeshellarg($file) . ' ' . escapeshellarg($marker), $output, $status);
        checkWrite1291($label . ' refused without data changes',
            $status === 1 && file_get_contents($file) === $invalid && !file_exists($marker));
    }
} finally {
    @unlink($file);
    @unlink($marker);
}
echo "TOTAL: $passed/$total write cases passed\n";
exit($passed === $total ? 0 : 1);
