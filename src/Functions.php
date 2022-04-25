<?php

/*
 * rah_bitly - Bitly integration for Textpattern CMS
 * https://github.com/gocom/rah_bitly
 *
 * Copyright (C) 2022 Jukka Svahn
 *
 * This file is part of rah_bitly.
 *
 * rah_bitly is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation, version 2.
 *
 * rah_bitly is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with rah_bitly. If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Lists all available custom fields.
 *
 * @param  string $name Preference field's name
 * @param  string $value  Current value
 * @return string HTML select field
 */
function rah_bitly_fields($name, $value)
{
    return selectInput($name, getCustomFields(), $value, true, '', $name);
}
