<?php

###############################################################################
# italian_utf-8.php
# This is the english language file for the Geeklog Static Page plugin
#
# Copyright (C) 2001 Tony Bibbs
# tony@tonybibbs.com
#
# This program is free software; you can redistribute it and/or
# modify it under the terms of the GNU General Public License
# as published by the Free Software Foundation; either version 2
# of the License, or (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with this program; if not, write to the Free Software
# Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
#
###############################################################################

global $LANG32;

###############################################################################
# Array Format:
# $LANGXX[YY]:  $LANG - variable name
#           XX - file id number
#           YY - phrase id number
###############################################################################

$LANG_STATIC = array(
    'newpage' => 'Nuova Pagina',
    'adminhome' => 'Home Amministrazione',
    'staticpages' => 'Pagine Statiche',
    'staticpageeditor' => 'Editor Pagine Statiche',
    'writtenby' => 'Scritto da',
    'date' => 'Ultimo Agg.',
    'title' => 'Configurazione delle Pagine Statiche',
    'page_title' => 'Titolo della pagina',
    'content' => 'Contenuto',
    'hits' => 'Visite',
    'staticpagelist' => 'Lista Pagine Statiche',
    'url' => 'URL',
    'edit' => 'Modifica',
    'lastupdated' => 'Ultimo Agg.',
    'pageformat' => 'Formato Pag.',
    'leftrightblocks' => 'Blocchi a Sinistra e Destra',
    'blankpage' => 'Pagina Vuota',
    'noblocks' => 'Nessun Blocco',
    'leftblocks' => 'Blocchi a Sinistra',
    'addtomenu' => 'Agg. al Menu',
    'label' => 'Pagine Statiche',
    'nopages' => 'Nessuna pagina statica al momento nel sistema',
    'Salva' => 'save',
    'Anteprima' => 'preview',
    'Cancella' => 'delete',
    'Elimina' => 'cancel',
    'access_denied' => 'Accesso Negato',
    'access_denied_msg' => 'Stai tentando di accedere illegalmente all\'Amministrazione Pagine Statiche.  Prego nota che tutti i tentativi di accesso illegali sono registrati',
    'all_html_allowed' => 'Tutto l\'HTML permesso',
    'results' => 'Risultati Pagine Statiche',
    'author' => 'Autore',
    'no_title_or_content' => 'Devi almeno compilare i campi <b>Titolo</b> e <b>Contenuto</b>.',
    'title_error_saving' => 'Error Saving Page',
    'template_xml_error' => 'You have an <em>error in your XML markup</em>. This page is set to use another page as a template and therefore requires template variables to be defined using XML markup. Please see our <a href="http://wiki.geeklog.net/Static_Pages_Plugin#Template_Static_Pages" target="_blank">Geeklog Wiki</a> for more information on how to do this as it must be corrected before the page can be saved.',
    'no_such_page_anon' => 'Prego entra nel sito..',
    'no_page_access_msg' => "This could be because you're not logged in, or not a member of {$_CONF['site_name']}. Please <a href=\"{$_CONF['site_url']}/users.php?mode=new\"> become a member</a> of {$_CONF['site_name']} to receive full membership access",
    'php_msg' => 'PHP: ',
    'php_warn' => 'Attenzione: il codice PHP della tua pagina sará valutato se abiliti questa opzione. <br',
    'exit_msg' => 'Tipo Uscita: ',
    'exit_info' => 'Abilita per Richiesta Messaggio di Login.  Lascia deselezionato per il normale controllo di sicurezza e messaggio.',
    'deny_msg' => 'Accesso a questa pagina negato.  Puó essere che questa pagina é stata spostata/rimossa o che non hai i permessi sufficiente per visulaizzarla.',
    'stats_headline' => 'Top 10 Pagine Statiche',
    'stats_page_title' => 'Titolo Pagina',
    'stats_hits' => 'Visite',
    'stats_no_hits' => 'Sembra che non ci siano pagine statiche in questo sito o che nessuno ne ha ancora visualizzata una.',
    'id' => 'ID',
    'duplicate_id' => 'L\'ID che hai selezionato per questa pagina statica é giá in uso. Prego seleziona un\'altro ID.',
    'instructions' => 'Per modificare o eliminare una pagina statica, clicca sul numero della pagina sotto. Per visualizzare una pagina statica, clicca sul titolo della pagina che desideri vedere. Per creare una nuova pagina statica clicca su [ Nuova Pagina ] sopra. Clicca su [C] per creare una copia di una pagina esistente.',
    'centerblock' => 'BloccoCentrale: ',
    'centerblock_msg' => 'Quando selezionato, questa Pagina Statica sará visualizzata come un blocco centrale nella pagina index.',
    'topic' => 'Argomento: ',
    'position' => 'Posizione: ',
    'all_topics' => 'Tutte',
    'no_topic' => 'Solo Homepage',
    'position_top' => 'Capo Pagina',
    'position_feat' => 'Dopo Articolo Evidenziato',
    'position_bottom' => 'Fine Pagina',
    'position_entire' => 'Pagina Intera',
    'head_centerblock' => 'BloccoCentrale',
    'centerblock_no' => 'No',
    'centerblock_top' => 'Capo Pag.',
    'centerblock_feat' => 'Art. Evidenz.',
    'centerblock_bottom' => 'Fine Pag.',
    'centerblock_entire' => 'Intera Pagina',
    'inblock_msg' => 'In un blocco: ',
    'inblock_info' => 'Racchiudi Pagina Statica in un blocco.',
    'title_edit' => 'Modifica Pagina',
    'title_copy' => 'Duplica questa pagina',
    'title_display' => 'Visualizza pagina',
    'select_php_none' => 'non eseguire PHP',
    'select_php_return' => 'esegui PHP (return)',
    'select_php_free' => 'esegui PHP',
    'php_not_activated' => 'The use of PHP in static pages is not activated. Please see the <a href="' . $_CONF['site_url'] . '/docs/english/staticpages.html#php">documentation</a> for details.',
    'printable_format' => 'Formato Stampabile',
    'copy' => 'Copia',
    'limit_results' => 'Limita i Risultati della ricerca',
    'search' => 'Ricerca',
	'likes' => 'Mi piace',
    'submit' => 'Invia',
    'no_new_pages' => 'Nessuna nuova pagina',
    'pages' => 'Pagine',
    'comments' => 'Commenti',
    'template' => 'Template',
    'use_template' => 'Use Template',
    'template_msg' => 'When checked, this Static Page will be marked as a template.',
    'none' => 'Nessuno',
    'use_template_msg' => 'If this Static Page is not a template, you can assign it to use a template. If a selection is made then remember that the content of this page must follow the proper XML format.',    'draft' => 'Bozza',
    'draft_yes' => 'Si',
    'draft_no' => 'No',
    'show_on_page' => 'Show on Page',
    'show_on_page_disabled' => 'Note: This is currently disabled for all pages in the Staticpage Configuration.',
    'cache_time'        => 'Durata cache',
    'cache_time_desc'   => 'This staticpage content will be cached for no longer than this many seconds. If 0 caching is disabled (3600 = 1 hour,  86400 = 1 day). Staticpages with PHP enabled or are a template will not be cached.',
    'autotag_desc_staticpage' => '[staticpage: id alternate title] - Displays a link to a static page using the static page title as the title. An alternate title may be specified but is not required.',
    'autotag_desc_staticpage_content' => '[staticpage_content: id alternate title] - Displays the contents of a staticpage.',
    'autotag_desc_page' => '[page: id alternate title] - Displays a link to a page (from the Static Page plugin) using the page title as the title. An alternate title may be specified but is not required.',
    'autotag_desc_page_content' => '[page_content: id] - Displays the contents of a page. (from Static Page plugin)',
    'yes' => 'Si',
    'used_by' => 'This template is assigned to %s page(s). It is possible this template is used more than specified here if the template is being retrieved via an autotag in another template.',
    'prev_page' => 'Previous page',
    'next_page' => 'Next page',
    'parent_page' => 'Parent page',
    'page_desc' => 'Setting a previous and/or next page will add HTML link elements rel=”next” and rel=”prev” to the header to indicate the relationship between pages in a paginated series. Actual page navigation links are not added to the page. You have to add these yourself. NOTE: Parent page is currently not being used.',
    'num_pages' => '%s Page(s)',
    'search_desc' => 'Control if page appears in search. Default depends on setting in Configuration and depends on page type (if it is a Center Block, Uses a Template, or Uses PHP).',
	'likes_desc' => 'Determines if and how likes control appears on page. Default depends on setting in Plugin Configuration. Pages displayed in a Center Blocks will not display a likes control. Pages that are a template do not use this setting.'      
);

