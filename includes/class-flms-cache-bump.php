<?php
/**
 * Versioned frontend cache invalidation (avoids expensive DELETE ... LIKE on transients).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FLMS_Cache_Bump {

	const OPTION_KEY = 'flms_render_cache_version';

	public static function init() {
		add_action( 'save_post_flms_match', [ __CLASS__, 'bump' ], 5 );
		add_action( 'save_post_flms_player', [ __CLASS__, 'bump' ], 5 );
		add_action( 'save_post_flms_team', [ __CLASS__, 'bump' ], 5 );
	}

	public static function version() {
		return (int) get_option( self::OPTION_KEY, 1 );
	}

	public static function bump() {
		update_option( self::OPTION_KEY, self::version() + 1, false );
	}
}
