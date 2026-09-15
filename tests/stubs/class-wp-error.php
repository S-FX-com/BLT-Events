<?php
declare( strict_types=1 );

/**
 * Minimal WP_Error for tests that run without WordPress loaded.
 */
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private array $errors = array();
		private array $data   = array();

		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( '' !== $code ) {
				$this->add( $code, $message, $data );
			}
		}

		public function add( $code, $message, $data = '' ): void {
			$this->errors[ $code ][] = $message;
			if ( '' !== $data ) {
				$this->data[ $code ] = $data;
			}
		}

		public function get_error_code() {
			$codes = array_keys( $this->errors );
			return $codes[0] ?? '';
		}

		public function get_error_message( $code = '' ) {
			$messages = $this->get_error_messages( $code );
			return $messages[0] ?? '';
		}

		public function get_error_messages( $code = '' ): array {
			if ( '' !== $code ) {
				return $this->errors[ $code ] ?? array();
			}
			$all = array();
			foreach ( $this->errors as $messages ) {
				$all = array_merge( $all, $messages );
			}
			return $all;
		}

		public function get_error_data( $code = '' ) {
			$code = '' !== $code ? $code : $this->get_error_code();
			return $this->data[ $code ] ?? null;
		}

		public function has_errors(): bool {
			return ! empty( $this->errors );
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}
