<?php
/**
 * BLT Events - Coupon Custom Post Type
 *
 * Registers the "blt_coupon" custom post type with meta boxes,
 * custom admin columns, and save logic for coupon management.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BLT_Events_Coupon_CPT {

    /**
     * Post type slug.
     *
     * @var string
     */
    public static $slug = 'blt_coupon';

    /**
     * Initialize hooks for the coupon CPT.
     */
    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_post_type' ) );
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
        add_action( 'save_post_' . self::$slug, array( __CLASS__, 'save_post' ) );
        add_action( 'manage_' . self::$slug . '_posts_columns', array( __CLASS__, 'set_custom_columns' ) );
        add_action( 'manage_' . self::$slug . '_posts_custom_column', array( __CLASS__, 'custom_column' ), 10, 2 );
        add_filter( 'manage_edit-' . self::$slug . '_sortable_columns', array( __CLASS__, 'sortable_columns' ) );
    }

    /**
     * Register the coupon custom post type.
     */
    public static function register_post_type() {
        $labels = array(
            'name'               => __( 'Coupons', 'blt-events' ),
            'singular_name'      => __( 'Coupon', 'blt-events' ),
            'menu_name'          => __( 'Coupons', 'blt-events' ),
            'add_new'            => __( 'Add New', 'blt-events' ),
            'add_new_item'       => __( 'Add New Coupon', 'blt-events' ),
            'edit_item'          => __( 'Edit Coupon', 'blt-events' ),
            'new_item'           => __( 'New Coupon', 'blt-events' ),
            'view_item'          => __( 'View Coupon', 'blt-events' ),
            'search_items'       => __( 'Search Coupons', 'blt-events' ),
            'not_found'          => __( 'No coupons found', 'blt-events' ),
            'not_found_in_trash' => __( 'No coupons found in Trash', 'blt-events' ),
        );

        $args = array(
            'labels'             => $labels,
            'public'             => false,
            'show_ui'            => true,
            'show_in_menu'       => 'edit.php?post_type=event',
            'show_in_admin_bar'  => true,
            'menu_position'      => null,
            'capability_type'    => 'post',
            'hierarchical'       => false,
            'supports'           => array( 'title' ),
            'has_archive'        => false,
            'rewrite'            => false,
            'query_var'          => false,
        );

        register_post_type( self::$slug, $args );
    }

    /**
     * Register meta boxes for the coupon edit screen.
     */
    public static function add_meta_boxes() {
        add_meta_box(
            'blt_coupon_details',
            __( 'Coupon Details', 'blt-events' ),
            array( __CLASS__, 'render_details_meta_box' ),
            self::$slug,
            'normal',
            'high'
        );

        add_meta_box(
            'blt_coupon_usage',
            __( 'Usage Statistics', 'blt-events' ),
            array( __CLASS__, 'render_usage_meta_box' ),
            self::$slug,
            'normal',
            'default'
        );
    }

    /**
     * Render the Coupon Details meta box.
     *
     * @param WP_Post $post The current post object.
     */
    public static function render_details_meta_box( $post ) {
        wp_nonce_field( 'blt_coupon_details', 'blt_coupon_details_nonce' );

        $code              = get_post_meta( $post->ID, '_blt_coupon_code', true );
        $discount_type     = get_post_meta( $post->ID, '_blt_discount_type', true ) ?: 'fixed';
        $amount            = get_post_meta( $post->ID, '_blt_amount', true ) ?: '';
        $expiration_date   = get_post_meta( $post->ID, '_blt_expiration_date', true ) ?: '';
        $usage_limit       = get_post_meta( $post->ID, '_blt_usage_limit', true ) ?: '';
        $status            = get_post_meta( $post->ID, '_blt_status', true ) ?: 'active';
        $applicable_events = get_post_meta( $post->ID, '_blt_applicable_events', true ) ?: array( 'all' );

        ?>
        <table class="form-table">
            <tr>
                <th><label for="coupon_code"><?php esc_html_e( 'Coupon Code', 'blt-events' ); ?></label></th>
                <td>
                    <input type="text" id="coupon_code" name="coupon_code" value="<?php echo esc_attr( $code ); ?>" class="regular-text" required>
                    <button type="button" class="button button-secondary" id="generate_coupon_code"><?php esc_html_e( 'Generate Code', 'blt-events' ); ?></button>
                </td>
            </tr>
            <tr>
                <th><label for="discount_type"><?php esc_html_e( 'Discount Type', 'blt-events' ); ?></label></th>
                <td>
                    <select id="discount_type" name="discount_type">
                        <option value="fixed" <?php selected( $discount_type, 'fixed' ); ?>><?php esc_html_e( 'Fixed Amount ($)', 'blt-events' ); ?></option>
                        <option value="percentage" <?php selected( $discount_type, 'percentage' ); ?>><?php esc_html_e( 'Percentage (%)', 'blt-events' ); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="amount"><?php esc_html_e( 'Amount', 'blt-events' ); ?></label></th>
                <td>
                    <input type="number" id="amount" name="amount" value="<?php echo esc_attr( $amount ); ?>" step="0.01" min="0" class="regular-text" required>
                    <span id="amount_symbol"><?php echo $discount_type === 'percentage' ? '%' : '$'; ?></span>
                </td>
            </tr>
            <tr>
                <th><label for="expiration_date"><?php esc_html_e( 'Expiration Date', 'blt-events' ); ?></label></th>
                <td>
                    <input type="date" id="expiration_date" name="expiration_date" value="<?php echo esc_attr( $expiration_date ); ?>" class="regular-text">
                    <p class="description"><?php esc_html_e( 'Leave blank for no expiration', 'blt-events' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="usage_limit"><?php esc_html_e( 'Usage Limit', 'blt-events' ); ?></label></th>
                <td>
                    <input type="number" id="usage_limit" name="usage_limit" value="<?php echo esc_attr( $usage_limit ); ?>" min="0" class="regular-text">
                    <p class="description"><?php esc_html_e( 'Maximum number of times this coupon can be used. Leave blank for unlimited.', 'blt-events' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="status"><?php esc_html_e( 'Status', 'blt-events' ); ?></label></th>
                <td>
                    <select id="status" name="status">
                        <option value="active" <?php selected( $status, 'active' ); ?>><?php esc_html_e( 'Active', 'blt-events' ); ?></option>
                        <option value="inactive" <?php selected( $status, 'inactive' ); ?>><?php esc_html_e( 'Inactive', 'blt-events' ); ?></option>
                    </select>
                </td>
            </tr>
            <?php
            $event_ids = get_posts( array(
                'post_type'      => BLT_Events_Event_CPT::$slug,
                'posts_per_page' => 500,
                'post_status'    => 'publish',
                'fields'         => 'ids',
                'orderby'        => 'title',
                'order'          => 'ASC',
                'no_found_rows'  => true,
            ) );
            ?>
            <tr>
                <th><label for="applicable_events"><?php esc_html_e( 'Applicable Events', 'blt-events' ); ?></label></th>
                <td>
                    <select id="applicable_events" name="applicable_events[]" multiple style="width: 100%; min-height: 120px;">
                        <option value="all" <?php selected( in_array( 'all', $applicable_events ) || empty( $applicable_events ) ); ?>><?php esc_html_e( 'All Events', 'blt-events' ); ?></option>
                        <?php foreach ( $event_ids as $ev_id ) : ?>
                            <option value="<?php echo esc_attr( $ev_id ); ?>" <?php selected( in_array( $ev_id, array_map( 'intval', $applicable_events ), true ) ); ?>>
                                <?php echo esc_html( get_the_title( $ev_id ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e( 'Select which events this coupon can be applied to. Select "All Events" for no restrictions.', 'blt-events' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render the Usage Statistics meta box.
     *
     * @param WP_Post $post The current post object.
     */
    public static function render_usage_meta_box( $post ) {
        $total_uses    = get_post_meta( $post->ID, '_blt_total_uses', true ) ?: 0;
        $total_savings = get_post_meta( $post->ID, '_blt_total_savings', true ) ?: 0;
        $last_used     = get_post_meta( $post->ID, '_blt_last_used', true ) ?: 0;
        $usage_history = get_post_meta( $post->ID, '_blt_usage_history', true ) ?: array();

        ?>
        <table class="form-table">
            <tr>
                <th><label><?php esc_html_e( 'Total Uses', 'blt-events' ); ?></label></th>
                <td><?php echo intval( $total_uses ); ?></td>
            </tr>
            <tr>
                <th><label><?php esc_html_e( 'Total Customer Savings', 'blt-events' ); ?></label></th>
                <td>$<?php echo number_format( (float) $total_savings, 2 ); ?></td>
            </tr>
            <tr>
                <th><label><?php esc_html_e( 'Last Used', 'blt-events' ); ?></label></th>
                <td><?php echo $last_used ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $last_used ) ) : esc_html__( 'Never', 'blt-events' ); ?></td>
            </tr>
        </table>

        <?php if ( ! empty( $usage_history ) ) : ?>
            <h3><?php esc_html_e( 'Usage History', 'blt-events' ); ?></h3>
            <table class="widefat fixed" style="width: 100%;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Date', 'blt-events' ); ?></th>
                        <th><?php esc_html_e( 'Registration', 'blt-events' ); ?></th>
                        <th><?php esc_html_e( 'Customer', 'blt-events' ); ?></th>
                        <th><?php esc_html_e( 'Amount Saved', 'blt-events' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $usage_history as $usage ) : ?>
                        <tr>
                            <td><?php echo esc_html( wp_date( get_option( 'date_format' ), (int) ( $usage['date'] ?? 0 ) ) ); ?></td>
                            <td>
                                <?php if ( ! empty( $usage['registration_id'] ) ) : ?>
                                    <a href="<?php echo esc_url( (string) get_edit_post_link( (int) $usage['registration_id'] ) ); ?>">
                                        #<?php echo (int) $usage['registration_id']; ?>
                                    </a>
                                <?php else : ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $customer_name  = get_post_meta( $usage['registration_id'], '_blt_customer_name', true );
                                $customer_email = get_post_meta( $usage['registration_id'], '_blt_customer_email', true );
                                ?>
                                <?php echo esc_html( $customer_name ); ?>
                                <br />
                                <?php echo esc_html( $customer_email ); ?>
                            </td>
                            <td>$<?php echo number_format( (float) ( $usage['amount_saved'] ?? 0 ), 2 ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p><?php esc_html_e( 'This coupon has not been used yet.', 'blt-events' ); ?></p>
        <?php endif;
    }

    /**
     * Define custom columns for the coupons list table.
     *
     * @param array $columns Existing columns.
     * @return array Modified columns.
     */
    public static function set_custom_columns( $columns ) {
        $new_columns               = array();
        $new_columns['cb']         = $columns['cb'];
        $new_columns['title']      = __( 'Coupon Title', 'blt-events' );
        $new_columns['code']       = __( 'Code', 'blt-events' );
        $new_columns['discount']   = __( 'Discount', 'blt-events' );
        $new_columns['usage']      = __( 'Usage', 'blt-events' );
        $new_columns['expiration'] = __( 'Expiration', 'blt-events' );
        $new_columns['status']     = __( 'Status', 'blt-events' );
        $new_columns['date']       = $columns['date'];
        return $new_columns;
    }

    /**
     * Render content for custom columns in the coupons list table.
     *
     * @param string $column  The column name.
     * @param int    $post_id The post ID.
     */
    public static function custom_column( $column, $post_id ) {
        switch ( $column ) {
            case 'code':
                $code = get_post_meta( $post_id, '_blt_coupon_code', true );
                echo '<code>' . esc_html( $code ) . '</code>';
                break;

            case 'discount':
                $discount_type = get_post_meta( $post_id, '_blt_discount_type', true ) ?: 'fixed';
                $amount        = get_post_meta( $post_id, '_blt_amount', true ) ?: 0;

                if ( $discount_type === 'percentage' ) {
                    echo esc_html( $amount ) . '%';
                } else {
                    echo '$' . number_format( (float) $amount, 2 );
                }
                break;

            case 'usage':
                $total_uses  = get_post_meta( $post_id, '_blt_total_uses', true ) ?: 0;
                $usage_limit = get_post_meta( $post_id, '_blt_usage_limit', true );

                if ( ! empty( $usage_limit ) ) {
                    echo esc_html( $total_uses ) . ' / ' . esc_html( $usage_limit );
                } else {
                    echo esc_html( $total_uses ) . ' / &infin;';
                }
                break;

            case 'expiration':
                $expiration_date = get_post_meta( $post_id, '_blt_expiration_date', true );
                if ( ! empty( $expiration_date ) ) {
                    echo esc_html( $expiration_date );

                    // Check if expired (site timezone, matching validate_coupon).
                    $today = current_time( 'Y-m-d' );
                    if ( $expiration_date < $today ) {
                        echo ' <span class="expired">' . esc_html__( '(Expired)', 'blt-events' ) . '</span>';
                    }
                } else {
                    echo '&mdash;';
                }
                break;

            case 'status':
                $status       = get_post_meta( $post_id, '_blt_status', true ) ?: 'active';
                $status_class = 'status-' . $status;
                echo '<span class="' . esc_attr( $status_class ) . '">' . esc_html( ucfirst( $status ) ) . '</span>';
                break;
        }
    }

    /**
     * Define sortable columns for the coupons list table.
     *
     * @param array $columns Existing sortable columns.
     * @return array Modified sortable columns.
     */
    public static function sortable_columns( $columns ) {
        $columns['code']       = 'code';
        $columns['discount']   = 'discount';
        $columns['usage']      = 'usage';
        $columns['expiration'] = 'expiration';
        $columns['status']     = 'status';
        return $columns;
    }

    /**
     * Save coupon meta data when the post is saved.
     *
     * @param int $post_id The post ID being saved.
     */
    public static function save_post( $post_id ) {
        // Check if our nonce is set.
        if ( ! isset( $_POST['blt_coupon_details_nonce'] ) ) {
            return;
        }

        // Verify that the nonce is valid.
        if ( ! wp_verify_nonce( $_POST['blt_coupon_details_nonce'], 'blt_coupon_details' ) ) {
            return;
        }

        // If this is an autosave, our form has not been submitted, so we don't want to do anything.
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        // Check the user's permissions.
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Update the meta fields.
        if ( isset( $_POST['coupon_code'] ) ) {
            update_post_meta( $post_id, '_blt_coupon_code', sanitize_text_field( $_POST['coupon_code'] ) );
        }

        if ( isset( $_POST['discount_type'] ) ) {
            $discount_type = in_array( $_POST['discount_type'], array( 'fixed', 'percentage' ), true ) ? $_POST['discount_type'] : 'fixed';
            update_post_meta( $post_id, '_blt_discount_type', $discount_type );
        }

        if ( isset( $_POST['amount'] ) ) {
            $amount = max( 0, (float) $_POST['amount'] );
            if ( ( $_POST['discount_type'] ?? '' ) === 'percentage' ) {
                $amount = min( 100, $amount );
            }
            update_post_meta( $post_id, '_blt_amount', $amount );
        }

        if ( isset( $_POST['expiration_date'] ) ) {
            update_post_meta( $post_id, '_blt_expiration_date', sanitize_text_field( $_POST['expiration_date'] ) );
        }

        if ( isset( $_POST['usage_limit'] ) ) {
            update_post_meta( $post_id, '_blt_usage_limit', (int) $_POST['usage_limit'] );
        }

        if ( isset( $_POST['status'] ) ) {
            $status = in_array( $_POST['status'], array( 'active', 'inactive' ), true ) ? $_POST['status'] : 'active';
            update_post_meta( $post_id, '_blt_status', $status );
        }

        if ( isset( $_POST['applicable_events'] ) ) {
            // Only the literal 'all' sentinel or event post IDs are allowed.
            $applicable_events = array();
            foreach ( (array) $_POST['applicable_events'] as $value ) {
                if ( $value === 'all' ) {
                    $applicable_events[] = 'all';
                } elseif ( absint( $value ) > 0 ) {
                    $applicable_events[] = absint( $value );
                }
            }
            update_post_meta( $post_id, '_blt_applicable_events', $applicable_events );
        }
    }
}
