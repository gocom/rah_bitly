<?php

/**
 * Rah_bitly plugin for Textpattern CMS.
 *
 * @author Jukka Svahn
 * @date 2011-
 * @license GNU GPLv2
 * @link http://rahforum.biz/plugins/rah_bitly
 * 
 * Copyright (C) 2011 Jukka Svahn <http://rahforum.biz>
 * Licensed under GNU Genral Public License version 2
 * http://www.gnu.org/licenses/gpl-2.0.html
 */

	if(@txpinterface == 'admin') {
		rah_bitly::get();
	}

class rah_bitly {
	
	static public $version = '0.3';
	
	/**
	 * Stores instances
	 */
	
	static public $instance = NULL;
	
	/**
	 * @var string Article's current permlink
	 */
	
	public $permlink;
	
	/**
	 * @var string Article's previous permlink
	 */
	
	private $prev_permlink;
	
	/**
	 * @var int Article's previous status
	 */
	
	private $prev_status;
	
	/**
	 * @var string Bitly login
	 */
	
	private $login;
	
	/**
	 * @var string Bitly API key
	 */
	
	private $apikey;
	
	/**
	 * @var int Custom field ID
	 */
	
	private $field;

	/**
	 * Installer
	 * @param string $event Admin-side event.
	 * @param string $step Admin-side, plugin-lifecycle step.
	 */

	static public function install($event='', $step='') {
		
		global $prefs;
		
		if($step == 'deleted') {
			
			safe_delete(
				'txp_prefs',
				"name like 'rah\_bitly\_%'"
			);
			
			return;
		}
		
		$current = isset($prefs['rah_bitly_version']) ?
			(string) $prefs['rah_bitly_version'] : 'base';
		
		if($current === self::$version)
			return;
		
		$position = 250;
		
		foreach(
			array(
				'login',
				'apikey',
				'field'
			) as $name
		) {
			
			if(!isset($prefs['rah_bitly_'. $name])) {
				$html = $name == 'field' ? 'rah_bitly_fields' : 'text_input';
				
				safe_insert(
					'txp_prefs',
					"prefs_id=1,
					name='rah_bitly_".$name."',
					val='',
					type=1,
					event='rah_bitly',
					html='$html',
					position=".$position
				);
				
				$prefs['rah_bitly_'.$name] = '';
			}
			
			$position++;
		}
		
		set_pref('rah_bitly_version', self::$version, 'rah_bitly', 2, '', 0);
		$prefs['rah_bitly_version'] = self::$version;
	}
	
	/**
	 * Gets an instance
	 * @return obj
	 */
	
	static public function get() {
		if(self::$instance === NULL) {
			self::$instance = new rah_bitly();
		}
		
		return self::$instance;
	}
	
	/**
	 * Constructor
	 */
	
	public function __construct() {
		self::install();
		add_privs('plugin_prefs.'.__CLASS__, '1,2');
		register_callback(array(__CLASS__, 'prefs'), 'plugin_prefs.'.__CLASS__);
		register_callback(array(__CLASS__, 'install'), 'plugin_lifecycle.'.__CLASS__);
		register_callback(array($this, 'initialize'), 'article', '', 1);
	}
	
	/**
	 * Initializes
	 */
	
	public function initialize() {
		
		foreach(array('login', 'apikey', 'field') as $name) {
			$this->$name = get_pref(__CLASS__.'_'.$name);
		}
		
		$this->field = (int) $this->field;
		
		if(!$this->login || !$this->apikey || !$this->field) {
			return;
		}
		
		include_once txpath.'/publish/taghandlers.php';
		
		$this->previous_state();
		register_callback(array($this, 'update'), 'article_saved');
		register_callback(array($this, 'update'), 'article_posted');
	}
	
	/**
	 * Fetches old article data
	 */
	 
	public function previous_state() {
		
		$id = (int) ps('ID');
		
		if(!$id) {
			return;
		}
		
		$this->prev_permlink = permlinkurl_id($id);
		$this->prev_status = fetch('Status', 'textpattern', 'ID', $id);
	}

	/**
	 * Hooks to article saving process and updates short URLs
	 */

	public function update($event, $step, $r) {
		
		global $app_mode;
		
		$this->permlink = permlinkurl_id($r['ID']);
		
		callback_event('rah_bitly.update');
		
		if(!$this->permlink || $r['Status'] < STATUS_LIVE) {
			return;
		}
		
		if(
			$this->prev_permlink !== $this->permlink ||
			empty($r['custom_'.$this->field]) ||
			$prev_status != $r['status']
		) {
			$uri = $this->fetch($this->permlink);
		}
		
		if(empty($uri)) {
			return;
		}
		
		$fields = getCustomFields();
		
		if(!isset($fields[$this->field])) {
			return;
		}
		
		safe_update(
			'textpattern',
			'custom_'.intval($this->field)."='".doSlash($uri)."'",
			"ID='".doSlash($r['ID'])."'"
		);
		
		$_POST['custom_'.$this->field] = $uri;
		
		$js = 
			'$(document).ready(function(){'.
				'$(\'input[name="custom_'.$this->field.'"]\').val("'.escape_js($uri).'");'.
			'});';
		
		if($app_mode == 'async') {
			send_script_response($js);
		}
			
		else {
			echo script_js($js);
		}
	}

	/**
	 * Fetches a Bitly short URL
	 * @param string $permlink The long URL to shorten
	 * @param int $timeout Timeout in seconds
	 * @return string
	 */

	protected function fetch($permlink, $timeout=10) {
		
		if(!$permlink || !function_exists('curl_init')) {
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
	 * Redirect to the admin-side interface
	 */

	static public function prefs() {
		header('Location: ?event=prefs&step=advanced_prefs#prefs-rah_bitly_login');
		echo 
			'<p>'.n.
			'	<a href="?event=prefs&amp;step=advanced_prefs#prefs-rah_bitly_login">'.gTxt('continue').'</a>'.n.
			'</p>';
	}
}

/**
 * Lists all available custom fields
 * @param string $name Preference field's name.
 * @param string $val Current value.
 * @return string HTML select field.
 */

	function rah_bitly_fields($name, $val) {
		return selectInput($name, getCustomFields(), $val, true, '', $name);
	}
?>