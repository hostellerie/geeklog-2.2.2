<?php
/**
 * Geeklog 2.2.2 multisite configuration audit, PHP 5.6+
 * READ ONLY. Install temporarily in public_html/config-audit.php.
 * Requires a logged-in Geeklog Root account.
 * Does not include/re-execute siteconfig.php or db-config.php.
 * Does not generate SQL updates or alter server configuration.
 */
require_once __DIR__ . '/lib-common.php';
if (!function_exists('SEC_inGroup') || !SEC_inGroup('Root')) {
    http_response_code(403);
    exit('Acces refuse');
}
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store, private');

function ca_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ca_fmt($v) {
    if ($v === null) return 'NULL';
    if ($v === true) return 'true';
    if ($v === false) return 'false';
    if (is_array($v)) return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (is_object($v)) return '[objet]';
    return (string)$v;
}
function ca_sensitive($key) {
    return (bool)preg_match('/pass|password|secret|token|api.?key|private|credential|auth/i', $key);
}
/**
 * Validate serialized PHP data before unserialize, including PHP 5.6.
 * Only scalar/array grammar is permitted: no objects, custom objects or references.
 */
function ca_serialToken($raw, &$pos, $depth) {
    $len = strlen($raw);
    if ($depth > 24 || $pos >= $len) return false;
    $type = $raw[$pos++];
    if ($type === 'N') {
        if (substr($raw, $pos, 1) !== ';') return false;
        $pos++; return true;
    }
    if (substr($raw, $pos, 1) !== ':') return false;
    $pos++;
    if ($type === 's') {
        $colon = strpos($raw, ':', $pos);
        if ($colon === false) return false;
        $size = substr($raw, $pos, $colon - $pos);
        if (!preg_match('/^(0|[1-9][0-9]*)$/D', $size) || strlen($size) > 9) return false;
        $size = (int)$size;
        $pos = $colon + 1;
        if (substr($raw, $pos, 1) !== '"' || $size > $len - $pos - 3) return false;
        $pos++;
        $pos += $size;
        if (substr($raw, $pos, 2) !== '";') return false;
        $pos += 2; return true;
    }
    $end = strpos($raw, ';', $pos);
    if ($type === 'a') {
        $colon = strpos($raw, ':', $pos);
        if ($colon === false) return false;
        $num = substr($raw, $pos, $colon - $pos);
        if (!preg_match('/^(0|[1-9][0-9]*)$/D', $num) || strlen($num) > 7) return false;
        $count = (int)$num;
        $pos = $colon + 1;
        if (substr($raw, $pos, 1) !== '{' || $count > 10000) return false;
        $pos++;
        for ($i = 0; $i < $count; $i++) {
            if (!ca_serialToken($raw, $pos, $depth + 1) ||
                !ca_serialToken($raw, $pos, $depth + 1)) return false;
        }
        if (substr($raw, $pos, 1) !== '}') return false;
        $pos++; return true;
    }
    if ($end === false) return false;
    $v = substr($raw, $pos, $end - $pos);
    if ($type === 'b') $valid = ($v === '0' || $v === '1');
    elseif ($type === 'i') $valid = (bool)preg_match('/^-?[0-9]+$/D', $v);
    elseif ($type === 'd') $valid = is_numeric($v) || in_array($v, array('NAN', 'INF', '-INF'), true);
    else return false;
    if (!$valid) return false;
    $pos = $end + 1; return true;
}
function ca_decode($raw, &$ok) {
    $ok = false;
    if (!is_string($raw) || strlen($raw) > 1048576) return null;
    if ($raw === 'unset') return null; // Geeklog unset sentinel, not corrupt data
    $pos = 0;
    if (!ca_serialToken($raw, $pos, 0) || $pos !== strlen($raw)) return null;
    // The grammar above excludes O, C, R and r, so this cannot create objects.
    $value = @unserialize($raw);
    if ($value === false && $raw !== 'b:0;') return null;
    $ok = true;
    return $value;
}
function ca_expr($expr, $known, &$ok) {
    $expr = trim($expr);
    $parts = preg_split('/\s*\.\s*(?=(?:[^\']*\'[^\']*\')*[^\']*$)/', $expr);
    $output = '';
    foreach ($parts as $part) {
        $part = trim($part);
        if (preg_match('/^\'([^\']*)\'$/', $part, $m) || preg_match('/^"([^"]*)"$/', $part, $m)) {
            $val = $m[1];
        } elseif (preg_match('/^\$_CONF\[\s*[\'\"]([^\'\"]+)[\'\"]\s*\]$/', $part, $m)) {
            if (!array_key_exists($m[1], $known) || $known[$m[1]]['ok'] !== true) { $ok = false; return null; }
            $val = $known[$m[1]]['value'];
        } elseif ($part === 'true') $val = true;
        elseif ($part === 'false') $val = false;
        elseif (preg_match('/^[0-9]+$/', $part)) $val = (int)$part;
        else { $ok = false; return null; }
        if (count($parts) === 1) return $val;
        $output .= (string)$val;
    }
    return $output;
}
/** Limited static analysis of the simple host blocks used by this deployment.
 * Unknown syntax is explicitly flagged rather than executed or guessed.
 */
