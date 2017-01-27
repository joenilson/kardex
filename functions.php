<?php

/*
 * Copyright (C) 2017 Joe Nilson <joenilson at gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
require_once 'plugins/kardex/vendor/php-i18n/i18n.class.php';
$i18n = "";
function i18n_kardex($lang){
    $language = ($lang and file_exists('plugins/kardex/lang/lang_'.$lang.'.ini'))?$lang:'es';
    $i18n = new i18n_kardex('plugins/kardex/lang/lang_'.$language.'.ini', 'plugins/kardex/langcache/');
    $i18n->setForcedLang($language);
    $i18n->init();
}
