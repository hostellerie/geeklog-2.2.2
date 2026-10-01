<?php

/* Reminder: always indent with 4 spaces (no tabs). */

if (stripos($_SERVER['PHP_SELF'], basename(__FILE__)) !== false) {
    die('This file can not be used on its own.');
}

global $LANG32;

$LANG_XMLSMAP = array(
    'plugin' => 'XMLSitemap',
    'admin' => 'Administration XMLSitemap',
    'description' => 'Normalement, tous les fichiers sitemap sont automatiquement mis à jour lorsqu’un élément est ajouté, modifié ou supprimé. En cas de problème, mettez les fichiers sitemap à jour manuellement à l’aide du bouton ci-dessous.',
    'filename' => 'Nom du fichier',
    'updated' => 'Mis à jour',
    'not_saved' => 'Non enregistré',
    'update_now' => 'Mettre à jour tous les fichiers sitemap maintenant !',
    'update_success' => 'Tous les fichiers sitemap ont été mis à jour avec succès.',
    'update_fail' => 'Échec de la mise à jour des fichiers sitemap. Consultez "error.log" pour plus de détails.',
);

$LANG_configsections['xmlsitemap'] = array('label' => 'XMLSitemap', 'title' => 'Configuration de XMLSitemap');

$LANG_confignames['xmlsitemap'] = array(
    'sitemap_file' => 'Nom du fichier sitemap',
    'mobile_sitemap_file' => 'Nom du fichier sitemap mobile',
    'include_homepage' => 'Page d’accueil dans le sitemap',
    'types' => 'Contenu du sitemap',
    'lastmod' => 'Types de contenu incluant l’élément lastmod',
    'priorities' => 'Priorité',
    'frequencies' => 'Fréquence',
    'ping_google' => 'Envoyer un ping à Google',
    'indexnow' => 'Activer IndexNow',
    'indexnow_key' => 'Clé IndexNow',
    'indexnow_key_location' => 'Emplacement de la clé IndexNow',
    'news_sitemap_file' => 'Nom du fichier sitemap Actualités',
    'news_sitemap_topics' => 'Inclure les articles de ces sujets',
    'news_sitemap_age' => 'Âge maximal des articles',
);

$LANG_configsubgroups['xmlsitemap'] = array('sg_main' => 'Paramètres principaux');

$LANG_tab['xmlsitemap'] = array(
    'tab_main' => 'Paramètres principaux de XMLSitemap',
    'tab_pri' => 'Priorité',
    'tab_freq' => 'Fréquence de mise à jour',
    'tab_ping' => 'Ping',
    'tab_news' => 'Sitemap Actualités',
);

$LANG_fs['xmlsitemap'] = array(
    'fs_main' => 'Paramètres principaux de XMLSitemap',
    'fs_pri' => 'Priorité (défaut = 0,5, minimum = 0,0, maximum = 1,0)',
    'fs_freq' => 'Fréquence de mise à jour',
    'fs_ping' => 'Envoyer un ping lors d’une modification',
    'fs_news' => 'Paramètres du sitemap Actualités',
);

$LANG_configselects['xmlsitemap'] = array(
    0 => array('Oui' => 1, 'Non' => 0),
    1 => array('Oui' => TRUE, 'Non' => FALSE),
    9 => array('Rediriger vers la page' => 'item', 'Afficher la liste' => 'list', 'Afficher l’accueil' => 'home', 'Afficher l’administration' => 'admin'),
    12 => array('Aucun accès' => 0, 'Lecture seule' => 2, 'Lecture-écriture' => 3),
    20 => array('toujours' => 'always', 'toutes les heures' => 'hourly', 'quotidien' => 'daily', 'hebdomadaire' => 'weekly', 'mensuel' => 'monthly', 'annuel' => 'yearly', 'jamais' => 'never', 'masqué' => 'hidden'),
);