function ca_siteconfig($file, $host) {
    $out = array(); $problems = array();
    if (!is_readable($file)) return array($out, array('siteconfig.php illisible'));
    $lines = file($file);
    $inHost = false; $active = true;
    foreach ($lines as $num => $line) {
        $s = trim($line);
        if (preg_match('/^if\s*\(.*\$_SERVER\s*\[\s*[\'\"]HTTP_HOST[\'\"]\s*\]/', $s)) {
            $inHost = true;
            preg_match_all('/(?:==|===)\s*[\'\"]([^\'\"]+)[\'\"]/', $s, $matches);
            $active = in_array($host, $matches[1], true);
            if (empty($matches[1])) $problems[] = 'Condition de domaine non analysee a la ligne ' . ($num + 1);
            continue;
        }
        if ($inHost && $s === '}') { $inHost = false; $active = true; continue; }
        if (!$active || $s === '' || substr($s, 0, 2) === '//') continue;
        if (preg_match('/^\$_CONF\[\s*[\'\"]([^\'\"]+)[\'\"]\s*\]\s*=\s*(.+?)\s*;\s*(?:\/\/.*)?$/', $s, $m)) {
            $key = $m[1]; $ok = true;
            $val = ca_expr($m[2], $out, $ok);
            $out[$key] = array('value' => $val, 'ok' => $ok, 'line' => $num + 1, 'expression' => $m[2]);
        }
    }
    $problems[] = 'Analyse siteconfig partielle : conditions elseif, blocs imbriques, affectations dynamiques et FCK non resolus de facon certaine. Comparer aux valeurs effectives.';
    return array($out, $problems);
}

$host = isset($_SERVER['HTTP_HOST']) ? strtolower(preg_replace('/:[0-9]+$/', '', $_SERVER['HTTP_HOST'])) : '';
$siteCandidates = array(__DIR__ . '/siteconfig.php', dirname(__DIR__) . '/siteconfig.php',
    isset($_CONF['path']) ? rtrim($_CONF['path'], '/') . '/siteconfig.php' : '');
