<?php
/**
 * BLT Events - Sponsors
 *
 * A simple per-event repeater (logo + optional link), stored as JSON meta.
 * No connected-CPT mode like presenters — sponsors are always event-local.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Sponsors {

	public static function is_enabled_for( $event_id ) {
		return get_post_meta( $event_id, '_blt_sponsors_enabled', true ) === '1';
	}

	/**
	 * Normalized sponsor logos for an event, or an empty array when the
	 * toggle is off or none are set.
	 *
	 * @return array<int,array{image_id:int,src:string,url:string}>
	 */
	public static function for_event( $event_id ) {
		if ( ! self::is_enabled_for( $event_id ) ) {
			return array();
		}

		$raw  = get_post_meta( $event_id, '_blt_sponsors', true );
		$rows = is_string( $raw ) ? json_decode( $raw, true ) : $raw;

		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$image_id = absint( $row['image_id'] ?? 0 );
			if ( ! $image_id ) {
				continue;
			}

			$src = wp_get_attachment_image_url( $image_id, 'medium' );
			if ( ! $src ) {
				continue;
			}

			$out[] = array(
				'image_id' => $image_id,
				'src'      => $src,
				'url'      => esc_url_raw( (string) ( $row['url'] ?? '' ) ),
			);
		}

		return $out;
	}
}
