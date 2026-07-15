<?php
/**
 * BLT Events - Presenters
 *
 * Presenter data lives in one of two modes, chosen in Settings:
 *
 *  - Connected mode: a site's existing presenter/speaker CPT is linked in
 *    Settings > Integrations. Events reference presenter post IDs, and
 *    fields (role, bio, photo) are read live from that CPT via configurable
 *    ACF/meta keys — nothing is duplicated.
 *  - Built-in mode (default): no CPT is connected, so each event stores its
 *    own presenter rows (name/role/bio/photo) via the repeater.
 *
 * Either way presenters only appear when the event's "Show presenters"
 * toggle is on. This class normalizes both modes to a common shape and
 * renders the single-event sidebar block.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_Presenters {

	public static function init() {
		add_action( 'wp_ajax_blt_search_presenters', array( __CLASS__, 'ajax_search' ) );
		add_action( 'blt_events_single_sidebar', array( __CLASS__, 'render_sidebar' ), 10, 1 );
	}

	/**
	 * The connected presenter post type slug, or '' when none is set (or the
	 * stored one no longer exists).
	 */
	public static function connected_post_type() {
		$slug = get_option( 'blt_events_presenter_post_type', '' );
		return ( $slug && post_type_exists( $slug ) ) ? $slug : '';
	}

	public static function is_connected() {
		return self::connected_post_type() !== '';
	}

	public static function is_enabled_for( $event_id ) {
		return get_post_meta( $event_id, '_blt_presenters_enabled', true ) === '1';
	}

	/**
	 * Normalized presenters for an event, or an empty array when the toggle
	 * is off or nothing is set.
	 *
	 * @return array<int,array{name:string,role:string,bio:string,photo:string,url:string}>
	 */
	public static function for_event( $event_id ) {
		if ( ! self::is_enabled_for( $event_id ) ) {
			return array();
		}

		return self::is_connected()
			? self::from_cpt( $event_id )
			: self::from_repeater( $event_id );
	}

	/**
	 * Presenters referenced from the connected CPT.
	 */
	private static function from_cpt( $event_id ) {
		$ids = get_post_meta( $event_id, '_blt_presenter_ids', true );
		$ids = is_array( $ids ) ? array_map( 'absint', $ids ) : array();
		if ( empty( $ids ) ) {
			return array();
		}

		$post_type = self::connected_post_type();
		$role_key  = get_option( 'blt_events_presenter_map_role', '' );
		$bio_key   = get_option( 'blt_events_presenter_map_bio', '' );
		$photo_key = get_option( 'blt_events_presenter_map_photo', '' );

		$out = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || $post->post_type !== $post_type || $post->post_status !== 'publish' ) {
				continue;
			}

			$out[] = array(
				'name'  => get_the_title( $id ),
				'role'  => $role_key ? (string) self::read_meta( $id, $role_key ) : '',
				'bio'   => $bio_key ? (string) self::read_meta( $id, $bio_key ) : wp_strip_all_tags( (string) $post->post_excerpt ),
				'photo' => self::resolve_photo( $id, $photo_key ),
				'url'   => (string) get_permalink( $id ),
			);
		}

		return $out;
	}

	/**
	 * Presenters stored directly on the event (built-in mode).
	 */
	private static function from_repeater( $event_id ) {
		$raw  = get_post_meta( $event_id, '_blt_presenters', true );
		$rows = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$name = trim( (string) ( $row['name'] ?? '' ) );
			if ( $name === '' ) {
				continue;
			}
			$image_id = absint( $row['image_id'] ?? 0 );
			$out[]    = array(
				'name'  => $name,
				'role'  => (string) ( $row['role'] ?? '' ),
				'bio'   => (string) ( $row['bio'] ?? '' ),
				'photo' => $image_id ? (string) wp_get_attachment_image_url( $image_id, 'medium' ) : '',
				'url'   => '',
			);
		}

		return $out;
	}

	/**
	 * Read a presenter field via ACF when available, falling back to raw
	 * post meta so the mapping works with or without ACF.
	 */
	private static function read_meta( $id, $key ) {
		if ( function_exists( 'get_field' ) ) {
			$value = get_field( $key, $id );
			if ( $value !== null && $value !== false ) {
				return is_scalar( $value ) ? $value : '';
			}
		}

		$meta = get_post_meta( $id, $key, true );
		return is_scalar( $meta ) ? $meta : '';
	}

	/**
	 * Resolve a presenter photo URL: the mapped image field (ACF image as
	 * array/ID/URL, or a meta attachment ID), else the featured image.
	 */
	private static function resolve_photo( $id, $photo_key ) {
		if ( $photo_key ) {
			$raw = function_exists( 'get_field' ) ? get_field( $photo_key, $id ) : get_post_meta( $id, $photo_key, true );

			if ( is_array( $raw ) ) {
				// ACF image (array return format).
				if ( ! empty( $raw['sizes']['medium'] ) ) {
					return $raw['sizes']['medium'];
				}
				if ( ! empty( $raw['url'] ) ) {
					return $raw['url'];
				}
			} elseif ( is_numeric( $raw ) ) {
				$url = wp_get_attachment_image_url( (int) $raw, 'medium' );
				if ( $url ) {
					return $url;
				}
			} elseif ( is_string( $raw ) && $raw !== '' ) {
				return $raw; // Already a URL.
			}
		}

		return (string) get_the_post_thumbnail_url( $id, 'medium' );
	}

	/* ------------------------------------------------------------------
	 * Admin AJAX: search the connected CPT for the event picker.
	 * ------------------------------------------------------------------ */

	public static function ajax_search() {
		check_ajax_referer( 'blt_presenter_search', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'blt-events' ) ) );
		}

		$post_type = self::connected_post_type();
		if ( ! $post_type ) {
			wp_send_json_error( array( 'message' => __( 'No presenter post type connected.', 'blt-events' ) ) );
		}

		$term  = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
		$query = new WP_Query( array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			's'              => $term,
			'posts_per_page' => 20,
			'no_found_rows'  => true,
		) );

		$results = array();
		foreach ( $query->posts as $post ) {
			$results[] = array(
				'id'    => $post->ID,
				'title' => get_the_title( $post ),
				'photo' => (string) get_the_post_thumbnail_url( $post->ID, 'thumbnail' ),
			);
		}

		wp_send_json_success( array( 'presenters' => $results ) );
	}

	/**
	 * Titles for a set of presenter IDs, for pre-filling the editor chips.
	 *
	 * @return array<int,array{id:int,title:string,photo:string}>
	 */
	public static function chips_for( $ids ) {
		$out = array();
		foreach ( array_map( 'absint', (array) $ids ) as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}
			$out[] = array(
				'id'    => $id,
				'title' => get_the_title( $id ),
				'photo' => (string) get_the_post_thumbnail_url( $id, 'thumbnail' ),
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------
	 * Front-end sidebar block.
	 * ------------------------------------------------------------------ */

	public static function render_sidebar( $event_id ) {
		$presenters = self::for_event( $event_id );
		if ( empty( $presenters ) ) {
			return;
		}

		$label = count( $presenters ) > 1
			? __( 'Presenters', 'blt-events' )
			: __( 'Presenter', 'blt-events' );
		?>
		<div class="blt-event__card blt-event__presenters">
			<h3 class="blt-event__card-title"><?php echo esc_html( $label ); ?></h3>
			<ul class="blt-event__presenter-list">
				<?php foreach ( $presenters as $p ) : ?>
					<li class="blt-event__presenter">
						<?php if ( $p['photo'] ) : ?>
							<img class="blt-event__presenter-photo" src="<?php echo esc_url( $p['photo'] ); ?>" alt="<?php echo esc_attr( $p['name'] ); ?>" loading="lazy" />
						<?php endif; ?>
						<span class="blt-event__presenter-meta">
							<?php if ( $p['url'] ) : ?>
								<a class="blt-event__presenter-name" href="<?php echo esc_url( $p['url'] ); ?>"><?php echo esc_html( $p['name'] ); ?></a>
							<?php else : ?>
								<span class="blt-event__presenter-name"><?php echo esc_html( $p['name'] ); ?></span>
							<?php endif; ?>
							<?php if ( $p['role'] ) : ?>
								<span class="blt-event__presenter-role"><?php echo esc_html( $p['role'] ); ?></span>
							<?php endif; ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}
}