$sitefile = '';
foreach ($siteCandidates as $candidate) {
    if ($candidate !== '' && is_readable($candidate)) { $sitefile = $candidate; break; }
}
if ($sitefile === '') $sitefile = __DIR__ . '/siteconfig.php';
list($fileValues, $parseWarnings) = ca_siteconfig($sitefile, $host);
$effective = isset($_CONF) && is_array($_CONF) ? $_CONF : array();
$dbValues = array(); $errors = array();
if (!isset($_TABLES['conf_values'])) {
    $errors[] = 'Table conf_values non declaree par Geeklog';
} else {
    $sql = "SELECT name, value FROM " . $_TABLES['conf_values'] . " WHERE group_name = 'Core'";
    $res = DB_query($sql);
    if ($res === false) $errors[] = 'Lecture de conf_values impossible';
    else {
        while ($row = DB_fetchArray($res)) {
            $valid = false;
            $decoded = ca_decode($row['value'], $valid);
            $dbValues[$row['name']] = array('value' => $decoded, 'ok' => $valid, 'raw' => $row['value']);
        }
    }
}
// PHP booleans and integer 0/1 are equivalent for Geeklog select flags.
// Preserve strict comparison for paths, URLs and all other strings.
function ca_equal($key, $a, $b) {
    $flags = array('likes_enabled', 'likes_articles', 'likes_comments');
    if (in_array($key, $flags, true)) {
        $isFlag = function ($v) {
            return is_bool($v) || is_int($v) && ($v === 0 || $v === 1)
                || is_string($v) && ($v === '0' || $v === '1');
        };
        if ($isFlag($a) && $isFlag($b)) return (int)$a === (int)$b;
    }
    return $a === $b;
}
// Keep the startup settings that the distributed siteconfig.php explicitly defines.
// These are not cleanup candidates (even when DB differs).
$coreKeep = array('site_enabled','path','path_system','default_charset');
$keys = array_unique(array_merge(array_keys($fileValues), array_keys($dbValues)));
sort($keys, SORT_STRING);
$actions = array(); $review = array(); $sqlPaths = array(); $ignored = 0;
foreach ($keys as $key) {
    if (ca_sensitive($key)) { $ignored++; continue; }
    $f = isset($fileValues[$key]) ? $fileValues[$key] : null;
    if (!$f || in_array($key, $coreKeep, true)) continue;
    $d = isset($dbValues[$key]) ? $dbValues[$key] : null;
    $hasEffective = array_key_exists($key, $effective);
    $e = $hasEffective ? $effective[$key] : null;
    // It is important not to infer priority from siteconfig alone: values can
    // be overridden by config, user settings, or plugins during bootstrap.
    $row = array('key'=>$key,'line'=>$f['line'],
        'file'=>$f['ok'] ? ca_fmt($f['value']) : $f['expression'],
        'db'=>$d ? ($d['ok'] ? ca_fmt($d['value']) : ($d['raw']==='unset'?'unset':'invalide')) : 'absent',
        'effective'=>$hasEffective ? ca_fmt($e) : 'absent','action'=>'');
    if (!$f['ok'] || !$d || !$d['ok'] || !$hasEffective) {
        $row['action'] = 'A verifier : valeur ou provenance non certaine'; $review[] = $row; continue;
    }
    if (ca_equal($key, $f['value'], $d['value']) && ca_equal($key, $e, $d['value'])) {
        $row['action'] = 'Deja aligne : suppression envisageable apres test'; $actions[] = $row; continue;
    }
    if (!ca_equal($key, $f['value'], $d['value']) && ca_equal($key, $e, $f['value'])) {
        $row['action'] = 'DB differente : confirmer la valeur voulue puis corriger la DB et retester avant suppression';
        $actions[] = $row;
        // Generate SQL ONLY for absolute-path strings, matching runtime and
        // resolved static value. SQL is displayed, never run by the audit.
        if (is_string($e) && substr($e, 0, 1) === '/' &&
            (strpos($key,'path') !== false || strpos($key,'dir') !== false)) {
            $table = $_TABLES['conf_values'];
            $encoded = serialize($e);
            $sql = "UPDATE " . $table . " SET value = '" . DB_escapeString($encoded) .
                "' WHERE group_name = 'Core' AND name = '" . DB_escapeString($key) . "';";
            $sqlPaths[] = array('key'=>$key,'line'=>$f['line'],'sql'=>$sql);
        }
        continue;
    }
    $row['action'] = 'Valeur effective non concordante : ne pas modifier automatiquement';
    $review[] = $row;
}
function ca_table($rows) {
    if (!$rows) { echo '<p>Aucune ligne.</p>'; return; }
    echo '<table><thead><tr><th>Cle / ligne</th><th>siteconfig.php</th><th>Base</th><th>Chargee</th><th>Que faire ?</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td><strong>'.ca_h($r['key']).'</strong><br><small>ligne '.(int)$r['line'].'</small></td>';
        foreach (array('file','db','effective') as $col) echo '<td><code>'.ca_h($r[$col]).'</code></td>';
        echo '<td>'.ca_h($r['action']).'</td></tr>';
    }
    echo '</tbody></table>';
}
?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow">
<title>Config audit Geeklog</title><style>
body{font:15px/1.5 system-ui,Arial,sans-serif;max-width:1250px;margin:24px auto;padding:0 16px;color:#20262d}
h1{font-size:1.5rem}h2{font-size:1.2rem;margin-top:30px}
table{border-collapse:collapse;width:100%;font-size:13px}
th,td{border:1px solid #d8dde3;padding:8px;vertical-align:top;text-align:left;word-break:break-word}
th{background:#edf1f4}tr:nth-child(even){background:#f8fafb}
code,pre{white-space:pre-wrap;word-break:break-word}
small{color:#58606b}.warn{color:#954e00}
@media(max-width:800px){table{display:block;overflow-x:auto}}
</style></head><body>
<h1>Audit de nettoyage de siteconfig.php</h1>
<p><strong>Domaine :</strong> <?php echo ca_h($host); ?> · <strong>Fichier :</strong> <?php echo ca_h($sitefile); ?></p>
<p><?php echo count($actions); ?> candidats · <?php echo count($review); ?> a verifier. Les cles de demarrage du siteconfig.php distribue avec Geeklog 2.2.2 ne sont pas affichees.</p>
<h2>1. Parametres candidats a retirer de siteconfig.php</h2>
<p>Pour chaque ligne : confirmer la valeur souhaitee, la regler d'abord dans Geeklog / sa base, controler le site sans cette affectation, puis seulement la retirer. Une valeur differente en DB et dans le fichier <strong>n'est pas</strong> « alignee », meme si la valeur chargee correspond au fichier.</p>
<?php ca_table($actions); ?>
<h2>2. Requetes SQL proposees pour les chemins</h2>
<p class="warn">Lecture seule : aucune requete n'est executee. Ces UPDATE visent uniquement les valeurs de chemins absolus resolues et identiques a la valeur chargee, lorsque la DB est differente. Chaque valeur est serialisee par PHP, avec sa longueur exacte. Verifier domaine, table et chemin avant execution. Sauvegarder la DB, preferer Configuration Manager quand applicable, puis retester avant de modifier siteconfig.php.</p>
<?php if (!$sqlPaths): ?><p>Aucune correction SQL de chemin suffisamment certaine a proposer.</p><?php endif; ?>
<?php foreach ($sqlPaths as $row): ?>
<p><strong><?php echo ca_h($row['key']); ?></strong> (ligne <?php echo (int)$row['line']; ?>)</p>
<pre><?php echo ca_h($row['sql']); ?></pre>
<?php endforeach; ?>
<h2>3. A examiner manuellement</h2>
<?php ca_table($review); ?>
<?php if ($errors || $parseWarnings): ?>
<h2>Limites</h2>
<?php foreach (array_merge($errors,$parseWarnings) as $msg): ?><p class="warn"><?php echo ca_h($msg); ?></p><?php endforeach; ?>
<?php endif; ?>
<p><small>Parametres Core conserves et masques : site_enabled, path, path_system, default_charset. Egalement conserver les parametres d'amorcage FCK et les definitions VERSION/LB selon la distribution. Aucune modification executee. <?php echo (int)$ignored; ?> cles sensibles non affichees.</small></p>
</body></html>
