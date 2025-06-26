<?php
class Obie_Events_Shortcodes
{
	public static function init()
	{
		add_shortcode('Obie_Event_Registration', array(__CLASS__, 'registration_shortcode'));
		add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_scripts'));
		add_shortcode('obie_events_calendar', array(__CLASS__, 'calendar_shortcode'));
	}

	public static function enqueue_scripts()
	{
		wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', array(), null, true);
		wp_enqueue_script('obie-events-payment', OBIE_EVENTS_PLUGIN_URL . 'assets/js/payment.js', array('jquery', 'obie-events-js', 'stripe-js'), '1.0', true);

		wp_localize_script('obie-events-payment', 'obieEventPaymentData', array(
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'stripeKey' => get_option('obie_events_stripe_publishable_key')
		));
	}

	public static function registration_shortcode($atts)
	{
		$atts = shortcode_atts(array(
			'id' => get_the_ID(),
		), $atts, 'Obie_Event_Registration');

		$event_id = intval($atts['id']);
		$by_tickets = get_post_meta($event_id, OBIE_EVENTS_PLUGIN_PREFIX . 'event_by_tickets', true);
		$ticket_types = get_post_meta($event_id, OBIE_EVENTS_PLUGIN_PREFIX . 'event_ticket_types', true);

		// FILTRO de tickets por rol y expiración
		$user = wp_get_current_user();
		$user_roles = (array) $user->roles;
		$is_logged_in = is_user_logged_in();
		$today = date('Y-m-d');
		$filtered_tickets = array();
		if (!empty($ticket_types)) {
			foreach ($ticket_types as $ticket) {
				// Filtrar por roles
				$roles_ok = true;
				if (!empty($ticket['roles'])) {
					$roles_ok = false;
					if ($is_logged_in) {
						foreach ($user_roles as $role) {
							if (in_array($role, $ticket['roles'])) {
								$roles_ok = true;
								break;
							}
						}
					}
				}
				// Si no está logueado y el ticket tiene roles, no mostrar
				if (!$is_logged_in && !empty($ticket['roles'])) {
					$roles_ok = false;
				}
				// Filtrar por expiración
				$expiration_ok = true;
				if (!empty($ticket['expiration'])) {
					$expiration_ok = ($ticket['expiration'] >= $today);
				}
				if ($roles_ok && $expiration_ok) {
					$filtered_tickets[] = $ticket;
				}
			}
		}

		ob_start(); ?>
		<form id="obie-events-registration-form" class="obie-events-registration-form">
			<input type="hidden" name="event_id" value="<?php echo $event_id; ?>" />
			<?php wp_nonce_field(Obie_Events_Registrations::$nonce, Obie_Events_Registrations::$nonce); ?>

			<div class="form-row">
				<label for="customer_name">Name</label>
				<input type="text" id="customer_name" name="customer_name" required />
			</div>

			<div class="form-row">
				<label for="customer_email">Email</label>
				<input type="email" id="customer_email" name="customer_email" required />
			</div>

			<?php if ($by_tickets) : ?>
				<?php if (!empty($filtered_tickets)) : ?>
					<div class="ticket-selection">
						<h4>Select Tickets</h4>
						<?php foreach ($filtered_tickets as $index => $ticket) : ?>
							<div class="ticket-type">
								<span class="ticket-name"><?php echo esc_html($ticket['name']); ?></span>
								<div class="ticket-quantity-controls">
									<button type="button" class="quantity-btn minus-btn" data-index="<?php echo $index; ?>">-</button>
									<input type="number"
										name="ticket_quantity[]"
										data-index="<?php echo $index; ?>"
										data-price="<?php echo $ticket['price']; ?>"
										min="0"
										value="0"
										class="ticket-quantity"
										readonly />
									<button type="button" class="quantity-btn plus-btn" data-index="<?php echo $index; ?>">+</button>
								</div>
								<span class="ticket-price"><?php echo Obie_Events_Helper::format_price($ticket['price']); ?></span>
							</div>
						<?php endforeach; ?>

						<div class="coupon-section">
							<?php wp_nonce_field(Obie_Events_Coupons::$nonce, Obie_Events_Coupons::$nonce); ?>
							<div id="coupon-form" class="form-row coupon-row">
								<label for="coupon_code"><?php _e('Coupon Code', OBIE_EVENTS_PLUGIN_PATH); ?></label>
								<div class="coupon-input-group">
									<input type="text" id="coupon_code" name="coupon_code" placeholder="<?php _e('Enter coupon code', OBIE_EVENTS_PLUGIN_PATH); ?>" />
									<button type="button" id="obie-events-apply-coupon" class="coupon-button"><?php _e('Apply', OBIE_EVENTS_PLUGIN_PATH); ?></button>
								</div>
							</div>
							<div id="coupon-discount" class="coupon-discount" style="display: none;">
								<input type="hidden" name="applied_coupon" id="applied_coupon" value="" />
								<span class="coupon-discount-amount"></span>
								<button type="button" id="obie-events-remove-coupon" class="remove-coupon"><?php _e('Remove', OBIE_EVENTS_PLUGIN_PATH); ?></button>
							</div>
							<div id="coupon-message"></div>
						</div>

						<p class="total-amount">
							<?php echo Obie_Events_Helper::format_price(0, true); ?>
						</p>
					</div>

					<div id="card-element"></div>
					<div id="card-errors" role="alert"></div>
				<?php endif; ?>
			<?php endif; ?>

			<button type="submit" id="obie-events-reserve-button" class="submit-button">
				Reserve now
			</button>
		</form>
<?php return ob_get_clean();
	}

