<?php
require_once dirname(__DIR__) . '/system/classes/UpdateCatalog.php';

use Geeklog\UpdateCatalog;

$private = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
if ($private === false) {
    fwrite(STDERR, "Cannot generate test key\n");
    exit(1);
}
$public = openssl_pkey_get_details($private)['key'];
$host = 'updates.example.invalid';
$now = 1796083200; // 2026-12-01 00:00 UTC
$release = array(
    'id' => 'geeklog-2.2.3.1', 'kind' => 'release', 'from' => array('2.2.3'),
    'from_schema' => array('2.2.3'), 'version' => '2.2.3.1', 'schema' => '2.2.3',
    'php_min' => '5.6.4', 'php_max' => '8.5.99', 'databases' => array('mysql', 'pgsql'),
    'requires' => array(), 'conflicts' => array(), 'supersedes' => array('patch-a'),
    'issues' => array(1291), 'security' => false, 'notes' => 'Test fixture only',
    'package' => array('url' => 'https://' . $host . '/core/release.zip',
        'bytes' => 1234, 'sha256' => str_repeat('a', 64))
);
$patch = $release;
$patch['id'] = 'patch-a';
$patch['kind'] = 'patch';
$patch['version'] = '2.2.3';
$patch['supersedes'] = array();
$patch['requires'] = array('previous-patch');
$patch['conflicts'] = array('incompatible-patch');
$patch['package']['url'] = 'https://' . $host . '/core/patch.zip';
$catalog = array('format' => 1, 'sequence' => 10, 'published_at' => '2026-12-01T00:00:00Z',
    'expires_at' => '2026-12-31T00:00:00Z', 'channel' => 'stable', 'updates' => array($patch, $release));
