<?php
/**
 * Base database abstraction class.
 *
 * Provides common CRUD operations for custom DB tables.
 * All child classes extend this and set their own table name.
 *
 * @package BLT_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BLT_Events_DB {

	/**
	 * Full table name including wpdb prefix.
	 *
	 * @var string
	 */
	protected $table_name;

	/**
	 * Primary key column name.
	 *
	 * @var string
	 */
	protected $primary_key = 'id';

	/**
	 * Constructor.
	 *
	 * @param string $table_name Table name without the wpdb prefix.
	 */
	public function __construct( $table_name ) {
		global $wpdb;
		$this->table_name = $wpdb->prefix . $table_name;
	}

	/**
	 * Insert a new row.
	 *
	 * @param array $data Column => value pairs to insert.
	 * @return int|false The insert ID on success, false on failure.
	 */
	public function insert( $data ) {
		global $wpdb;

		$data = $this->sanitize_data( $data );

		if ( ! isset( $data['created_at'] ) ) {
			$data['created_at'] = current_time( 'mysql' );
		}

		if ( ! isset( $data['updated_at'] ) ) {
			$data['updated_at'] = current_time( 'mysql' );
		}

		$result = $wpdb->insert( $this->table_name, $data );

		if ( false === $result ) {
			return false;
		}

		return $wpdb->insert_id;
	}

	/**
	 * Update an existing row by primary key.
	 *
	 * @param int   $id   Row ID.
	 * @param array $data Column => value pairs to update.
	 * @return int|false Number of rows updated, or false on error.
	 */
	public function update( $id, $data ) {
		global $wpdb;

		$id   = absint( $id );
		$data = $this->sanitize_data( $data );

		// Always refresh updated_at timestamp.
		$data['updated_at'] = current_time( 'mysql' );

		return $wpdb->update(
			$this->table_name,
			$data,
			array( $this->primary_key => $id )
		);
	}

	/**
	 * Delete a row by primary key.
	 *
	 * @param int $id Row ID.
	 * @return int|false Number of rows deleted, or false on error.
	 */
	public function delete( $id ) {
		global $wpdb;

		$id = absint( $id );

		return $wpdb->delete(
			$this->table_name,
			array( $this->primary_key => $id ),
			array( '%d' )
		);
	}

	/**
	 * Get a single row by primary key.
	 *
	 * @param int $id Row ID.
	 * @return object|null Row object or null if not found.
	 */
	public function get( $id ) {
		global $wpdb;

		$id = absint( $id );

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE {$this->primary_key} = %d",
				$id
			)
		);
	}

	/**
	 * Get the first row matching a column value.
	 *
	 * @param string $column Column name.
	 * @param mixed  $value  Value to match.
	 * @return object|null Row object or null if not found.
	 */
	public function get_by( $column, $value ) {
		global $wpdb;

		$column = sanitize_key( $column );

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE {$column} = %s LIMIT 1",
				$value
			)
		);
	}

	/**
	 * Get paginated list of rows.
	 *
	 * @param array $args {
	 *     Optional. Query arguments.
	 *
	 *     @type string $orderby Column to order by. Default 'id'.
	 *     @type string $order   ASC or DESC. Default 'DESC'.
	 *     @type int    $limit   Number of rows to return. Default 20.
	 *     @type int    $offset  Number of rows to skip. Default 0.
	 *     @type array  $where   Array of conditions. Each condition is an
	 *                           associative array with 'column', 'value',
	 *                           and optionally 'compare' (default '=').
	 * }
	 * @return array Array of row objects.
	 */
	public function get_all( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'orderby' => $this->primary_key,
			'order'   => 'DESC',
			'limit'   => 20,
			'offset'  => 0,
			'where'   => array(),
		);

		$args = wp_parse_args( $args, $defaults );

		// Sanitize order direction.
		$order = strtoupper( $args['order'] );
		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$order = 'DESC';
		}

		// Sanitize orderby column.
		$orderby = sanitize_key( $args['orderby'] );

		$limit  = absint( $args['limit'] );
		$offset = absint( $args['offset'] );

		// Build WHERE clause.
		$where_sql    = '';
		$where_values = array();

		if ( ! empty( $args['where'] ) && is_array( $args['where'] ) ) {
			$where_clauses = $this->build_where_clauses( $args['where'], $where_values );
			if ( ! empty( $where_clauses ) ) {
				$where_sql = 'WHERE ' . implode( ' AND ', $where_clauses );
			}
		}

		$sql = "SELECT * FROM {$this->table_name} {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

		$where_values[] = $limit;
		$where_values[] = $offset;

		return $wpdb->get_results(
			$wpdb->prepare( $sql, $where_values )
		);
	}

	/**
	 * Count rows matching conditions.
	 *
	 * @param array $where Array of conditions. Same format as get_all() where param.
	 * @return int Number of matching rows.
	 */
	public function count( $where = array() ) {
		global $wpdb;

		$where_sql    = '';
		$where_values = array();

		if ( ! empty( $where ) && is_array( $where ) ) {
			$where_clauses = $this->build_where_clauses( $where, $where_values );
			if ( ! empty( $where_clauses ) ) {
				$where_sql = 'WHERE ' . implode( ' AND ', $where_clauses );
			}
		}

		if ( ! empty( $where_values ) ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$this->table_name} {$where_sql}",
					$where_values
				)
			);
		}

		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->table_name}"
		);
	}

	/**
	 * Build WHERE clauses from an array of conditions.
	 *
	 * Each condition should be an array with:
	 *   - 'column'  (string) Column name.
	 *   - 'value'   (mixed)  Value to compare.
	 *   - 'compare' (string) Comparison operator. Default '='.
	 *
	 * @param array $conditions    Array of condition arrays.
	 * @param array &$values       Values array to populate for prepare().
	 * @return array Array of SQL clause strings.
	 */
	protected function build_where_clauses( $conditions, &$values ) {
		$clauses            = array();
		$allowed_comparisons = array( '=', '!=', '>', '<', '>=', '<=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN' );

		foreach ( $conditions as $condition ) {
			if ( ! isset( $condition['column'], $condition['value'] ) ) {
				continue;
			}

			$column  = sanitize_key( $condition['column'] );
			$compare = isset( $condition['compare'] ) ? strtoupper( trim( $condition['compare'] ) ) : '=';

			if ( ! in_array( $compare, $allowed_comparisons, true ) ) {
				$compare = '=';
			}

			if ( in_array( $compare, array( 'IN', 'NOT IN' ), true ) ) {
				$in_values = (array) $condition['value'];
				if ( empty( $in_values ) ) {
					continue;
				}
				$placeholders = implode( ', ', array_fill( 0, count( $in_values ), '%s' ) );
				$clauses[]    = "{$column} {$compare} ({$placeholders})";
				$values       = array_merge( $values, $in_values );
			} else {
				// Only genuine PHP numbers get numeric placeholders;
				// numeric-looking strings ("1e3", "1.5") must not be
				// truncated by %d when comparing string columns.
				if ( is_int( $condition['value'] ) ) {
					$placeholder = '%d';
				} elseif ( is_float( $condition['value'] ) ) {
					$placeholder = '%f';
				} else {
					$placeholder = '%s';
				}
				$clauses[] = "{$column} {$compare} {$placeholder}";
				$values[]  = $condition['value'];
			}
		}

		return $clauses;
	}

	/**
	 * Sanitize data before insert/update.
	 *
	 * Applies sanitize_text_field to string values.
	 * JSON-encoded strings and numeric values are preserved.
	 *
	 * @param array $data Column => value pairs.
	 * @return array Sanitized data.
	 */
	protected function sanitize_data( $data ) {
		$sanitized = array();

		// Only these columns store JSON; other string values must never
		// skip sanitization just because they happen to parse as JSON.
		$json_columns = array( 'fields', 'consent_fields', 'custom_fields', 'coupon_data' );

		foreach ( $data as $key => $value ) {
			$key = sanitize_key( $key );

			if ( is_null( $value ) ) {
				$sanitized[ $key ] = null;
			} elseif ( is_int( $value ) || is_float( $value ) ) {
				$sanitized[ $key ] = $value;
			} elseif ( is_string( $value ) && in_array( $key, $json_columns, true ) && $this->is_json( $value ) ) {
				// Preserve JSON-encoded strings for known JSON columns.
				$sanitized[ $key ] = $value;
			} elseif ( is_string( $value ) ) {
				$sanitized[ $key ] = sanitize_text_field( $value );
			} else {
				$sanitized[ $key ] = $value;
			}
		}

		return $sanitized;
	}

	/**
	 * Check if a string is valid JSON.
	 *
	 * @param string $string String to check.
	 * @return bool True if valid JSON, false otherwise.
	 */
	protected function is_json( $string ) {
		if ( ! is_string( $string ) || strlen( $string ) < 2 ) {
			return false;
		}

		$first = $string[0];
		if ( '{' !== $first && '[' !== $first ) {
			return false;
		}

		json_decode( $string );
		return json_last_error() === JSON_ERROR_NONE;
	}

	/**
	 * Get the full table name.
	 *
	 * @return string
	 */
	public function get_table_name() {
		return $this->table_name;
	}
}
