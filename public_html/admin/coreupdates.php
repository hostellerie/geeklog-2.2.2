<?php
/**
 * Experimental Core update status page (#1291).
 * Read-only; filesystem mutation remains restricted to the CLI laboratory.
 */
require_once '../lib-common.php';
require_once 'auth.inc.php';

if (!SEC_inGroup('Root') || !isset($_CONF['core_update_status']) || $_CONF['core_update_status'] !== true) {
    COM_accessLog('Unauthorized access to experimental Core update status');
    COM_output(COM_createHTMLDocument(COM_showMessageText($MESSAGE[29], $MESSAGE[30]),
        array('pagetitle' => $MESSAGE[30])));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}

$fr = strpos(COM_getLanguage(), 'french') === 0;
$title = $fr ? 'Mises à jour du noyau' : 'Core updates';
$schema = DB_getItem($_TABLES['vars'], 'value', "name = 'database_version'");
$schema = is_string($schema) && preg_match('/^[0-9]+\\.[0-9]+\\.[0-9]+(?:\\.[0-9]+)?$/D', $schema) === 1
    ? $schema : ($fr ? 'Indisponible' : 'Unavailable');
$labels = $fr ? array('Version du code', 'Version du schéma SQL', 'Version de PHP') :
    array('Code version', 'SQL schema version', 'PHP version');
$values = array(VERSION, $schema, PHP_VERSION);
$display = COM_startBlock($title, '', COM_getBlockTemplate('_admin_block', 'header'));
$display .= '<dl>';
foreach ($labels as $index => $label) {
    $display .= '<dt>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</dt><dd>' .
        htmlspecialchars($values[$index], ENT_QUOTES, 'UTF-8') . '</dd>';
}
$display .= '</dl><p>' . ($fr
    ? 'Aperçu expérimental en lecture seule. La vérification et l’installation des mises à jour ne sont pas encore activées.'
    : 'Experimental read-only preview. Update checking and installation are not yet enabled.') . '</p>';
$display .= COM_endBlock(COM_getBlockTemplate('_admin_block', 'footer'));
COM_output(COM_createHTMLDocument($display, array('pagetitle' => $title)));
