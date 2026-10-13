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
// Action-oriented report. Do not infer a safe change from uncertain static parsing.
$bootstrap = array('path','path_system','site_enabled','default_charset');
$keys = array_unique(array_merge(array_keys($fileValues), array_keys($dbValues)));
sort($keys, SORT_STRING);
$actions = array();
$keep = array();
$review = array();
$ignored = 0;
foreach ($keys as $key) {
    if (ca_sensitive($key)) { $ignored++; continue; }
    $f = isset($fileValues[$key]) ? $fileValues[$key] : null;
    if (!$f) continue; // Objective: reduce siteconfig.php, not list the whole DB.
    $d = isset($dbValues[$key]) ? $dbValues[$key] : null;
    $hasEffective = array_key_exists($key, $effective);
    $e = $hasEffective ? $effective[$key] : null;
    $row = array('key'=>$key, 'line'=>$f['line'], 'file'=>$f['ok'] ? ca_fmt($f['value']) : $f['expression'],
        'db'=>$d ? ($d['ok'] ? ca_fmt($d['value']) : ($d['raw'] === 'unset' ? 'unset' : 'non decodable')) : 'absent',
        'effective'=>$hasEffective ? ca_fmt($e) : 'absent', 'action'=>'');
    if (in_array($key, $bootstrap, true)) {
        $row['action'] = 'Conserver : parametre de demarrage / a verifier avant changement';
        $keep[] = $row;
    } elseif (!$f['ok'] || !$hasEffective || !$d || !$d['ok']) {
        $row['action'] = 'Verifier manuellement : expression, valeur effective ou DB incertaine';
        $review[] = $row;
    } elseif ($f['value'] === $d['value'] && $e === $d['value']) {
        $row['action'] = 'Candidat a retirer du siteconfig APRES verification des dependances';
        $actions[] = $row;
    } elseif ($e === $f['value'] && $d['value'] !== $e) {
        $row['action'] = '1. Aligner la valeur DB sur la valeur effective via Configuration Manager ; 2. Retester ; 3. Retirer cette affectation';
        $actions[] = $row;
    } else {
        $row['action'] = 'Verifier la valeur cible et l ordre de chargement ; ne rien supprimer';
        $review[] = $row;
    }
}
function ca_table($rows) {
    if (!$rows) { echo '<p>Aucune ligne dans cette categorie.</p>'; return; }
    echo '<table><thead><tr><th>Cle / ligne</th><th>siteconfig.php</th><th>Base Core</th><th>Valeur chargee</th><th>Action</th></tr></thead><tbody>';
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
h1{font-size:1.55rem}h2{font-size:1.2rem;margin-top:30px}
table{border-collapse:collapse;width:100%;font-size:13px}
th,td{border:1px solid #d8dde3;padding:8px;vertical-align:top;text-align:left;word-break:break-word}
th{background:#edf1f4}tr:nth-child(even){background:#f8fafb}
code{white-space:pre-wrap;word-break:break-word}
small{color:#58606b}.warn{color:#954e00}
@media(max-width:800px){table{display:block;overflow-x:auto}}
</style></head><body>
<h1>Que modifier dans la base puis dans siteconfig.php ?</h1>
<p><strong>Domaine :</strong> <?php echo ca_h($host); ?> · <strong>siteconfig :</strong> <?php echo ca_h($sitefile); ?> · <strong>PHP :</strong> <?php echo ca_h(PHP_VERSION); ?></p>
<p><strong><?php echo count($actions); ?></strong> candidats · <strong><?php echo count($review); ?></strong> a verifier · <strong><?php echo count($keep); ?></strong> a conserver</p>
<p><strong>Procedure :</strong> sauvegarder la base et siteconfig.php ; modifier d'abord la configuration Core dans Geeklog ; confirmer la valeur chargee apres actualisation ; retirer seulement ensuite la ligne concernee ; tester administration et pages publiques sur chaque domaine. Aucune correction n'est appliquee par ce script.</p>
<h2>1. Modifications candidates (base puis siteconfig)</h2>
<p class="warn">La lecture de siteconfig.php est statique et partielle. Ces lignes sont des candidats, pas une preuve que l'affectation peut etre retiree sans risque : confirmer le bloc de domaine, les dependances et l'ordre d'initialisation. Ne pas recopier de SQL avec des valeurs serialisees a la main.</p>
<?php ca_table($actions); ?>
<h2>2. A verifier avant toute modification</h2>
<?php ca_table($review); ?>
<h2>3. A conserver dans siteconfig.php</h2>
<?php ca_table($keep); ?>
<?php if ($errors || $parseWarnings): ?>
<h2>Limites de l'analyse</h2>
<?php foreach (array_merge($errors,$parseWarnings) as $err): ?><p class="warn"><?php echo ca_h($err); ?></p><?php endforeach; ?>
<?php endif; ?>
<p><small>Lecture seule. Valeurs sensibles masquees (<?php echo (int)$ignored; ?> cles). Conditions multisites et expressions complexes non evaluees. Supprimer ce script public apres audit.</small></p>
</body></html>
