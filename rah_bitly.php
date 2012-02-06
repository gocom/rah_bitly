<?php

/**
 * Rah_bitly plugin for Textpattern CMS.
 *
 * @author Jukka Svahn
 * @date 2011-
 * @license GNU GPLv2
 * @link http://rahforum.biz/plugins/rah_bitly
 *
 * Requires Textpattern v4.4.1 or newer.
 * 
 * Copyright (C) 2011 Jukka Svahn <http://rahforum.biz>
 * Licensed under GNU Genral Public License version 2
 * http://www.gnu.org/licenses/gpl-2.0.html
 */

	if(@txpinterface == 'admin') {
		rah_bitly_install();
		add_privs('plugin_prefs.rah_bitly', '1,2');
		register_callback('rah_bitly_prefs', 'plugin_prefs.rah_bitly');
		register_callback('rah_bitly_install', 'plugin_lifecycle.rah_bitly');
		register_callback('rah_bitly', 'article', 'edit', 1);
		register_callback('rah_bitly', 'article', 'publish', 1);
		register_callback('rah_bitly', 'article', 'create', 1);
		register_callback('rah_bitly', 'article', 'save', 1);
		register_callback('rah_bitly', 'article', 'edit', 0);
		register_callback('rah_bitly', 'article', 'publish', 0);
		register_callback('rah_bitly', 'article', 'create', 0);
		register_callback('rah_bitly', 'article', 'save', 0);
	}

/**
 * Installer
 * @param string $event Admin-side event.
 * @param string $step Admin-side, plugin-lifecycle step.
 */

	function rah_bitly_install($event='', $step='') {
		
		global $prefs;
		
		if($step == 'deleted') {
			
			safe_delete(
				'txp_prefs',
				"name like 'rah\_bitly\_%'"
			);
			
			return;
		}

		$version = '0.3';
		
		if(
			isset($prefs['rah_bitly_version']) &&
			$prefs['rah_bitly_version'] == $version
		)
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
				
				$prefs['rah_bitly_'. $name] = '';
			}
			
			$position++;
		}
		
		set_pref('rah_bitly_version',$version,'rah_bitly',2,'',0);
		$prefs['rah_bitly_version'] = $version;
	}

/**
 * Hooks to article saving process and update bitly short URLs
 */

	function rah_bitly() {
		
		global $prefs;
		
		if(
			empty($prefs['rah_bitly_login']) ||
			empty($prefs['rah_bitly_apikey']) ||
			empty($prefs['rah_bitly_field'])
		)
			return;
		
		static $old = array();
		static $updated = false;
		
		$id = !empty($GLOBALS['ID']) ? $GLOBALS['ID'] : ps('ID');
		
		if(!$id || ps('_txp_token') != form_token() || ps('Status') < 4){
			$old = array('permlink' => NULL, 'status' => NULL);
			return;
		}
		
		include_once txpath.'/publish/taghandlers.php';
		
		/*
			Get the old article permlink before anything is saved
		*/
		
		if(!$old) {
			$old =
				array(
					'permlink' => permlinkurl_id($id),
					'status' => fetch('Status', 'textpattern', 'ID', $id)
				);
			return;
		}
		
		/*
			Clear the permlink cache
		*/
		
		unset(
			$GLOBALS['permlinks'][$id]
		);
		
		/*
			If permlink is different than the old one,
			or if article is re/published generate a new
		*/
		
		if(
			$updated == false && 
			($permlink = permlinkurl_id($id)) && 
			(
				$old['permlink'] != $permlink || 
				!ps('custom_'.$prefs['rah_bitly_field']) || 
				$old['status'] != ps('Status')
			)
		) {
			
			$uri = rah_bitly_fetch($permlink);
			
			if($uri) {
				
				$fields = getCustomFields();
				
				if(!isset($fields[$prefs['rah_bitly_field']]))
					return;
				
				safe_update(
					'textpattern',
					'custom_'.$prefs['rah_bitly_field']."='".doSlash($uri)."'",
					"ID='".doSlash($id)."'"
				);
				
				$_POST['custom_'.$prefs['rah_bitly_field']] = $uri;
			}
			
			$updated = true;
		}
		
		if(isset($uri) && !empty($uri)) {
			echo 
				script_js(
					'$(\'input[name="custom_'.$prefs['rah_bitly_field'].'"]\').val("'.escape_js($uri).'");'
				);
		}
	}

/**
 * Fetches the bitly short URL
 * @param string $permlink The long URL to shorten
 * @param int $timeout Timeout in seconds
 * @return string The shortened URL, false on failure.
 */

	function rah_bitly_fetch($permlink, $timeout=10) {
		
		global $prefs;
		
		if(!$permlink)
			return;
	
		$uri = 
			'http://api.bitly.com/v3/shorten'.
				'?login='.urlencode($prefs['rah_bitly_login']).
				'&apiKey='.urlencode($prefs['rah_bitly_apikey']).
				'&longUrl='.urlencode($permlink).
				'&format=txt'
		;
		
		/*
			If cURL isn't available, try file_get_contnets instead
		*/
		
		if(!function_exists('curl_init')) {
			
			if((@$fopen = ini_get('allow_url_fopen')) && !$fopen)
				return false;
			
			$context = 
				stream_context_create(
					array('http' => array('timeout' => $timeout))
				);
			
			@$bitcode = file_get_contents($uri, 0, $context);
		}
		
		else {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $uri);
			curl_setopt($ch, CURLOPT_FAILONERROR, 1);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
			curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
			$bitcode = curl_exec($ch);
			curl_close($ch);
		}
		
		return $bitcode && strpos($bitcode, 'http') === 0 ? htmlspecialchars(trim($bitcode)) : false;
	}

/**
 * Lists all available custom fields
 * @param string $name Preference field's name.
 * @param string $val Current value.
 * @return string HTML select field.
 */

	function rah_bitly_fields($name, $val) {
		$out = array();
		$out[''] = gTxt('none');
		
		foreach(rah_bitly_getcustomfields() as $id => $label)
			$out[$id] = $id . ' : ' . $label;
		
		return selectInput($name, $out, $val, '', '', $name);
	}

/**
 * Get custom fields. Core's getCustomFields() with added ability to pick new fields from POST data.
 * @return array List of custom fields.
 */

	function rah_bitly_getcustomfields() {
		global $prefs;

		$cfs = preg_grep('/^custom_\d+_set/', array_keys($prefs));
		$out = array();

		foreach($cfs as $name) {
			preg_match('/(\d+)/', $name, $match);
			$newname = ps($name);
			if(!empty($prefs[$name]) || !empty($newname)) {
				$out[$match[1]] = $newname ? $newname : $prefs[$name];
			}
		}

		return $out;
	}

/**
 * Redirect to the admin-side interface
 */

	function rah_bitly_prefs() {
		header('Location: ?event=prefs&step=advanced_prefs#prefs-rah_bitly_login');
		echo 
			'<p>'.n.
			'	<a href="?event=prefs&amp;step=advanced_prefs#prefs-rah_bitly_login">'.gTxt('continue').'</a>'.n.
			'</p>';
	}
?>