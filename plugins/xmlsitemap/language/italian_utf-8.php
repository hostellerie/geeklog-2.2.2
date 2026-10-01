<?php

/* Reminder: always indent with 4 spaces (no tabs). */

if (stripos($_SERVER['PHP_SELF'], basename(__FILE__)) !== false) {
    die('This file can not be used on its own.');
}

global $LANG32;

$LANG_XMLSMAP = array(
    'plugin' => 'XMLSitemap',
    'admin' => 'Amministrazione XMLSitemap',
    'description' => 'Normalmente tutti i file sitemap vengono aggiornati automaticamente quando un elemento viene aggiunto, modificato o eliminato. Se qualcosa non funziona, aggiorna manualmente i file sitemap con il pulsante qui sotto.',
    'filename' => 'Nome file',
    'updated' => 'Aggiornato',
    'not_saved' => 'Non salvato',
    'update_now' => 'Aggiorna ora tutti i file sitemap!',
    'update_success' => 'Tutti i file sitemap sono stati aggiornati correttamente.',
    'update_fail' => 'Impossibile aggiornare i file sitemap. Consulta "error.log" per i dettagli.',
);

$LANG_configsections['xmlsitemap'] = array('label' => 'XMLSitemap', 'title' => 'Configurazione XMLSitemap');

$LANG_confignames['xmlsitemap'] = array(
    'sitemap_file' => 'Nome file sitemap',
    'mobile_sitemap_file' => 'Nome file sitemap mobile',
    'include_homepage' => 'Homepage nella sitemap',
    'types' => 'Contenuto della sitemap',
    'lastmod' => 'Tipi di contenuto che includono l’elemento lastmod',
    'priorities' => 'Priorità',
    'frequencies' => 'Frequenza',
    'ping_google' => 'Invia ping a Google',
    'indexnow' => 'Abilita IndexNow',
    'indexnow_key' => 'Chiave IndexNow',
    'indexnow_key_location' => 'Posizione chiave IndexNow',
    'news_sitemap_file' => 'Nome file sitemap News',
    'news_sitemap_topics' => 'Includi articoli da questi argomenti',
    'news_sitemap_age' => 'Età massima degli articoli',
);

$LANG_configsubgroups['xmlsitemap'] = array('sg_main' => 'Impostazioni principali');

$LANG_tab['xmlsitemap'] = array(
    'tab_main' => 'Impostazioni principali XMLSitemap',
    'tab_pri' => 'Priorità',
    'tab_freq' => 'Frequenza di aggiornamento',
    'tab_ping' => 'Ping',
    'tab_news' => 'Sitemap News',
);

$LANG_fs['xmlsitemap'] = array(
    'fs_main' => 'Impostazioni principali XMLSitemap',
    'fs_pri' => 'Priorità (predefinita = 0,5, minima = 0,0, massima = 1,0)',
    'fs_freq' => 'Frequenza di aggiornamento',
    'fs_ping' => 'Invia ping in caso di modifica',
    'fs_news' => 'Impostazioni sitemap News',
);

$LANG_configselects['xmlsitemap'] = array(
    0 => array('Sì' => 1, 'No' => 0),
    1 => array('Sì' => TRUE, 'No' => FALSE),
    9 => array('Inoltra alla pagina' => 'item', 'Mostra elenco' => 'list', 'Mostra homepage' => 'home', 'Mostra amministrazione' => 'admin'),
    12 => array('Nessun accesso' => 0, 'Sola lettura' => 2, 'Lettura-scrittura' => 3),
    20 => array('sempre' => 'always', 'ogni ora' => 'hourly', 'giornalmente' => 'daily', 'settimanalmente' => 'weekly', 'mensilmente' => 'monthly', 'annualmente' => 'yearly', 'mai' => 'never', 'nascosto' => 'hidden'),
);
