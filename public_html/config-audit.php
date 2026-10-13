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
// Show all declared siteconfig keys, plus core keys relevant to the migration.
$tracked = array('site_url','site_admin_url','site_enabled','path','path_system','path_html','path_data',
 'path_log','path_images','path_language','path_editors','path_pear','images_url','default_charset',
 'timezone','theme','locale','login_attempts','cache_templates','cache_blocks','cache_topics',
 'cache_pages','multi_install','backup_path','rdf_file','likes_enabled','likes_articles','likes_comments');
$keys = array_unique(array_merge($tracked, array_keys($fileValues), array_keys($dbValues)));
sort($keys, SORT_STRING);
$bootstrap = array('path', 'path_system', 'site_enabled', 'default_charset');
$counts = array('same'=>0,'different'=>0,'missing'=>0,'uncertain'=>0);
?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow">
<title>Audit Geeklog multisite</title><style>
body{font:15px/1.5 system-ui,Arial,sans-serif;max-width:1500px;margin:25px auto;padding:0 16px;color:#20262d}
h1{font-size:1.5rem}h2{font-size:1.15rem}table{border-collapse:collapse;width:100%;font-size:13px}
th,td{border:1px solid #d8dde3;padding:8px;vertical-align:top;text-align:left;word-break:break-word}
th{background:#edf1f4}tr:nth-child(even){background:#f8fafb}code{white-space:pre-wrap;word-break:break-word}
small{color:#58606b}.warn{color:#9a5300}.critical{color:#a00000}.good{color:#176039}
@media(max-width:800px){table{display:block;overflow-x:auto}}
</style></head><body>
<h1>Audit Geeklog 2.2.2 — lecture seule</h1>
<p><strong>Domaine :</strong> <?=ca_h($host)?> · <strong>siteconfig :</strong> <?=ca_h($sitefile)?> · <strong>Table :</strong> <?=ca_h(isset($_TABLES['conf_values']) ? $_TABLES['conf_values'] : 'indisponible')?> · <strong>PHP :</strong> <?=ca_h(PHP_VERSION)?></p>
<p><small>Lecture statique des affectations simples du fichier (aucune reevaluation de siteconfig.php). Les valeurs effectives peuvent inclure des preferences utilisateur ou des modifications apportees par des plugins.</small></p>
<?php foreach (array_merge($errors,$parseWarnings) as $err): ?><p class="critical"><?=ca_h($err)?></p><?php endforeach; ?>
<table><thead><tr><th>Cle</th><th>siteconfig.php</th><th>Base Core</th><th>Effective</th><th>Comparaison / controle</th><th>Orientation</th></tr></thead><tbody>
<?php foreach ($keys as $key):
    if (ca_sensitive($key)) continue;
    $f = isset($fileValues[$key]) ? $fileValues[$key] : null;
    $d = isset($dbValues[$key]) ? $dbValues[$key] : null;
    $e = array_key_exists($key, $effective) ? $effective[$key] : null;
    if (!$f && !$d && !array_key_exists($key,$effective)) continue;
    $same = $f && $d && $f['ok'] && $d['ok'] && ($f['value'] === $d['value']);
    if ($f && !$f['ok']) { $status = 'Expression non resolue'; $counts['uncertain']++; }
    elseif ($d && !$d['ok']) { $status = ($d['raw'] === 'unset' ? 'DB: non defini (unset)' : 'Valeur DB non decodable'); $counts['uncertain']++; }
    elseif ($f && !$d) { $status = 'Absent de la DB'; $counts['missing']++; }
    elseif ($f && $d && $same) { $status = 'Identique'; $counts['same']++; }
    elseif ($f && $d) { $status = 'Different'; $counts['different']++; }
    else { $status = 'DB / runtime uniquement'; }
    if (in_array($key, $bootstrap, true)) $action='Garder dans siteconfig (demarrage)';
    elseif ($f && !$d) $action='Garder / examiner usage et initialisation';
    elseif ($f && $d && $same) $action='Suppression envisageable apres test du chargement';
    elseif ($f && $d) $action='Comparer et choisir la valeur cible avant suppression';
    else $action='Pas de ligne correspondante a supprimer';
    $path = strpos($key, 'path_') === 0 || $key === 'path';
    if ($path && $f && $f['ok'] && is_string($f['value']) && !is_dir($f['value'])) $status .= ' / chemin fichier non trouve';
    foreach (array($f ? ($f['ok'] ? $f['value'] : null) : null, $d ? ($d['ok'] ? $d['value'] : null) : null, $e) as $checkValue) {
        if (is_string($checkValue) && strpos($checkValue, '/home/') === 0 &&
            isset($_CONF['path']) && strpos($checkValue, dirname(rtrim($_CONF['path'], '/'))) !== 0) {
            $status .= ' / ancien chemin home possible'; break;
        }
    }
    $effDifferent = $d && $d['ok'] && array_key_exists($key,$effective) && $e !== $d['value'];
    if ($effDifferent) $status .= ' / effective != DB';
?>
<tr><td><strong><?=ca_h($key)?></strong></td>
<td><?php if ($f): ?><code><?=ca_h($f['ok'] ? ca_fmt($f['value']) : '[non resolu] '.$f['expression'])?></code><br><small>ligne <?=intval($f['line'])?></small><?php else: ?>—<?php endif; ?></td>
<td><code><?=ca_h($d ? ($d['ok'] ? ca_fmt($d['value']) : '[unset / invalide]') : 'ABSENT')?></code></td>
<td><code><?=ca_h(array_key_exists($key,$effective) ? ca_fmt($e) : 'ABSENT')?></code></td>
<td><?=ca_h($status)?></td><td><?=ca_h($action)?></td></tr>
<?php endforeach; ?></tbody></table>
<h2>Interpretation</h2>
<p><strong>Important :</strong> « suppression envisageable » n'est pas une autorisation de suppression automatique. Verifiez les fichiers d'initialisation, les chemins, la selection de base et une page publique + administration avant et apres modification. Un ecart entre valeur effective et base n'etablit pas, a lui seul, la priorite de siteconfig.php.</p>
<p>Les valeurs de la base sont decodees en lecture seule avec validation de la serialisation (PHP 5.6 compatible). Le script ne modifie rien, ne genere pas d'UPDATE SQL et n'affiche aucune valeur portant un nom de cle sensible (mot de passe, secret, token, etc.). Conservez-le uniquement pendant l'audit puis supprimez-le de public_html.</p>
</body></html>
