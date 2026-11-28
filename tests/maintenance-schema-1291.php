<?php
/**
 * Isolated tests for the real installer version methods (no database connection).
 */
require_once dirname(__DIR__) . '/public_html/admin/install/classes/Common.php';

class FakeDatabase1291
{
    public function setDisplayError($enabled) {}
}
class FakeMysqli1291
{
    const MYSQL_SQL_MODE_NONE = 0;
}
class_alias('FakeMysqli1291', 'Geeklog\\Database\\DbMysqli');
function DB_checkTableExists($table) { return true; }
function DB_getItem($table, $field, $where) { return $GLOBALS['storedVersion1291']; }
function DB_query($sql, $ignore = false) { throw new RuntimeException('Unexpected SQL: ' . $sql); }
function DB_setMysqlSqlMode($mode) {}
function DB_delete($table, $field, $value) { $GLOBALS['deleteCount1291']++; }

class InstallerProbe1291 extends Geeklog\Install\Common
{
    public $versionWrites = 0;
    public static function normalize($version) { return parent::normalizeDatabaseVersion($version); }
    public static function schema() { return parent::getTargetDatabaseVersion(); }
    public function detect() { return $this->identifyGeeklogVersion(); }
    public function upgrade($version, $messages = false) { return $this->doDatabaseUpgrades($version, $messages); }
    protected function setVersion($path) { $this->versionWrites++; }
}
class CodePatchProbe1291 extends InstallerProbe1291
{
    const GL_VERSION = '2.2.3.1';
}
class SchemaPatchProbe1291 extends InstallerProbe1291
{
    const GL_VERSION = '2.2.3.2';
    const GL_DATABASE_VERSION = '2.2.3.1';
}

$_DB = new FakeDatabase1291();
$_DB_dbms = 'mysql';
$_TABLES = array('vars' => 'test_vars');
Geeklog\Install\Common::$env['siteconfig_path'] = '/unused';
$deleteCount1291 = 0;
$probe = new InstallerProbe1291();
$total = 0;
$passed = 0;
function check1291($label, $condition)
{
    $GLOBALS['total']++;
    if ($condition) {
        $GLOBALS['passed']++;
    }
    echo ($condition ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
}
foreach (array(
    '2.2.2' => '2.2.2',
    '2.2.3b1' => '2.2.3',
    '2.2.3rc1' => '2.2.3',
    '2.2.3sr1' => '2.2.3',
    '2.2.3.1' => '2.2.3.1',
    '2.2.3-1' => '2.2.3.1',
    '2.2.3.2' => '2.2.3.2',
    'invalid' => '',
    '2.2.3.1junk' => ''
) as $stored => $expected) {
    $storedVersion1291 = $stored;
    check1291('detect ' . $stored, $probe->detect() === $expected);
}
check1291('default schema', InstallerProbe1291::schema() === '2.2.2');
check1291('code-only patch schema', CodePatchProbe1291::schema() === '2.2.3');
check1291('explicit schema patch', SchemaPatchProbe1291::schema() === '2.2.3.1');
check1291('unknown schema refused', $probe->upgrade('9.9.9') === false);
check1291('downgrade from maintenance schema refused', $probe->upgrade('2.2.2.1') === false);
check1291('no version writes on refusal', $probe->versionWrites === 0 && $deleteCount1291 === 0);
check1291('message check does not write version', $probe->upgrade('2.2.2', true) === true && $probe->versionWrites === 0);
check1291('known current schema accepted', $probe->upgrade('2.2.2') === true && $probe->versionWrites === 1);
echo "TOTAL: $passed/$total installer cases passed\n";
exit($passed === $total ? 0 : 1);
