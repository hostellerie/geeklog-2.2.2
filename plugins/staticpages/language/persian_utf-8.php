<?php

###############################################################################
# persian_utf-8.php
#
# This is the Persian language file for Geeklog staticpages plugin
# Special thanks to Mahdi Montazeri for his work on this project
#
# Copyright (C) 2018 geeklog.ir
# info AT mahdimontazeri DOT com
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
#               XX    - file id number
#               YY    - phrase id number
###############################################################################

$LANG_STATIC = array(
    'newpage' => 'صفحه جدید',
    'adminhome' => 'خانه مدیر',
    'staticpages' => 'صفحات ایستا',
    'staticpageeditor' => 'ویرایشگر صفحه ایستا',
    'writtenby' => 'نوشته‌شده توسط',
    'date' => 'آخرین به‌روزرسانی',
    'title' => 'عنوان',
    'page_title' => 'عنوان صفحه',
    'content' => 'محتوا',
    'hits' => 'بازدیدها',
    'staticpagelist' => 'فهرست صفحات ایستا',
    'url' => 'URL',
    'edit' => 'ویرایش',
    'lastupdated' => 'آخرین به‌روزرسانی',
    'pageformat' => 'قالب صفحه',
    'leftrightblocks' => 'بلوک‌های چپ و راست',
    'blankpage' => 'صفحه خالی',
    'noblocks' => 'بدون بلوک',
    'leftblocks' => 'بلوک‌های چپ',
    'addtomenu' => 'افزودن به منو',
    'label' => 'برچسب',
    'nopages' => 'هنوز هیچ صفحه ایستایی در سیستم وجود ندارد',
    'save' => 'ذخیره',
    'preview' => 'پیش‌نمایش',
    'delete' => 'حذف',
    'cancel' => 'لغو',
    'access_denied' => 'دسترسی ممنوع است',
    'access_denied_msg' => 'شما به‌صورت غیرمجاز در تلاش برای دسترسی به یکی از صفحات مدیریت صفحات ایستا هستید. توجه داشته باشید که همه تلاش‌های دسترسی غیرمجاز ثبت می‌شوند.',
    'all_html_allowed' => 'استفاده از همه HTML مجاز است',
    'results' => 'Static Pages Results',
    'author' => 'نویسنده',
    'no_title_or_content' => 'حداقل باید فیلدهای <b>عنوان</b> و <b>محتوا</b> را تکمیل کرده و یک <b>موضوع</b> را انتخاب کنید.',
    'title_error_saving' => 'خطا هنگام ذخیره صفحه',
    'template_xml_error' => 'در نشانه‌گذاری XML شما <em>خطا</em> وجود دارد. این صفحه برای استفاده از صفحه‌ای دیگر به‌عنوان الگو تنظیم شده است، بنابراین متغیرهای الگو باید با نشانه‌گذاری XML تعریف شوند. برای اطلاعات بیشتر به <a href="http://wiki.geeklog.net/Static_Pages_Plugin#Template_Static_Pages" target="_blank">ویکی Geeklog</a> مراجعه کنید. این خطا باید پیش از ذخیره صفحه اصلاح شود.',
    'no_such_page_anon' => 'لطفاً وارد شوید.',
    'no_page_access_msg' => 'ممکن است وارد سایت نشده باشید یا عضو {$_CONF[\'site_name\']} نباشید. برای دسترسی کامل، لطفاً <a href="{$_CONF[\'site_url\']}/users.php?mode=new">عضو {$_CONF[\'site_name\']} شوید</a>'{$_CONF['site_url']}/users.php?mode=new\"> become a member</a> of {$_CONF['site_name']} to receive full membership access",
    'php_msg' => 'PHP: ',
    'php_warn' => 'هشدار: اگر این گزینه را فعال کنید، کد PHP موجود در صفحه اجرا خواهد شد. با احتیاط استفاده کنید!',
    'exit_msg' => 'نوع خروج: ',
    'exit_info' => 'برای نمایش پیام «ورود الزامی است» فعال کنید. برای بررسی امنیتی و پیام معمولی، آن را غیرفعال بگذارید.',
    'deny_msg' => 'دسترسی به این صفحه مجاز نیست. ممکن است صفحه جابه‌جا یا حذف شده باشد، یا مجوز کافی برای مشاهده آن نداشته باشید.',
    'stats_headline' => 'Top Ten Static Pages',
    'stats_page_title' => 'عنوان صفحه',
    'stats_hits' => 'بازدیدها',
    'stats_no_hits' => 'به نظر می‌رسد در این سایت صفحه ایستایی وجود ندارد یا هنوز کسی آن‌ها را مشاهده نکرده است.',
    'id' => 'شناسه',
    'duplicate_id' => 'شناسه‌ای که برای این صفحه ایستا انتخاب کرده‌اید قبلاً استفاده شده است. لطفاً شناسه دیگری انتخاب کنید.',
    'instructions' => 'To modify or delete a static page, click on that page\'s edit icon below. To view a static page, click on the title of the page you wish to view. To create a new static page, click on "Create New" above. Click on on the copy icon to create a copy of an existing page.',
    'centerblock' => 'بلوک مرکزی: ',
    'centerblock_msg' => 'در صورت انتخاب، این صفحه ایستا به‌صورت بلوک مرکزی در صفحه اصلی موضوع‌های اختصاص‌یافته نمایش داده می‌شود.',
    'topic' => 'موضوع',
    'position' => 'موقعیت: ',
    'all_topics' => 'همه',
    'no_topic' => 'فقط صفحه اصلی',
    'position_top' => 'بالای صفحه',
    'position_feat' => 'پس از مطلب ویژه',
    'position_bottom' => 'پایین صفحه',
    'position_entire' => 'کل صفحه',
    'head_centerblock' => 'بلوک مرکزی',
    'centerblock_no' => 'خیر',
    'centerblock_top' => 'بالا',
    'centerblock_feat' => 'مطلب ویژه',
    'centerblock_bottom' => 'پایین',
    'centerblock_entire' => 'کل صفحه',
    'inblock_msg' => 'در یک بلوک: ',
    'inblock_info' => 'صفحه ایستا را داخل یک بلوک نمایش دهید.',
    'title_edit' => 'ویرایش صفحه',
    'title_copy' => 'ایجاد یک نسخه از این صفحه',
    'title_display' => 'نمایش صفحه',
    'select_php_none' => 'PHP اجرا نشود',
    'select_php_return' => 'اجرای PHP (return)',
    'select_php_free' => 'اجرای PHP',
    'php_not_activated' => "The use of PHP in static pages is not activated. Please see the <a href=\"{$_CONF['site_url']}/docs/english/staticpages.html#php\">documentation</a> for details.",
    'printable_format' => 'قالب مناسب چاپ',
    'copy' => 'نسخه',
    'limit_results' => 'محدود کردن نتایج',
    'search' => 'جستجو',
    'likes' => 'پسندها',
    'submit' => 'ارسال',
    'no_new_pages' => 'صفحه جدیدی وجود ندارد',
    'pages' => 'صفحات',
    'comments' => 'نظرات',
    'template' => 'الگو',
    'use_template' => 'استفاده از الگو',
    'template_msg' => 'در صورت انتخاب، این صفحه ایستا به‌عنوان الگو علامت‌گذاری می‌شود.',
    'none' => 'هیچ‌کدام',
    'use_template_msg' => 'اگر این صفحه ایستا الگو نیست، می‌توانید آن را به یک الگو اختصاص دهید. در صورت انتخاب الگو، محتوای صفحه باید قالب صحیح XML را رعایت کند. برای اطلاعات بیشتر به <a href="http://wiki.geeklog.net/Static_Pages_Plugin#Template_Static_Pages" target="_blank">ویکی Geeklog</a> مراجعه کنید.',
    'draft' => 'پیش‌نویس',
    'draft_yes' => 'بله',
    'draft_no' => 'خیر',
    'show_on_page' => 'نمایش در صفحه',
    'show_on_page_disabled' => 'توجه: این گزینه در حال حاضر برای همه صفحات در تنظیمات صفحات ایستا غیرفعال است.',
    'cache_time' => 'زمان حافظه نهان',
    'cache_time_desc' => 'محتوای این صفحه ایستا حداکثر به مدت این تعداد ثانیه در حافظه نهان نگهداری می‌شود. مقدار 0 حافظه نهان را غیرفعال می‌کند و مقدار -1 تا زمان ویرایش دوباره صفحه آن را نگه می‌دارد. صفحات دارای PHP یا صفحات الگو در حافظه نهان قرار نمی‌گیرند. (3600 = یک ساعت، 86400 = یک روز)',
    'autotag_desc_staticpage' => '[staticpage: id عنوان جایگزین] - پیوندی به یک صفحه ایستا با استفاده از عنوان صفحه نمایش می‌دهد. عنوان جایگزین اختیاری است.',
    'autotag_desc_staticpage_content' => '[staticpage_content: id] - محتوای یک صفحه ایستا را نمایش می‌دهد.',
    'autotag_desc_page' => '[page: id عنوان جایگزین] - پیوندی به یک صفحه از افزونه صفحات ایستا با استفاده از عنوان صفحه نمایش می‌دهد. عنوان جایگزین اختیاری است.',
    'autotag_desc_page_content' => '[page_content: id] - محتوای یک صفحه از افزونه صفحات ایستا را نمایش می‌دهد.',
    'yes' => 'بله',
    'used_by' => 'این الگو به %s صفحه اختصاص داده شده است. اگر این الگو از طریق autotag در الگویی دیگر فراخوانی شود، ممکن است صفحات بیشتری از آن استفاده کنند.',
    'prev_page' => 'صفحه قبلی',
    'next_page' => 'صفحه بعدی',
    'parent_page' => 'صفحه والد',
    'page_desc' => 'Setting a previous and/or next page will add HTML link elements rel=”next” and rel=”prev” to the header to indicate the relationship between pages in a paginated series. Actual page navigation links are not added to the page. You have to add these yourself. NOTE: Parent page is currently not being used.',
    'num_pages' => '%s صفحه',
    'search_desc' => 'Control if page appears in search. Default depends on setting in Configuration and depends on page type (if it is a Center Block, Uses a Template, or Uses PHP).',
    'likes_desc' => 'تعیین می‌کند کنترل پسندیدن در صفحه نمایش داده شود یا نه و به چه صورت. مقدار پیش‌فرض به تنظیمات افزونه بستگی دارد. صفحاتی که به‌صورت بلوک مرکزی نمایش داده می‌شوند این کنترل را نشان نمی‌دهند. صفحات الگو از این تنظیم استفاده نمی‌کنند.'
);

