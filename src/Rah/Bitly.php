<?php

/*
 * rah_function - Every PHP function is a Textpattern CMS tag
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
 * The plugin class.
 */
final class Rah_Bitly
{
    /**
     * Article's previous permlink.
     *
     * @var string
     */
    private $previousPermlink;

    /**
     * Article's previous status.
     *
     * @var int
     */
    private $previousStatus;

    /**
     * Constructor.
     */
    public function __construct()
    {
        add_privs('plugin_prefs.rah_bitly', '1,2');
        add_privs('prefs.rah_bitly', '1,2');

        register_callback([$this, 'prefs'], 'plugin_prefs.rah_bitly');
        register_callback([$this, 'install'], 'plugin_lifecycle.rah_bitly', 'installed');
        register_callback([$this, 'uninstall'], 'plugin_lifecycle.rah_bitly', 'deleted');
        register_callback([$this, 'initializeArticleHooks'], 'article', '', 1);
    }

    /**
     * Installer.
     */
    public function install()
    {
        $position = 250;

        $options = [
            'rah_bitly_access_token'  => ['text_input', ''],
            'rah_bitly_field'  => ['rah_bitly_fields', ''],
        ];

        foreach ($options as $name => $val) {
            create_pref($name, $val[1], 'rah_bitly', PREF_PLUGIN, $val[0], $position++);
        }

        // Remove old incorrectly named preferences.
        safe_delete(
            'txp_prefs',
            "name like 'rah\_bity\_%'"
        );
    }

    /**
     * Uninstaller.
     */
    public function uninstall()
    {
        remove_pref(null, 'rah_bitly');
    }

    /**
     * Initializes article hooks.
     */
    public function initializeArticleHooks()
    {
        if ($this->getAccessToken()) {
            include_once txpath.'/publish/taghandlers.php';

            $this->loadPreviousArticleState();

            register_callback([$this, 'processArticleUpdate'], 'article_saved');
            register_callback([$this, 'processArticleUpdate'], 'article_posted');
        }
    }

    /**
     * Hooks to article saving process and updates short URLs.
     *
     * @param string $event Callback event
     * @param string $step Callback step
     * @param array  $r Article data
     */
    public function processArticleUpdate($event, $step, $r)
    {
        global $app_mode;

        $permlink = permlinkurl_id($r['ID']);

        if (!$permlink || $r['Status'] < STATUS_LIVE) {
            return;
        }

        if (
            $this->previousPermlink !== $permlink ||
            empty($r['custom_'.$this->getField()]) ||
            $this->previousStatus != $r['Status']
        ) {
            try {
                $shortlink = $this->getShortLink($permlink);
            } catch (Exception $e) {
                if ($app_mode == 'async') {
                    send_script_response(announce($e->getMessage(), E_ERROR));
                }
            }
        }

        if (empty($shortlink)) {
            return;
        }

        $fields = getCustomFields();
        $field = $this->getField();

        if (!isset($fields[$field])) {
            return;
        }

        $shortlink = txpspecialchars($shortlink);

        safe_update(
            'textpattern',
            'custom_'.intval($field)."='".doSlash($shortlink)."'",
            "ID='".doSlash($r['ID'])."'"
        );

        if ($app_mode == 'async') {
            $value = escape_js($shortlink);

            $js = <<<EOF
$(document).ready(function() {
    $('input[name="custom_$field"]').val('$value');
});
EOF;

            send_script_response($js);
        }
    }

    /**
     * The plugin's options page.
     *
     * Redirects to preferences.
     */
    public function prefs()
    {
        header('Location: ?event=prefs#prefs_group_rah_bitly');
        echo graf(href(gTxt('continue'), ['href' => '?event=prefs#prefs_group_rah_bitly']));
    }

    /**
     * Gets access token.
     *
     * @return string
     */
    private function getAccessToken(): string
    {
        return get_pref('rah_bitly_access_token');
    }

    /**
     * Gets article storage field name.
     *
     * @return int
     */
    private function getField(): int
    {
        return (int) get_pref('rah_bitly_field');
    }

    /**
     * Fetches a Bitly short link.
     *
     * @param string $permlink The long URL to shorten
     * @param int $timeout Timeout in seconds
     *
     * @return string|null
     *
     * @throws Exception
     */
    private function getShortLink(string $permlink, int $timeout = 10): ?string
    {
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->getAccessToken(),
        ];

        $data = [
            'long_url' => $permlink,
        ];

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, 'https://api-ssl.bitly.com/v4/shorten');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_FAILONERROR, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

        $body = curl_exec($ch);

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        $data = $body
            ? json_decode($body, true)
            : [];

        if (!is_array($data)) {
            $data = [];
        }

        if ($httpCode >= 400) {
            throw new Exception(
                gTxt('rah_bitly_error_response', [
                    'code' => $httpCode,
                    'message' => $data['message'] ?? null,
                    'description' => $data['description'] ?? null,
                ])
            );
        }

        if ($body) {
            return $data['link'] ?? null;
        }

        return null;
    }

    /**
     * Loads previous article state.
     */
    private function loadPreviousArticleState(): void
    {
        $id = (int) ps('ID');

        if ($id) {
            $this->previousPermlink = permlinkurl_id($id);
            $this->previousStatus = (int) fetch('Status', 'textpattern', 'ID', $id);
            $this->cleanPermlinkCache($id);
        }
    }

    /**
     * Clean permlink cache for the given article.
     *
     * @param int $id
     */
    private function cleanPermlinkCache(int $id): void
    {
        global $permlinks;

        unset($permlinks[$id]);
    }
}
