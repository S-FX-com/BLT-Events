<?php
/**
 * ZymEvents - Coupon Custom Post Type
 *
 * Registers the "cmt_coupon" custom post type with meta boxes,
 * custom admin columns, and save logic for coupon management.
 *
 * Coupon custom post type for ZymEvents.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ZymEvents_Coupon_CPT {

    /**
     * Post type slug.
     *
     * @var string
     */
    public static $slug = 'zymevents_coupon';

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
            'name'               => 'Coupons',
            'singular_name'      => 'Coupon',
            'menu_name'          => 'Coupons',
            'add_new'            => 'Add New',
            'add_new_item'       => 'Add New Coupon',
            'edit_item'          => 'Edit Coupon',
            'new_item'           => 'New Coupon',
            'view_item'          => 'View Coupon',
            'search_items'       => 'Search Coupons',
            'not_found'          => 'No coupons found',
            'not_found_in_trash' => 'No coupons found in Trash',
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
            'zymevents_coupon_details',
            'Coupon Details',
            array( __CLASS__, 'render_details_meta_box' ),
            self::$slug,
            'normal',
            'high'
        );

        add_meta_box(
            'zymevents_coupon_usage',
            'Usage Statistics',
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
        wp_nonce_field( 'zymevents_coupon_details', 'zymevents_coupon_details_nonce' );

        $code              = get_post_meta( $post->ID, '_zymevents_coupon_code', true );
        $discount_type     = get_post_meta( $post->ID, '_zymevents_discount_type', true ) ?: 'fixed';
        $amount            = get_post_meta( $post->ID, '_zymevents_amount', true ) ?: '';
        $expiration_date   = get_post_meta( $post->ID, '_zymevents_expiration_date', true ) ?: '';
        $usage_limit       = get_post_meta( $post->ID, '_zymevents_usage_limit', true ) ?: '';
        $status            = get_post_meta( $post->ID, '_zymevents_status', true ) ?: 'active';
        $applicable_events = get_post_meta( $post->ID, '_zymevents_applicable_events', true ) ?: array( 'all' );

        ?>
        <table class="form-table">
            <tr>
                <th><label for="coupon_code">Coupon Code</label></th>
                <td>
                    <input type="text" id="coupon_code" name="coupon_code" value="<?php echo esc_attr( $code ); ?>" class="regular-text" required>
                    <button type="button" class="button button-secondary" id="generate_coupon_code">Generate Code</button>
                </td>
            </tr>
            <tr>
                <th><label for="discount_type">Discount Type</label></th>
                <td>
                    <select id="discount_type" name="discount_type">
                        <option value="fixed" <?php selected( $discount_type, 'fixed' ); ?>>Fixed Amount ($)</option>
                        <option value="percentage" <?php selected( $discount_type, 'percentage' ); ?>>Percentage (%)</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="amount">Amount</label></th>
                <td>
                    <input type="number" id="amount" name="amount" value="<?php echo esc_attr( $amount ); ?>" step="0.01" min="0" class="regular-text" required>
                    <span id="amount_symbol"><?php echo $discount_type === 'percentage' ? '%' : '$'; ?></span>
                </td>
            </tr>
            <tr>
                <th><label for="expiration_date">Expiration Date</label></th>
                <td>
                    <input type="date" id="expiration_date" name="expiration_date" value="<?php echo esc_attr( $expiration_date ); ?>" class="regular-text">
                    <p class="description">Leave blank for no expiration</p>
                </td>
            </tr>
            <tr>
                <th><label for="usage_limit">Usage Limit</label></th>
                <td>
                    <input type="number" id="usage_limit" name="usage_limit" value="<?php echo esc_attr( $usage_limit ); ?>" min="0" class="regular-text">
                    <p class="description">Maximum number of times this coupon can be used. Leave blank for unlimited.</p>
                </td>
            </tr>
            <tr>
                <th><label for="status">Status</label></th>
                <td>
                    <select id="status" name="status">
                        <option value="active" <?php selected( $status, 'active' ); ?>>Active</option>
                        <option value="inactive" <?php selected( $status, 'inactive' ); ?>>Inactive</option>
                    </select>
                </td>
            </tr>
            <?php
            $events = array();

            $query = new WP_Query( array(
                'post_type'      => ZymEvents_Event_CPT::$slug,
                'posts_per_page' => -1,
                'post_status'    => 'publish',
            ) );
            if ( $query->have_posts() ) {
                while ( $query->have_posts() ) {
                    $query->the_post();
                    $event              = new stdClass();
                    $event->ID          = get_the_ID();
                    $event->post_title  = get_the_title();
                    $events[]           = $event;
                }
                wp_reset_postdata();
            }
            ?>
            <tr>
                <th><label for="applicable_events">Applicable Events</label></th>
                <td>
                    <select id="applicable_events" name="applicable_events[]" multiple style="width: 100%; min-height: 120px;">
                        <option value="all" <?php selected( in_array( 'all', $applicable_events ) || empty( $applicable_events ) ); ?>>All Events</option>
                        <?php foreach ( $events as $event ) : ?>
                            <option value="<?php echo esc_attr( $event->ID ); ?>" <?php selected( in_array( $event->ID, $applicable_events ) ); ?>>
                                <?php echo esc_html( $event->post_title ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">Select which events this coupon can be applied to. Select "All Events" for no restrictions.</p>
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
        $total_uses    = get_post_meta( $post->ID, '_zymevents_total_uses', true ) ?: 0;
        $total_savings = get_post_meta( $post->ID, '_zymevents_total_savings', true ) ?: 0;
        $last_used     = get_post_meta( $post->ID, '_zymevents_last_used', true ) ?: 0;
        $usage_history = get_post_meta( $post->ID, '_zymevents_usage_history', true ) ?: array();

        ?>
        <table class="form-table">
            <tr>
                <th><label>Total Uses</label></th>
                <td><?php echo intval( $total_uses ); ?></td>
            </tr>
            <tr>
                <th><label>Total Customer Savings</label></th>
                <td>$<?php echo number_format( (float) $total_savings, 2 ); ?></td>
            </tr>
            <tr>
                <th><label>Last Used</label></th>
                <td><?php echo $last_used ? date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_used ) : 'Never'; ?></td>
            </tr>
        </table>

        <?php if ( ! empty( $usage_history ) ) : ?>
            <h3>Usage History</h3>
            <table class="widefat fixed" style="width: 100%;">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Registration</th>
                        <th>Customer</th>
                        <th>Amount Saved</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $usage_history as $usage ) : ?>
                        <tr>
                            <td><?php echo date( get_option( 'date_format' ), $usage['date'] ); ?></td>
                            <td>
                                <?php if ( ! empty( $usage['registration_id'] ) ) : ?>
                                    <a href="<?php echo get_edit_post_link( $usage['registration_id'] ); ?>">
                                        #<?php echo $usage['registration_id']; ?>
                                    </a>
                                <?php else : ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $customer_name  = get_post_meta( $usage['registration_id'], '_zymevents_customer_name', true );
                                $customer_email = get_post_meta( $usage['registration_id'], '_zymevents_customer_email', true );
                                ?>
                                <?php echo esc_html( $customer_name ); ?>
                                <br />
                                <?php echo esc_html( $customer_email ); ?>
                            </td>
                            <td>$<?php echo number_format( $usage['amount_saved'], 2 ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p>This coupon has not been used yet.</p>
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
        $new_columns['title']      = __( 'Coupon Title' );
        $new_columns['code']       = __( 'Code' );
        $new_columns['discount']   = __( 'Discount' );
        $new_columns['usage']      = __( 'Usage' );
        $new_columns['expiration'] = __( 'Expiration' );
        $new_columns['status']     = __( 'Status' );
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
                $code = get_post_meta( $post_id, '_zymevents_coupon_code', true );
                echo '<code>' . esc_html( $code ) . '</code>';
                break;

            case 'discount':
                $discount_type = get_post_meta( $post_id, '_zymevents_discount_type', true ) ?: 'fixed';
                $amount        = get_post_meta( $post_id, '_zymevents_amount', true ) ?: 0;

                if ( $discount_type === 'percentage' ) {
                    echo esc_html( $amount ) . '%';
                } else {
                    echo '$' . number_format( (float) $amount, 2 );
                }
                break;

            case 'usage':
                $total_uses  = get_post_meta( $post_id, '_zymevents_total_uses', true ) ?: 0;
                $usage_limit = get_post_meta( $post_id, '_zymevents_usage_limit', true );

                if ( ! empty( $usage_limit ) ) {
                    echo esc_html( $total_uses ) . ' / ' . esc_html( $usage_limit );
                } else {
                    echo esc_html( $total_uses ) . ' / &infin;';
                }
                break;

            case 'expiration':
                $expiration_date = get_post_meta( $post_id, '_zymevents_expiration_date', true );
                if ( ! empty( $expiration_date ) ) {
                    echo esc_html( $expiration_date );

                    // Check if expired.
                    $today = date( 'Y-m-d' );
                    if ( $expiration_date < $today ) {
                        echo ' <span class="expired">(Expired)</span>';
                    }
                } else {
                    echo '&mdash;';
                }
                break;

            case 'status':
                $status       = get_post_meta( $post_id, '_zymevents_status', true ) ?: 'active';
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
        if ( ! isset( $_POST['zymevents_coupon_details_nonce'] ) ) {
            return;
        }

        // Verify that the nonce is valid.
        if ( ! wp_verify_nonce( $_POST['zymevents_coupon_details_nonce'], 'zymevents_coupon_details' ) ) {
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
            update_post_meta( $post_id, '_zymevents_coupon_code', sanitize_text_field( $_POST['coupon_code'] ) );
        }

        if ( isset( $_POST['discount_type'] ) ) {
            update_post_meta( $post_id, '_zymevents_discount_type', sanitize_text_field( $_POST['discount_type'] ) );
        }

        if ( isset( $_POST['amount'] ) ) {
            update_post_meta( $post_id, '_zymevents_amount', (float) $_POST['amount'] );
        }

        if ( isset( $_POST['expiration_date'] ) ) {
            update_post_meta( $post_id, '_zymevents_expiration_date', sanitize_text_field( $_POST['expiration_date'] ) );
        }

        if ( isset( $_POST['usage_limit'] ) ) {
            update_post_meta( $post_id, '_zymevents_usage_limit', (int) $_POST['usage_limit'] );
        }

        if ( isset( $_POST['status'] ) ) {
            update_post_meta( $post_id, '_zymevents_status', sanitize_text_field( $_POST['status'] ) );
        }

        if ( isset( $_POST['applicable_events'] ) ) {
            $applicable_events = array_map( 'sanitize_text_field', $_POST['applicable_events'] );
            update_post_meta( $post_id, '_zymevents_applicable_events', $applicable_events );
        }
    }
}
