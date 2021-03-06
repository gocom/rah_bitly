<?php

/**
 * Rah_bitly plugin for Textpattern CMS.
 *
 * @author  Jukka Svahn
 * @license GNU GPLv2
 * @link    http://rahforum.biz/plugins/rah_bitly
 * 
 * Copyright (C) 2013 Jukka Svahn http://rahforum.biz
 * Licensed under GNU Genral Public License version 2
 * http://www.gnu.org/licenses/gpl-2.0.html
 */

class rah_bitly
{
	/**
	 * Stores instances.
	 *
	 * @var rah_bitly
	 */

	static public $instance = null;

	/**
	 * Article's current permlink.
	 *
	 * @var string
	 */

	public $permlink;

	/**
	 * Article's previous permlink.
	 *
	 * @var string
	 */

	public $prev_permlink;

	/**
	 * Article's previous status.
	 *
	 * @var int
	 */

	private $prev_status;
	
	/**
	 * Bitly login.
	 *
	 * @var string
	 */

	private $login;

	/**
	 * Bitly API key.
	 *
	 * @var string
	 */

	private $apikey;

	/**
	 * Custom field ID.
	 *
	 * @var int
	 */

	private $field;

	/**
	 * Installer.
	 */

	public function install()
	{
		$position = 250;

		foreach (
			array(
				'login'  => array('text_input', ''),
				'apikey' => array('text_input', ''),
				'field'  => array('rah_bitly_fields', ''),
			) as $name => $val
		) {
			$n = 'rah_bity_'.$name;

			if (get_pref($n, false) === false)
			{
				set_pref($n, $val[1], 'rah_bitly', PREF_ADVANCED, $val[0], $position);
			}

			$position++;
		}
	}

	/**
	 * Uninstaller.
	 */

	public function uninstall()
	{
		safe_delete(
			'txp_prefs',
			"name like 'rah\_bitly\_%'"
		);
	}

	/**
	 * Gets an instance.
	 *
	 * @return rah_bitly
	 */

	static public function get()
	{
		if (self::$instance === null)
		{
			self::$instance = new rah_bitly();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */

	public function __construct()
	{
		add_privs('plugin_prefs.rah_bitly', '1,2');
		add_privs('prefs.rah_bitly', '1,2');
		register_callback(array($this, 'prefs'), 'plugin_prefs.rah_bitly');
		register_callback(array($this, 'install'), 'plugin_lifecycle.rah_bitly', 'installed');
		register_callback(array($this, 'uninstall'), 'plugin_lifecycle.rah_bitly', 'deleted');
		register_callback(array($this, 'initialize'), 'article', '', 1);
	}

	/**
	 * Initializes the plugin.
	 */

	public function initialize()
	{	
		foreach (array('login', 'apikey', 'field') as $name)
		{
			$this->$name = get_pref('rah_bitly_'.$name);
		}

		$this->field = (int) $this->field;

		if (!$this->login || !$this->apikey || !$this->field)
		{
			return;
		}

		include_once txpath.'/publish/taghandlers.php';

		$this->previous_state();
		register_callback(array($this, 'update'), 'article_saved');
		register_callback(array($this, 'update'), 'article_posted');
	}

	/**
	 * Fetches old article data.
	 */

	public function previous_state()
	{	
		$id = (int) ps('ID');

		if (!$id)
		{
			return;
		}

		$this->prev_permlink = permlinkurl_id($id);
		$this->prev_status = fetch('Status', 'textpattern', 'ID', $id);
		unset($GLOBALS['permlinks'][$id]);
	}

	/**
	 * Hooks to article saving process and updates short URLs.
	 *
	 * @param string $event Callback event
	 * @param string $step  Callback step
	 * @param array  $r     Article data
	 */

	public function update($event, $step, $r)
	{	
		global $app_mode;

		$this->permlink = permlinkurl_id($r['ID']);

		callback_event('rah_bitly.update', '', false, $r);

		if (!$this->permlink || $r['Status'] < STATUS_LIVE)
		{
			return;
		}

		if (
			$this->prev_permlink !== $this->permlink ||
			empty($r['custom_'.$this->field]) ||
			$this->prev_status != $r['Status']
		) {
			$uri = $this->fetch($this->permlink);
		}

		if (empty($uri))
		{
			return;
		}

		$fields = getCustomFields();

		if (!isset($fields[$this->field]))
		{
			return;
		}

		safe_update(
			'textpattern',
			'custom_'.intval($this->field)."='".doSlash($uri)."'",
			"ID='".doSlash($r['ID'])."'"
		);

		$js = 
			'$(document).ready(function(){'.
				'$(\'input[name="custom_'.$this->field.'"]\').val("'.escape_js($uri).'");'.
			'});';

		if ($app_mode == 'async')
		{
			send_script_response($js);
		}
	}

	/**
	 * Fetches a Bitly short URL.
	 *
	 * @param  string $permlink The long URL to shorten
	 * @param  int    $timeout  Timeout in seconds
	 * @return string
	 */

	protected function fetch($permlink, $timeout = 10)
	{
		if (!$permlink || !function_exists('curl_init'))
		{
			return;
		}

		$uri = 
			'https://api-ssl.bitly.com/v3/shorten'.
				'?login='.urlencode($this->login).
				'&apiKey='.urlencode($this->apikey).
				'&longUrl='.urlencode($permlink).
				'&format=txt';

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $uri);
		curl_setopt($ch, CURLOPT_FAILONERROR, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
		curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
		$bitcode = curl_exec($ch);
		curl_close($ch);

		return $bitcode && strpos($bitcode, 'http') === 0 ? txpspecialchars(trim($bitcode)) : '';
	}

	/**
	 * Redirect to the admin-side interface.
	 */

	public function prefs()
	{
		header('Location: ?event=prefs&step=advanced_prefs#prefs-rah_bitly_login');
		echo 
			'<p>'.n.
			'	<a href="?event=prefs&amp;step=advanced_prefs#prefs-rah_bitly_login">'.gTxt('continue').'</a>'.n.
			'</p>';
	}
}

rah_bitly::get();

/**
 * Lists all available custom fields.
 *
 * @param  string $name Preference field's name
 * @param  string $val  Current value
 * @return string HTML select field
 */

	function rah_bitly_fields($name, $val)
	{
		return selectInput($name, getCustomFields(), $val, true, '', $name);
	}