function encode1291($value) { return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
function signed1291($json)
{
    if (!openssl_sign($json, $signature, $GLOBALS['private'], OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Unable to sign fixture');
    }
    return $signature;
}
function verify1291($json, $seq = 0, $digest = '', $signature = null)
{
    return UpdateCatalog::fromSigned($json, $signature === null ? signed1291($json) : $signature,
        $GLOBALS['public'], $GLOBALS['host'], $GLOBALS['now'], $seq, $digest);
}
$total = 0;
$passed = 0;
function check1291($label, $ok)
{
    $GLOBALS['total']++;
    $GLOBALS['passed'] += $ok ? 1 : 0;
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
}
function reject1291($label, $callback)
{
    try {
        $callback();
        check1291($label, false);
    } catch (InvalidArgumentException $e) {
        check1291($label, true);
    }
}
$json = encode1291($catalog);
$verified = verify1291($json);
check1291('signed catalog accepted', $verified->getSequence() === 10 && $verified->getDigest() === hash('sha256', $json));
check1291('same sequence same digest accepted', verify1291($json, 10, hash('sha256', $json))->getSequence() === 10);
reject1291('modified catalog signature rejected', function () use ($json) {
    verify1291(str_replace('Test fixture only', 'Changed', $json), 0, '', signed1291($json));
});
reject1291('key file references are rejected', function () use ($json, $host, $now) {
    UpdateCatalog::fromSigned($json, signed1291($json), 'file:///tmp/key.pem', $host, $now);
});
reject1291('wrong trusted key rejected', function () use ($json, $host, $now) {
    $other = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
    $otherPublic = openssl_pkey_get_details($other)['key'];
    UpdateCatalog::fromSigned($json, signed1291($json), $otherPublic, $host, $now);
});
reject1291('malformed signed JSON rejected', function () { verify1291('{invalid'); });
reject1291('missing required field rejected', function () use ($catalog) {
    unset($catalog['updates'][1]['schema']); verify1291(encode1291($catalog));
});
reject1291('bad signature rejected', function () use ($json) { verify1291($json, 0, '', 'bad'); });
reject1291('old sequence rejected', function () use ($json) { verify1291($json, 11, str_repeat('b', 64)); });
reject1291('same sequence different digest rejected', function () use ($json) {
    verify1291($json, 10, str_repeat('b', 64));
});
reject1291('signed duplicate key rejected', function () use ($json) {
    verify1291(str_replace('"format":1', '"format":1,"format":1', $json));
});
reject1291('noncanonical whitespace rejected', function () use ($json) { verify1291($json . "\n"); });
reject1291('expired catalog rejected', function () use ($catalog) {
    $catalog['published_at'] = '2026-11-01T00:00:00Z';
    $catalog['expires_at'] = '2026-11-30T00:00:00Z';
    verify1291(encode1291($catalog));
});
reject1291('future catalog rejected', function () use ($catalog) {
    $catalog['published_at'] = '2026-12-02T00:00:00Z';
    verify1291(encode1291($catalog));
});
reject1291('duplicate update ID rejected', function () use ($catalog) {
    $catalog['updates'][] = $catalog['updates'][0]; verify1291(encode1291($catalog));
});
foreach (array(
    'invalid version' => array('version', '2.2.3.01'),
    'release downgrade' => array('version', '2.2.2'),
    'schema downgrade' => array('schema', '2.2.2'),
    'invalid PHP range' => array('php_max', '5.2.0'),
    'unknown field' => array('extra', true),
    'self-dependency' => array('requires', array('geeklog-2.2.3.1')),
    'bad digest' => array('package', array('url' => 'https://' . $host . '/a.zip', 'bytes' => 1, 'sha256' => 'bad')),
    'foreign URL' => array('package', array('url' => 'https://untrusted.invalid/a.zip', 'bytes' => 1, 'sha256' => str_repeat('a', 64))),
    'path traversal' => array('package', array('url' => 'https://' . $host . '/../a.zip', 'bytes' => 1, 'sha256' => str_repeat('a', 64)))
) as $label => $change) {
    reject1291($label, function () use ($catalog, $change) {
        $catalog['updates'][1][$change[0]] = $change[1]; verify1291(encode1291($catalog));
    });
}
$updates = $verified->applicable('2.2.3', '2.2.3', '8.5.13', 'mysql');
check1291('release selected, dependency-gated patch excluded', count($updates) === 1 && $updates[0]['id'] === $release['id']);
$updates = $verified->applicable('2.2.3', '2.2.3', '8.4.1', 'pgsql', array('previous-patch'));
check1291('release preferred and eligible patch listed', count($updates) === 2 && $updates[0]['id'] === $release['id']);
foreach (array(
    array('schema mismatch', '2.2.3', '2.2.2', '8.4.1', 'mysql', array()),
    array('code mismatch', '2.2.2', '2.2.3', '8.4.1', 'mysql', array()),
    array('PHP below range', '2.2.3', '2.2.3', '5.6.3', 'mysql', array()),
    array('PHP above range', '2.2.3', '2.2.3', '8.6.0', 'mysql', array())
) as $case) {
    check1291($case[0], count($verified->applicable($case[1], $case[2], $case[3], $case[4], $case[5])) === 0);
}
check1291('conflicting patch excluded', count($verified->applicable('2.2.3', '2.2.3', '8.4.1', 'mysql', array('previous-patch', 'incompatible-patch'))) === 1);
check1291('previously applied patch excluded', count($verified->applicable('2.2.3', '2.2.3', '8.4.1', 'mysql', array('previous-patch', 'patch-a'))) === 1);
check1291('mysql-innodb alias supported', count($verified->applicable('2.2.3', '2.2.3', '8.4.1', 'mysql-innodb')) === 1);
reject1291('invalid installed code rejected', function () use ($verified) {
    $verified->applicable('invalid', '2.2.3', '8.4.1', 'mysql');
});
echo "TOTAL: $passed/$total catalog cases passed\n";
exit($passed === $total ? 0 : 1);
