<?php

/**
 * Localhost module for rah_bitly
 *
 * @package rah_bitly
 * @author Jukka Svahn
 * @version 0.1
 * @link https://github.com/gocom/rah_bitly
 */

	if(@txpinterface == 'admin') {
		register_callback('rah_bitly__localhost', 'rah_bitly.update');
	}

/**
 * Prepends "http://example.com/#" to the localhost links
 */

	function rah_bitly__localhost() {
		rah_bitly::get()->permlink = 'http://example.com/#'.rah_bitly::get()->permlink;
		rah_bitly::get()->prev_permlink = 'http://example.com/#'.rah_bitly::get()->prev_permlink;
	}

?>