	public static function calendar_shortcode($atts)
	{
		$atts = shortcode_atts(array(
			'view' => 'list', // 'list' o 'month'
			'show_past' => false,
		), $atts, 'obie_events_calendar');

		$today = date('Y-m-d');
		$args = array(
			'post_type' => Obie_Events_CPT::$slug,
			'posts_per_page' => -1,
			'meta_key' => OBIE_EVENTS_PLUGIN_PREFIX . 'event_date',
			'orderby' => 'meta_value',
			'order' => 'ASC',
		);
		$query = new WP_Query($args);
		$events = array();
		if ($query->have_posts()) {
			while ($query->have_posts()) {
				$query->the_post();
				$event_date = get_post_meta(get_the_ID(), OBIE_EVENTS_PLUGIN_PREFIX . 'event_date', true);
				$event_start_time = get_post_meta(get_the_ID(), OBIE_EVENTS_PLUGIN_PREFIX . 'event_start_time', true);
				$event_end_time = get_post_meta(get_the_ID(), OBIE_EVENTS_PLUGIN_PREFIX . 'event_end_time', true);
				$event_all_day = get_post_meta(get_the_ID(), OBIE_EVENTS_PLUGIN_PREFIX . 'event_all_day', true);
				if (!$atts['show_past'] && $event_date < $today) continue;
				if ($atts['show_past'] && $event_date >= $today) continue;
				$events[] = array(
					'ID' => get_the_ID(),
					'title' => get_the_title(),
					'permalink' => get_permalink(),
					'date' => $event_date,
					'start_time' => $event_start_time,
					'end_time' => $event_end_time,
					'all_day' => $event_all_day,
				);
			}
			wp_reset_postdata();
		}
		ob_start();
		$current_view = $atts['view'] === 'month' ? 'month' : 'list';
		echo '<div class="obie-events-calendar-wrapper">';
		echo '<div class="obie-events-calendar-switch" style="margin-bottom:10px;">';
		echo '<button type="button" class="' . ($current_view == 'list' ? 'active' : '') . '" data-view="list">List</button> ';
		echo '<button type="button" class="' . ($current_view == 'month' ? 'active' : '') . '" data-view="month">Month</button>';
		echo '</div>';
		// Agrupar eventos por mes para la vista de lista
		$events_by_month = array();
		foreach ($events as $event) {
			$month_label = date_i18n('F Y', strtotime($event['date']));
			if (!isset($events_by_month[$month_label])) {
				$events_by_month[$month_label] = array();
			}
			$events_by_month[$month_label][] = $event;
		}
		// Vista de lista
		echo '<div class="obie-events-calendar-view" data-view="list" style="' . ($current_view == 'list' ? '' : 'display:none;') . '">';
		foreach ($events_by_month as $month_label => $month_events) {
			echo '<h3 class="obie-events-list-month">' . esc_html($month_label) . '</h3>';
			echo '<ul class="obie-events-list">';
			foreach ($month_events as $event) {
				echo '<li class="obie-event-list-item">';
				echo '<div class="obie-event-list-date">';
				echo '<span class="obie-event-list-day">' . date_i18n('D', strtotime($event['date'])) . '</span>';
				echo '<span class="obie-event-list-num">' . date_i18n('j', strtotime($event['date'])) . '</span>';
				echo '</div>';
				echo '<div class="obie-event-list-main">';
				// Horario
				echo '<div class="obie-event-list-time">';
				if ($event['all_day'] == '1') {
					echo esc_html__('All day', 'obie-events');
				} else if ($event['start_time'] || $event['end_time']) {
					echo esc_html($event['date']);
					if ($event['start_time']) {
						echo ' @ ' . esc_html($event['start_time']);
					}
					if ($event['end_time']) {
						echo ' - ' . esc_html($event['end_time']);
					}
				}
				echo '</div>';
				// Título
				echo '<div class="obie-event-list-title"><a href="' . esc_url($event['permalink']) . '"><strong>' . esc_html($event['title']) . '</strong></a></div>';
				// Dirección/lugar (si existe en un custom field 'event_location')
				$location = get_post_meta($event['ID'], OBIE_EVENTS_PLUGIN_PREFIX . 'event_location', true);
				if ($location) {
					echo '<div class="obie-event-list-location">' . esc_html($location) . '</div>';
				}
				// Excerpt
				$post_obj = get_post($event['ID']);
				$excerpt = $post_obj->post_excerpt ? $post_obj->post_excerpt : wp_trim_words($post_obj->post_content, 30);
				echo '<div class="obie-event-list-excerpt">' . esc_html($excerpt) . '</div>';
				// RSVP
				echo '<div class="obie-event-list-rsvp"><a href="' . esc_url($event['permalink']) . '">' . esc_html__('RSVP Now', 'obie-events') . '</a></div>';
				echo '</div>';
				// Imagen destacada
				if (has_post_thumbnail($event['ID'])) {
					echo '<div class="obie-event-list-thumb">' . get_the_post_thumbnail($event['ID'], 'medium') . '</div>';
				}
				echo '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
		// Vista de calendario mensual
		echo '<div class="obie-events-calendar-view" data-view="month" style="' . ($current_view == 'month' ? '' : 'display:none;') . '">';
		echo '<div class="obie-events-calendar-month">';
		$month = date('m');
		$year = date('Y');
		$days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
		echo '<table class="calendar-table"><thead><tr>';
		foreach (["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"] as $day) {
			echo '<th>' . $day . '</th>';
		}
		echo '</tr></thead><tbody><tr>';
		$first_day_of_month = date('w', strtotime("$year-$month-01"));
		for ($i = 0; $i < $first_day_of_month; $i++) {
			echo '<td></td>';
		}
		$day_counter = $first_day_of_month;
		for ($day = 1; $day <= $days_in_month; $day++) {
			$current_date = sprintf('%04d-%02d-%02d', $year, $month, $day);
			$event_blocks = array();
			foreach ($events as $event) {
				if ($event['date'] === $current_date) {
					$block = '<div class="calendar-events">';
					$block .= '<a href="' . esc_url($event['permalink']) . '"><strong>' . esc_html($event['title']) . '</strong></a>';
					if ($event['all_day'] == '1') {
						$block .= ' (' . esc_html__('All day', 'obie-events') . ')';
					} else if ($event['start_time'] || $event['end_time']) {
						$block .= ' ' . esc_html($event['start_time']);
						if ($event['end_time']) {
							$block .= ' - ' . esc_html($event['end_time']);
						}
					}
					$block .= '</div>';
					$event_blocks[] = $block;
				}
			}
			echo '<td>' . $day;
			if (!empty($event_blocks)) {
				echo implode('', $event_blocks);
			}
			echo '</td>';
			$day_counter++;
			if ($day_counter % 7 == 0) echo '</tr><tr>';
		}
		while ($day_counter % 7 != 0) {
			echo '<td></td>';
			$day_counter++;
		}
		echo '</tr></tbody></table>';
		echo '</div>';
		echo '</div>';
		echo '</div>';
		return ob_get_clean();
	}
}
