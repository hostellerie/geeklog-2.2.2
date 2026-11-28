<?php
/**
 * Standalone versioning regression probe for Geeklog issue #1291.
 * Does not bootstrap Geeklog or connect to a database.
 * Usage: php tests/maintenance-versioning-1291.php
 */
$root = dirname(__DIR__);
$source = file_get_contents($root . '/public_html/lib-common.php');
if ($source === false) {
    fwrite(STDERR, "Unable to read lib-common.php\n");
    exit(1);
}

// Load only the two real functions, without executing lib-common bootstrap.
$tokens = token_get_all($source);
$wanted = array('COM_versionConvert', 'COM_versionCompare');
$loaded = array();
for ($i = 0; $i < count($tokens); $i++) {
    if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
        continue;
    }
    $j = $i + 1;
    while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
        $j++;
    }
    if (!isset($tokens[$j]) || !is_array($tokens[$j]) ||
        $tokens[$j][0] !== T_STRING || !in_array($tokens[$j][1], $wanted, true)) {
        continue;
    }
    $name = $tokens[$j][1];
    $code = '';
    $depth = 0;
    $started = false;
    for ($k = $i; $k < count($tokens); $k++) {
        $token = $tokens[$k];
        $code .= is_array($token) ? $token[1] : $token;
        if ($token === '{') {
            $depth++;
            $started = true;
        } elseif ($token === '}') {
            $depth--;
            if ($started && $depth === 0) {
                break;
            }
        }
    }
    if (!$started || $depth !== 0) {
        fwrite(STDERR, "Incomplete function: " . $name . "\n");
        exit(1);
    }
    eval($code); // Only named functions from the local, trusted checkout.
    $loaded[] = $name;
}
if (count($loaded) !== count($wanted)) {
    fwrite(STDERR, "Expected version functions not found\n");
    exit(1);
}

$cases = array(
    array('2.2.2', '2.2.3b1'),
    array('2.2.3b1', '2.2.3b2'),
    array('2.2.3b2', '2.2.3rc1'),
    array('2.2.3rc1', '2.2.3'),
    array('2.2.3', '2.2.3sr1'),
    array('2.2.3sr1', '2.2.3-1'),
    array('2.2.3-1', '2.2.3-2'),
    array('2.2.3-2', '2.2.4'),
    array('2.2.3', '2.2.3.1'),
    array('2.2.3.1', '2.2.3.2'),
    array('2.2.3.2', '2.2.4')
);
$passed = 0;
$total = 0;
foreach ($cases as $case) {
    list($a, $b) = $case;
    $ok = COM_versionCompare($a, $b) === -1 &&
        COM_versionCompare($b, $a) === 1 &&
        COM_versionCompare($a, $b, '<') === true &&
        COM_versionCompare($a, $a, '==') === true;
    $total++;
    if ($ok) {
        $passed++;
    }
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $a . ' < ' . $b . "\n";
}

echo "\nDiagnostic conversions (not an assertion of alias equivalence):\n";
foreach (array('2.2.3b1', '2.2.3rc1', '2.2.3', '2.2.3sr1', '2.2.3-1', '2.2.3.1', '2.2.3.2', '2.2.4', 'invalid') as $version) {
    echo $version . ' => ' . COM_versionConvert($version) . "\n";
}
echo 'Legacy vs dotted patch: ' . COM_versionCompare('2.2.3-1', '2.2.3.1') . "\n";
foreach (array(
    array('2.2.3-1', '2.2.3.1'),
    array('2.2.3-2', '2.2.3.2'),
    array('2.2.3-1rc1', '2.2.3.1rc1')
) as $case) {
    $ok = COM_versionCompare($case[0], $case[1]) === 0;
    $total++;
    $passed += $ok ? 1 : 0;
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $case[0] . ' == ' . $case[1] . "\n";
}
echo "TOTAL: $passed/$total ordering cases passed\n";
exit($passed === $total ? 0 : 1);