$PLG_staticpages_MESSAGE15 = 'Your comment has been submitted for review and will be published when approved by a moderator.';
$PLG_staticpages_MESSAGE19 = 'Your page has been successfully saved.';
$PLG_staticpages_MESSAGE20 = 'Your page has been successfully deleted.';
$PLG_staticpages_MESSAGE21 = 'This page does not exist yet. To create the page, please fill in the form below. If you are here by mistake, click the Cancel button.';
$PLG_staticpages_MESSAGE22 = 'You could not delete the page. It is a template staticpage and it is currently assigned to 1 or more staticpages.';

// Messages for the plugin upgrade
$PLG_staticpages_MESSAGE3001 = 'Plugin upgrade not supported.';
$PLG_staticpages_MESSAGE3002 = $LANG32[9];

// Search options for pages
$LANG_staticpages_search = array(
    0  => 'Excluded',
    1  => 'Use Default',
    2  => 'Included'
);

// Likes options for pages 
// The same values for these options will match values for the config option "likes_pages"
$LANG_staticpages_likes = array(
	-1  => 'Use Default',
    0   => 'Disabled', 
    1   => 'Likes and Dislikes',
	2   => 'Likes Only',
);

// Localization of the Admin Configuration UI
$LANG_configsections['staticpages'] = array(
    'label' => 'Pagine Statiche',
    'title' => 'Configurazione delle Pagine Statiche'
);

