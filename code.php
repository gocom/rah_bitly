<?php	##################
	#
	#	rah_bitly-plugin for Textpattern
	#	version 0.1
	#	by Jukka Svahn
	#	http://rahforum.biz
	#
	#	Copyright (C) 2011 Jukka Svahn <http://rahforum.biz>
	#	Licensed under GNU Genral Public License version 2
	#	http://www.gnu.org/licenses/gpl-2.0.html
	#
	##################

	if(@txpinterface == 'admin') {
		rah_bitly_install();
		add_privs('plugin_prefs.rah_bitly','1,2');
		register_callback('rah_bitly_prefs','plugin_prefs.rah_bitly');
		register_callback('rah_bitly_install','plugin_lifecycle.rah_bitly');
		register_callback('rah_bitly','article','edit',1);
		register_callback('rah_bitly','article','publish',1);
		register_callback('rah_bitly','article','create',1);
		register_callback('rah_bitly','article','save',1);
		register_callback('rah_bitly','article','edit',0);
		register_callback('rah_bitly','article','publish',0);
		register_callback('rah_bitly','article','create',0);
		register_callback('rah_bitly','article','save',0);
	}

/**
	The unified installer and uninstaller
	@param $event string Admin-side event.
	@param $step string Admin-side, plugin-lifecycle step.
*/

	function rah_bitly_install($event='',$step='') {
		
		/*
			Uninstall if uninstalling the
			plugin
		*/
		
		if($step == 'deleted') {
			
			safe_delete(
				'txp_prefs',
				"name like 'rah_bitly_%'"
			);
			
			return;
		}
		
		global $prefs, $textarray;
		
		/*
			Make sure language strings are set
		*/
		
		foreach(
			array(
				'rah_bitly' => 'Bitly integration',
				'rah_bitly_login' => 'Bitly Login',
				'rah_bitly_apikey' => 'API key',
				'rah_bitly_field' => 'Store in custom field'
			) as $string => $translation
		)
			if(!isset($textarray[$string]))
				$textarray[$string] = $translation;
		
		$version = '0.1';
		
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
		
		/*
			Set version
		*/
		
		set_pref('rah_bitly_version',$version,'rah_bitly',2,'',0);
		$prefs['rah_bitly_version'] = $version;
	}

/**
	Hook to article saving process and update bitly short URLs
	@param $event string Admin-side callback event
	@param $step string Admin-side callback step
*/

	function rah_bitly($event='',$step='') {
		
		global $prefs;
		
		if(
			!isset($prefs['rah_bitly_login']) ||
			empty($prefs['rah_bitly_login']) ||
			empty($prefs['rah_bitly_apikey']) ||
			empty($prefs['rah_bitly_field'])
		)
			return;
		
		static $old = array();
		static $updated = false;
		
		$id = isset($GLOBALS['ID']) && !empty($GLOBALS['ID']) ? $GLOBALS['ID'] : ps('ID');
		
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
			echo <<<EOF
				<script type="text/javascript">
					<!--
						$('input[name="custom_{$prefs['rah_bitly_field']}"]').val('$uri');
					-->
				</script>
EOF;
		}
	}

/**
	Fetches the bitly short URL
	@param $permlink string The long URL to shorten
	@param $timeout in Timeout in seconds
	@return string The shortened URL, false on failure.
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
			If cURL isn't available,
			use file_get_contents if possible
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
	Lists all available custom fields
	@param $name string Preferences field's name.
	@param $val string Currently save value
	@return string HTML select field.
*/

	function rah_bitly_fields($name,$val) {
		$out = array();
		$out[''] = gTxt('none');
		
		foreach(getCustomFields() as $id => $label)
			$out[$id] = htmlspecialchars( $id . ' : ' . $label);
		
		return selectInput($name, $out, $val, '', '', $name);
	}

/**
	Redirect to the admin-side interface
*/

	function rah_bitly_prefs() {
		header('Location: ?event=prefs&step=advanced_prefs#prefs-rah_bitly_login');
		echo 
			'<p>'.n.
			'	<a href="?event=prefs&amp;step=advanced_prefs#prefs-rah_bitly_login">'.gTxt('continue').'</a>'.n.
			'</p>';
	}
?>