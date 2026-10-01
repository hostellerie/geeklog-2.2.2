<?php

/* Reminder: always indent with 4 spaces (no tabs). */

if (stripos($_SERVER['PHP_SELF'], basename(__FILE__)) !== false) {
    die('This file can not be used on its own.');
}

global $LANG32;

$LANG_XMLSMAP = array(
    'plugin' => 'XMLSitemap',
    'admin' => 'Администрирование XMLSitemap',
    'description' => 'Обычно все файлы sitemap автоматически обновляются при добавлении, изменении или удалении элемента. Если возникла проблема, обновите файлы sitemap вручную кнопкой ниже.',
    'filename' => 'Имя файла',
    'updated' => 'Обновлено',
    'not_saved' => 'Не сохранено',
    'update_now' => 'Обновить все файлы sitemap сейчас!',
    'update_success' => 'Все файлы sitemap успешно обновлены.',
    'update_fail' => 'Не удалось обновить файлы sitemap. Подробности смотрите в "error.log".',
);

$LANG_configsections['xmlsitemap'] = array('label' => 'XMLSitemap', 'title' => 'Настройка XMLSitemap');

$LANG_confignames['xmlsitemap'] = array(
    'sitemap_file' => 'Имя файла sitemap',
    'mobile_sitemap_file' => 'Имя файла мобильной sitemap',
    'include_homepage' => 'Главная страница в sitemap',
    'types' => 'Содержимое sitemap',
    'lastmod' => 'Типы содержимого с элементом lastmod',
    'priorities' => 'Приоритет',
    'frequencies' => 'Частота',
    'ping_google' => 'Отправлять ping в Google',
    'indexnow' => 'Включить IndexNow',
    'indexnow_key' => 'Ключ IndexNow',
    'indexnow_key_location' => 'Расположение ключа IndexNow',
    'news_sitemap_file' => 'Имя файла новостной sitemap',
    'news_sitemap_topics' => 'Включать статьи из этих тем',
    'news_sitemap_age' => 'Максимальный возраст статей',
);

$LANG_configsubgroups['xmlsitemap'] = array('sg_main' => 'Основные настройки');

$LANG_tab['xmlsitemap'] = array(
    'tab_main' => 'Основные настройки XMLSitemap',
    'tab_pri' => 'Приоритет',
    'tab_freq' => 'Частота обновления',
    'tab_ping' => 'Ping',
    'tab_news' => 'Новостная sitemap',
);

$LANG_fs['xmlsitemap'] = array(
    'fs_main' => 'Основные настройки XMLSitemap',
    'fs_pri' => 'Приоритет (по умолчанию = 0,5, минимум = 0,0, максимум = 1,0)',
    'fs_freq' => 'Частота обновления',
    'fs_ping' => 'Отправлять ping при изменении',
    'fs_news' => 'Настройки новостной sitemap',
);

$LANG_configselects['xmlsitemap'] = array(
    0 => array('Да' => 1, 'Нет' => 0),
    1 => array('Да' => TRUE, 'Нет' => FALSE),
    9 => array('Перейти на страницу' => 'item', 'Показать список' => 'list', 'Показать главную' => 'home', 'Показать администрирование' => 'admin'),
    12 => array('Нет доступа' => 0, 'Только чтение' => 2, 'Чтение и запись' => 3),
    20 => array('всегда' => 'always', 'ежечасно' => 'hourly', 'ежедневно' => 'daily', 'еженедельно' => 'weekly', 'ежемесячно' => 'monthly', 'ежегодно' => 'yearly', 'никогда' => 'never', 'скрыто' => 'hidden'),
);