$LANG_staticpages_search = array(
    0  => 'حذف‌شده',
    1  => 'استفاده از پیش‌فرض',
    2  => 'شامل‌شده'
);

$LANG_staticpages_likes = array(
    -1 => 'استفاده از پیش‌فرض',
    0  => 'غیرفعال',
    1  => 'پسندیدن و نپسندیدن',
    2  => 'فقط پسندیدن',
);

$PLG_staticpages_MESSAGE15 = 'Your comment has been submitted for review and will be published when approved by a moderator.';
$PLG_staticpages_MESSAGE19 = 'Your page has been successfully saved.';
$PLG_staticpages_MESSAGE20 = 'Your page has been successfully deleted.';
$PLG_staticpages_MESSAGE21 = 'This page does not exist yet. To create the page, please fill in the form below. If you are here by mistake, click the Cancel button.';
$PLG_staticpages_MESSAGE22 = 'You could not delete the page. It is a template staticpage and it is currently assigned to 1 or more staticpages.';

// Messages for the plugin upgrade
$PLG_staticpages_MESSAGE3001 = 'Plugin upgrade not supported.';
$PLG_staticpages_MESSAGE3002 = $LANG32[9];

// Localization of the Admin Configuration UI
$LANG_configsections['staticpages'] = array(
    'label' => 'Static Pages',
    'title' => 'Static Pages Configuration'
);

