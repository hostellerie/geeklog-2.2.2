<?php

/**
 * External themes/components whose language coverage is tracked by the
 * central Geeklog language checker.
 */
return [
    'eclipse' => [
        'name' => 'Eclipse',
        'repository' => 'hostellerie/eclipse',
        'branch' => 'main',
        'language_path' => 'eclipse/language',
        'reference' => 'english.php',
        'file_map' => [
            'french_canada_utf-8.php' => 'french.php',
            'french_france_utf-8.php' => 'french.php',
            'german_formal_utf-8.php' => 'german_formal.php',
            'german_utf-8.php' => 'german.php',
            'hebrew_utf-8.php' => 'hebrew.php',
            'italian_utf-8.php' => 'italian.php',
            'japanese_utf-8.php' => 'japanese.php',
            'persian_utf-8.php' => 'persian.php',
            'russian_utf-8.php' => 'russian.php',
            'spanish_argentina_utf-8.php' => 'spanish_argentina.php',
            'spanish_utf-8.php' => 'spanish.php',
            'chinese_simplified_utf-8.php' => 'chinese_simplified.php',
            'chinese_traditional_utf-8.php' => 'chinese_traditional.php',
        ],
    ],
];
