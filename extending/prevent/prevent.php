<?php

/**
 * This is an example plugin for rah_bitly. Showcases extending.
 *
 * @package rah_bitly
 * @author Jukka Svahn
 * @version 0.1
 * @link https://github.com/gocom/rah_bitly
 *
 * The plugin will prevent generating bitly links, and pinging bitly when
 * article's section is set to "private"
 */

	if(@txpinterface == 'admin') {
		new rah_bitly__prevent();
	}

class rah_bitly__prevent {

	protected $ignore_sections = array();

	/**
	 * Constructor
	 */
	
	public function __construct() {
		
		if(defined('rah_bitly__prevent_sections')) {
			$this->ignore_sections = do_list(rah_bitly__prevent_sections);
		}
		
		register_callback(array($this, 'filter'), 'rah_bitly.update');
	}
 
 	/**
 	 * Does validation prior to generating a new link
 	 */

	public function filter() {
		if(strpos(ps('Section'), '_') === 0 || in_array(ps('Section'), $this->ignore_sections)) {
			rah_bitly::get()->permlink = false;
		}
	}
}

?>