$LANG_confignames['staticpages'] = array(
    'allow_php' => 'Allow PHP?',
    'enable_eval_php_save' => 'Parse PHP on Save of Page',
    'sort_by' => 'Sort Centerblocks by',
    'sort_menu_by' => 'Sort Menu Entries by',
    'sort_list_by' => 'Sort Admin List by',
    'delete_pages' => 'Delete Pages with Owner?',
    'in_block' => 'Wrap Pages in Block?',
    'show_hits' => 'Show Hits?',
    'show_date' => 'Show Date?',
    'filter_html' => 'Filter HTML?',
    'censor' => 'Censor Content?',
    'default_permissions' => 'Page Default Permissions',
    'autotag_permissions_staticpage' => '[staticpage: ] Permissions',
    'autotag_permissions_staticpage_content' => '[staticpage_content: ] Permissions',
    'aftersave' => 'After Saving Page',
    'atom_max_items' => 'Max. Pages in Webservices Feed',
    'meta_tags' => 'Enable Meta Tags',
    'likes_pages' => 'Page Likes',
    'comment_code' => 'پیشفرض نظر',
    'structured_data_type_default' => 'Structured Data Type Default',
    'draft_flag' => 'پیشفرض پرچم پیش نویس',
    'disable_breadcrumbs_staticpages' => 'Disable Breadcrumbs',
    'default_cache_time' => 'Default Cache Time',
    'newstaticpagesinterval' => 'New Static Page Interval',
    'hidenewstaticpages' => 'New Static Pages',
    'title_trim_length' => 'عنوان کوتاه کردن طول',
    'includecenterblocks' => 'Include Center Block Static Pages',
    'includephp' => 'Include Static Pages with PHP',
    'includesearch' => 'Enable Static Pages in Search',
    'includesearchcenterblocks' => 'Include Center Block Static Pages',
    'includesearchphp' => 'Include Static Pages with PHP',
    'includesearchtemplate' => 'Include Template Static Pages'
);