$LANG_confignames['staticpages'] = array(
    'allow_php' => 'Permettere PHP?',
    'enable_eval_php_save' => 'Parse PHP on Save of Page',
    'sort_by' => 'Ordina i Centerblock secondo',
    'sort_menu_by' => 'Ordina gli Elementi del Menu secondo',
    'sort_list_by' => 'Ordina la Lista Ammin secondo',
    'delete_pages' => 'Delete Pages with Owner?',
    'in_block' => 'Wrap Pages in Block?',
    'show_hits' => 'Mostrare Visite?',
    'show_date' => 'Motrare Data?',
    'filter_html' => 'Filtrare HTML?',
    'censor' => 'Censurare il Contenuto?',
    'default_permissions' => 'Autorizzazioni predefinite per pagine',
    'autotag_permissions_staticpage' => '[staticpage: ] Permissions',
    'autotag_permissions_staticpage_content' => '[staticpage_content: ] Permissions',
    'aftersave' => 'Dopo Aver Salvato la Pagina',
    'atom_max_items' => 'Max. Pages in Webservices Feed',
    'meta_tags' => 'Abilita Meta Tags',
	'likes_pages' => 'Page Likes',
    'comment_code' => 'Comporatamento Predefinito per Commenti',
    'structured_data_type_default' => 'Structured Data Type Default',
    'draft_flag' => 'Draft Flag Default',
    'disable_breadcrumbs_staticpages' => 'Disable Breadcrumbs',
    'default_cache_time' => 'Default Cache Time',
    'newstaticpagesinterval' => 'Intervallo per Nuove Pagine Statiche',
    'hidenewstaticpages' => 'Nascondi Nuove Pagine Statiche',
    'title_trim_length' => 'Massima Lunghezza del Titolo',
    'includecenterblocks' => 'Include Center Block Static Pages',
    'includephp' => 'Includi Pagine Statiche con PHP',
    'includesearch' => 'Mostra Pagine Statiche Nei Risultati Di Ricerca',
    'includesearchcenterblocks' => 'Include Center Block Static Pages',
    'includesearchphp' => 'Mostra Pagine Statiche con PHP',
    'includesearchtemplate' => 'Include Template Static Pages'
);

$LANG_configsubgroups['staticpages'] = array(
    'sg_main' => 'Impostazioni Principali'
);

$LANG_tab['staticpages'] = array(
    'tab_main' => 'Static Pages Main Settings',
    'tab_whatsnew' => 'What\'s New Block',
    'tab_search' => 'Search Results',
    'tab_permissions' => 'Default Permissions',
    'tab_autotag_permissions' => 'Autotag Usage Permissions'
);

$LANG_fs['staticpages'] = array(
    'fs_main' => 'Impostazioni Principali per Pagine Statiche',
    'fs_whatsnew' => 'Blocco Per Novitá',
    'fs_search' => 'Risultati di Ricerca',
    'fs_permissions' => 'Autorizzazioni predefinite',
    'fs_autotag_permissions' => 'Autotag Usage Permissions'
);

// Note: entries 0, 1, 9, 12, 17, 39, 41 are the same as in $LANG_configselects['Core']
$LANG_configselects['staticpages'] = array(
    0 => array('True' => 1, 'False' => 0),
    1 => array('True' => TRUE, 'False' => FALSE),
    2 => array('Date' => 'date', 'Page ID' => 'id', 'Title' => 'title'),
    3 => array('Date' => 'date', 'Page ID' => 'id', 'Title' => 'title', 'Label' => 'label'),
    4 => array('Date' => 'date', 'Page ID' => 'id', 'Title' => 'title', 'Author' => 'author'),
    5 => array('Hide' => 'hide', 'Show - Use Modified Date' => 'modified', 'Show - Use Created Date' => 'created'),
    9 => array('Forward to page' => 'item', 'Display List' => 'lista', 'Display Home' => 'home', 'Display Admin' => 'admin'),
    12 => array('No access' => 0, 'Read-Only' => 2, 'Read-Write' => 3),
    13 => array('No access' => 0, 'Use' => 2),
    17 => array('Comments Enabled' => 0, 'Comments Disabled' => -1),
    39 => array('None' => '', 'WebPage' => 'core-webpage', 'Article' => 'core-article', 'NewsArticle' => 'core-newsarticle', 'BlogPosting' => 'core-blogposting'),
	41 => array('False' => 0, 'Likes and Dislikes' => 1, 'Likes Only' => 2)
);