$LANG_configsubgroups['staticpages'] = array(
    'sg_main' => 'تنظیمات اصلی'
);

$LANG_tab['staticpages'] = array(
    'tab_main' => 'Static Pages Main Settings',
    'tab_whatsnew' => 'بلوک موارد جدید',
    'tab_search' => 'Search Results',
    'tab_permissions' => 'Default Permissions',
    'tab_autotag_permissions' => 'مجوز های استفاده از برچسب خودکار'
);

$LANG_fs['staticpages'] = array(
    'fs_main' => 'Static Pages Main Settings',
    'fs_whatsnew' => 'بلوک موارد جدید',
    'fs_search' => 'Search Results',
    'fs_permissions' => 'Default Permissions',
    'fs_autotag_permissions' => 'مجوز های استفاده از برچسب خودکار'
);

// Note: entries 0, 1, 9, 12, 17 are the same as in $LANG_configselects['Core']
$LANG_configselects['staticpages'] = array(
    0 => array('True' => 1, 'False' => 0),
    1 => array('True' => true, 'False' => false),
    2 => array('Date' => 'date', 'Page ID' => 'id', 'Title' => 'title'),
    3 => array('Date' => 'date', 'Page ID' => 'id', 'Title' => 'title', 'Label' => 'label'),
    4 => array('Date' => 'date', 'Page ID' => 'id', 'Title' => 'title', 'Author' => 'author'),
    5 => array('Hide' => 'hide', 'Show - Use Modified Date' => 'modified', 'Show - Use Created Date' => 'created'),
    9 => array('Forward to page' => 'item', 'Display List' => 'فهرست', 'Display Home' => 'home', 'Display Admin' => 'admin'),
    12 => array('No access' => 0, 'Read-Only' => 2, 'Read-Write' => 3),
    13 => array('No access' => 0, 'Use' => 2),
    17 => array('Comments Enabled' => 0, 'Comments Disabled' => -1),
    39 => array('None' => '', 'WebPage' => 'core-webpage', 'Article' => 'core-article', 'NewsArticle' => 'core-newsarticle', 'BlogPosting' => 'core-blogposting'),
    41 => array('False' => 0, 'Likes and Dislikes' => 1, 'Likes Only' => 2)
);
