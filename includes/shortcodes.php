<?php
class Obie_Events_Shortcodes
{
	public static function init()
	{
		add_shortcode('Obie_Event_Registration', array(__CLASS__, 'registration_shortcode'));
		add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_scripts'));

		add_shortcode('Obie_Events_Calendar', array(__CLASS__, 'calendar_shortcode'));

		add_action('wp_ajax_obie_events_get_list', array(__CLASS__, 'ajax_get_events_list'));
		add_action('wp_ajax_nopriv_obie_events_get_list', array(__CLASS__, 'ajax_get_events_list'));
		add_action('wp_ajax_obie_events_get_month', array(__CLASS__, 'ajax_get_events_month'));
		add_action('wp_ajax_nopriv_obie_events_get_month', array(__CLASS__, 'ajax_get_events_month'));
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
			'events_per_page' => 5
		), $atts, 'obie_events_calendar');

		$view = sanitize_text_field($atts['view']);
		$events_per_page = intval($atts['events_per_page']);

		// Enqueue scripts específicos para el calendario
		wp_enqueue_script('obie-events-calendar', OBIE_EVENTS_PLUGIN_URL . 'assets/js/calendar.js', array('jquery'), '1.0', true);
		wp_localize_script('obie-events-calendar', 'obieCalendarData', array(
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('obie_events_calendar_nonce'),
			'eventsPerPage' => $events_per_page
		));

		ob_start(); ?>

		<div id="obie-events-calendar-container" class="obie-events-calendar">
			<!-- Vista Toggle -->
			<div class="calendar-view-toggle">
				<button type="button" class="view-toggle-btn <?php echo $view === 'list' ? 'active' : ''; ?>" data-view="list">
					List
				</button>
				<button type="button" class="view-toggle-btn <?php echo $view === 'month' ? 'active' : ''; ?>" data-view="month">
					Month
				</button>
			</div>

			<!-- Vista Lista -->
			<div id="calendar-list-view" class="calendar-view <?php echo $view === 'list' ? 'active' : ''; ?>">
				<div class="list-navigation">
					<button type="button" id="list-prev" class="nav-btn">« Previous</button>
					<span class="list-info">Upcoming events</span>
					<button type="button" id="list-next" class="nav-btn">Next »</button>
				</div>
				<div id="events-list-container" class="events-list">
					<div class="loading">Loading events...</div>
				</div>
			</div>

			<!-- Vista Mes -->
			<div id="calendar-month-view" class="calendar-view <?php echo $view === 'month' ? 'active' : ''; ?>">
				<div class="month-navigation">
					<button type="button" id="month-prev" class="nav-btn">« Previous</button>
					<span id="current-month" class="month-title"><?php echo date('F Y'); ?></span>
					<button type="button" id="month-next" class="nav-btn">Next »</button>
				</div>
				<div id="calendar-grid" class="calendar-grid">
					<div class="calendar-header">
						<div class="day-header">Sun</div>
						<div class="day-header">Mon</div>
						<div class="day-header">Tue</div>
						<div class="day-header">Wed</div>
						<div class="day-header">Thu</div>
						<div class="day-header">Fri</div>
						<div class="day-header">Sat</div>
					</div>
					<div id="calendar-days" class="calendar-days">
						<div class="loading">Loading calendar...</div>
					</div>
				</div>
			</div>
		</div>

		<style>
			/* Modern calendar container */
			.obie-events-calendar {
				max-width: 1000px;
				margin: 2rem auto;
				padding: 2rem;
				background: #ffffff;
				border-radius: 12px;
				box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
				font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
			}

			/* View toggle buttons */
			.calendar-view-toggle {
				text-align: center;
				margin-bottom: 2rem;
			}

			.view-toggle-btn {
				background: #f9fafb;
				border: 1px solid #e5e7eb;
				padding: 0.75rem 1.5rem;
				margin: 0 0.25rem;
				cursor: pointer;
				border-radius: 8px;
				font-size: 1rem;
				font-weight: 500;
				color: #374151;
				transition: all 0.2s ease;
			}

			.view-toggle-btn:hover {
				background: #f3f4f6;
				border-color: #d1d5db;
			}

			.view-toggle-btn.active {
				background: #6366f1;
				color: white;
				border-color: #6366f1;
				box-shadow: 0 2px 4px rgba(99, 102, 241, 0.2);
			}

			.calendar-view {
				display: none;
			}

			.calendar-view.active {
				display: block;
			}

			/* Navigation controls */
			.list-navigation,
			.month-navigation {
				display: flex;
				justify-content: space-between;
				align-items: center;
				margin-bottom: 2rem;
				padding: 0 1rem;
			}

			.nav-btn {
				background: #6366f1;
				color: white;
				border: none;
				padding: 0.75rem 1.5rem;
				border-radius: 8px;
				cursor: pointer;
				font-size: 1rem;
				font-weight: 500;
				transition: background-color 0.2s ease;
			}

			.nav-btn:hover {
				background: #4f46e5;
			}

			.nav-btn:focus {
				outline: none;
				box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.3);
			}

			.nav-btn:disabled {
				background: #9ca3af;
				cursor: not-allowed;
			}

			.list-info,
			.month-title {
				font-size: 1.5rem;
				font-weight: 600;
				color: #1f2937;
			}

			/* Events list */
			.events-list {
				min-height: 300px;
			}

			.event-item {
				border: 1px solid #e5e7eb;
				border-radius: 12px;
				padding: 1.5rem;
				margin-bottom: 1.5rem;
				background: #ffffff;
				box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
				transition: all 0.2s ease;
			}

			.event-item:hover {
				transform: translateY(-2px);
				box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
				border-color: #d1d5db;
			}

			.event-title {
				font-size: 1.25rem;
				font-weight: 600;
				color: #6366f1;
				margin-bottom: 0.75rem;
				line-height: 1.4;
			}

			.event-title a {
				text-decoration: none;
				color: inherit;
				transition: color 0.2s ease;
			}

			.event-title a:hover {
				color: #4f46e5;
			}

			.event-date {
				color: #4b5563;
				font-size: 1rem;
				font-weight: 500;
				margin-bottom: 0.5rem;
			}

			.event-time {
				color: #6b7280;
				font-size: 0.875rem;
				margin-bottom: 1rem;
			}

			.event-excerpt {
				color: #374151;
				line-height: 1.6;
				font-size: 0.95rem;
			}

			/* Month calendar grid */
			.calendar-grid {
				border: 1px solid #e5e7eb;
				border-radius: 12px;
				overflow: hidden;
				background: white;
				box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
			}

			.calendar-header {
				display: grid;
				grid-template-columns: repeat(7, 1fr);
				background: #f8fafc;
				border-bottom: 1px solid #e5e7eb;
			}

			.day-header {
				padding: 1rem;
				text-align: center;
				font-weight: 600;
				color: #374151;
				font-size: 0.875rem;
				text-transform: uppercase;
				letter-spacing: 0.05em;
				border-right: 1px solid #e5e7eb;
			}

			.day-header:last-child {
				border-right: none;
			}

			.calendar-days {
				display: grid;
				grid-template-columns: repeat(7, 1fr);
				min-height: 500px;
			}

			.calendar-day {
				border-right: 1px solid #f3f4f6;
				border-bottom: 1px solid #f3f4f6;
				min-height: 100px;
				padding: 0.75rem;
				position: relative;
				background: white;
				transition: background-color 0.2s ease;
			}

			.calendar-day:hover {
				background: #f9fafb;
			}

			.calendar-day:last-child {
				border-right: none;
			}

			.calendar-day.other-month {
				background: #f8fafc;
				color: #9ca3af;
			}

			.calendar-day.today {
				background: #eff6ff;
				border-color: #dbeafe;
			}

			.day-number {
				font-weight: 600;
				font-size: 1rem;
				color: #374151;
				margin-bottom: 0.5rem;
			}

			.calendar-day.today .day-number {
				color: #2563eb;
				background: #dbeafe;
				width: 28px;
				height: 28px;
				border-radius: 50%;
				display: flex;
				align-items: center;
				justify-content: center;
				font-weight: 700;
			}

			.day-events {
				font-size: 0.75rem;
			}

			.day-event {
				background: #6366f1;
				color: white;
				padding: 0.25rem 0.5rem;
				margin: 0.125rem 0;
				border-radius: 4px;
				cursor: pointer;
				overflow: hidden;
				text-overflow: ellipsis;
				white-space: nowrap;
				font-weight: 500;
				transition: background-color 0.2s ease;
			}

			.day-event:hover {
				background: #4f46e5;
			}

			/* Loading and empty states */
			.loading {
				text-align: center;
				padding: 3rem;
				color: #6b7280;
				font-size: 1.125rem;
			}

			.no-events {
				text-align: center;
				padding: 3rem;
				color: #9ca3af;
				font-style: italic;
				font-size: 1.125rem;
				background: #f9fafb;
				border-radius: 8px;
				margin: 1rem 0;
			}

			/* Responsive design */
			@media (max-width: 768px) {
				.obie-events-calendar {
					margin: 1rem;
					padding: 1.5rem;
				}

				.calendar-days {
					min-height: 400px;
				}

				.calendar-day {
					min-height: 80px;
					padding: 0.5rem;
				}

				.day-events {
					font-size: 0.6875rem;
				}

				.list-navigation,
				.month-navigation {
					flex-direction: column;
					gap: 1rem;
					text-align: center;
				}

				.nav-btn {
					padding: 0.875rem 2rem;
					font-size: 1rem;
				}

				.event-item {
					padding: 1.25rem;
				}

				.event-title {
					font-size: 1.125rem;
				}

				.list-info,
				.month-title {
					font-size: 1.25rem;
				}
			}

			@media (max-width: 480px) {
				.view-toggle-btn {
					display: block;
					margin: 0.25rem 0;
					width: 100%;
				}

				.calendar-day {
					min-height: 60px;
					padding: 0.25rem;
				}

				.day-number {
					font-size: 0.875rem;
				}

				.day-events {
					font-size: 0.625rem;
				}
			}
		</style>

		<script>
			jQuery(document).ready(function($) {
				var currentPage = 0;
				var currentMonth = new Date().getMonth();
				var currentYear = new Date().getFullYear();
				var currentView = '<?php echo $view; ?>';
				var isLoading = false;

				// Cambio de vista
				$('.view-toggle-btn').on('click', function() {
					var newView = $(this).data('view');
					if (newView === currentView) return;

					$('.view-toggle-btn').removeClass('active');
					$(this).addClass('active');
					$('.calendar-view').removeClass('active');
					$('#calendar-' + newView + '-view').addClass('active');

					currentView = newView;

					if (newView === 'list') {
						loadEventsList(0);
					} else {
						loadEventsMonth(currentMonth, currentYear);
					}
				});

				// Navegación lista
				$('#list-prev').on('click', function() {
					if (currentPage > 0) {
						currentPage--;
						loadEventsList(currentPage);
					}
				});

				$('#list-next').on('click', function() {
					currentPage++;
					loadEventsList(currentPage);
				});

				// Navegación mes
				$('#month-prev').on('click', function() {
					currentMonth--;
					if (currentMonth < 0) {
						currentMonth = 11;
						currentYear--;
					}
					loadEventsMonth(currentMonth, currentYear);
				});

				$('#month-next').on('click', function() {
					currentMonth++;
					if (currentMonth > 11) {
						currentMonth = 0;
						currentYear++;
					}
					loadEventsMonth(currentMonth, currentYear);
				});

				// Cargar eventos iniciales
				if (currentView === 'list') {
					loadEventsList(0);
				} else {
					loadEventsMonth(currentMonth, currentYear);
				}

				function loadEventsList(page) {
					if (isLoading) return;
					isLoading = true;

					$('#events-list-container').html('<div class="loading">Loading events...</div>');
					$('#list-prev').prop('disabled', true);
					$('#list-next').prop('disabled', true);

					$.ajax({
						url: obieCalendarData.ajaxUrl,
						type: 'POST',
						data: {
							action: 'obie_events_get_list',
							page: page,
							per_page: obieCalendarData.eventsPerPage,
							nonce: obieCalendarData.nonce
						},
						success: function(response) {
							if (response.success) {
								$('#events-list-container').html(response.data.html);
								$('#list-prev').prop('disabled', page === 0);
								$('#list-next').prop('disabled', !response.data.has_more);
							} else {
								$('#events-list-container').html('<div class="no-events">Error loading events</div>');
							}
						},
						error: function() {
							$('#events-list-container').html('<div class="no-events">Error loading events</div>');
						},
						complete: function() {
							isLoading = false;
						}
					});
				}

				function loadEventsMonth(month, year) {
					if (isLoading) return;
					isLoading = true;

					$('#calendar-days').html('<div class="loading">Loading calendar...</div>');

					var monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
						'July', 'August', 'September', 'October', 'November', 'December'
					];
					$('#current-month').text(monthNames[month] + ' ' + year);

					$.ajax({
						url: obieCalendarData.ajaxUrl,
						type: 'POST',
						data: {
							action: 'obie_events_get_month',
							month: month + 1,
							year: year,
							nonce: obieCalendarData.nonce
						},
						success: function(response) {
							if (response.success) {
								$('#calendar-days').html(response.data.html);
							} else {
								$('#calendar-days').html('<div class="no-events">Error loading calendar</div>');
							}
						},
						error: function() {
							$('#calendar-days').html('<div class="no-events">Error loading calendar</div>');
						},
						complete: function() {
							isLoading = false;
						}
					});
				}
			});
		</script>

		<?php
		return ob_get_clean();
	}

	public static function ajax_get_events_month()
	{
		if (!wp_verify_nonce($_POST['nonce'], 'obie_events_calendar_nonce')) {
			wp_die('Security check failed');
		}

		$month = intval($_POST['month']);
		$year = intval($_POST['year']);

		// Obtener eventos del mes
		$start_date = sprintf('%04d-%02d-01', $year, $month);
		$end_date = date('Y-m-t', strtotime($start_date));

		$args = array(
			'post_type' => 'event',
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'meta_key' => OBIE_EVENTS_PLUGIN_PREFIX . 'event_date',
			'orderby' => 'meta_value',
			'order' => 'ASC',
			'meta_query' => array(
				array(
					'key' => OBIE_EVENTS_PLUGIN_PREFIX . 'event_date',
					'value' => array($start_date, $end_date),
					'compare' => 'BETWEEN'
				)
			)
		);

		$events = get_posts($args);

		// Agrupar eventos por día
		$events_by_day = array();
		foreach ($events as $event) {
			$event_date = get_post_meta($event->ID, OBIE_EVENTS_PLUGIN_PREFIX . 'event_date', true);
			$day = date('j', strtotime($event_date));
			if (!isset($events_by_day[$day])) {
				$events_by_day[$day] = array();
			}
			$events_by_day[$day][] = $event;
		}

		// Generar calendario
		$first_day_of_month = mktime(0, 0, 0, $month, 1, $year);
		$days_in_month = date('t', $first_day_of_month);
		$first_day_of_week = date('w', $first_day_of_month);

		// Días del mes anterior para completar la primera semana
		$prev_month_days = $first_day_of_week;
		$prev_month = $month == 1 ? 12 : $month - 1;
		$prev_year = $month == 1 ? $year - 1 : $year;
		$prev_month_last_day = date('t', mktime(0, 0, 0, $prev_month, 1, $prev_year));

		ob_start();

		// Días del mes anterior
		for ($i = $prev_month_days - 1; $i >= 0; $i--) {
			$day = $prev_month_last_day - $i;
			echo '<div class="calendar-day other-month">';
			echo '<div class="day-number">' . $day . '</div>';
			echo '</div>';
		}

		// Días del mes actual
		$today = date('Y-m-d');
		for ($day = 1; $day <= $days_in_month; $day++) {
			$current_date = sprintf('%04d-%02d-%02d', $year, $month, $day);
			$is_today = ($current_date === $today);

			echo '<div class="calendar-day' . ($is_today ? ' today' : '') . '">';
			echo '<div class="day-number">' . $day . '</div>';

			if (isset($events_by_day[$day])) {
				echo '<div class="day-events">';
				foreach ($events_by_day[$day] as $event) {
					echo '<div class="day-event" title="' . esc_attr($event->post_title) . '">';
					echo esc_html(substr($event->post_title, 0, 20) . (strlen($event->post_title) > 20 ? '...' : ''));
					echo '</div>';
				}
				echo '</div>';
			}

			echo '</div>';
		}

		// Completar la última semana con días del mes siguiente
		$total_cells = ceil(($days_in_month + $first_day_of_week) / 7) * 7;
		$remaining_cells = $total_cells - ($days_in_month + $first_day_of_week);

		for ($day = 1; $day <= $remaining_cells; $day++) {
			echo '<div class="calendar-day other-month">';
			echo '<div class="day-number">' . $day . '</div>';
			echo '</div>';
		}

		$html = ob_get_clean();

		wp_send_json_success(array(
			'html' => $html
		));
	}

	public static function ajax_get_events_list()
	{
		if (!wp_verify_nonce($_POST['nonce'], 'obie_events_calendar_nonce')) {
			wp_die('Security check failed');
		}

		$page = intval($_POST['page']);
		$per_page = intval($_POST['per_page']) ?: 5;
		$offset = $page * $per_page;

		$args = array(
			'post_type' => 'event',
			'post_status' => 'publish',
			'posts_per_page' => $per_page,
			'offset' => $offset,
			'meta_key' => OBIE_EVENTS_PLUGIN_PREFIX . 'event_date',
			'orderby' => 'meta_value',
			'order' => 'ASC',
			'meta_query' => array(
				array(
					'key' => OBIE_EVENTS_PLUGIN_PREFIX . 'event_date',
					'value' => date('Y-m-d'),
					'compare' => '>='
				)
			)
		);

		$events = get_posts($args);

		// Verificar si hay más eventos
		$check_args = $args;
		$check_args['posts_per_page'] = 1;
		$check_args['offset'] = $offset + $per_page;
		$has_more = !empty(get_posts($check_args));

		ob_start();

		if (!empty($events)) {
			foreach ($events as $event) {
				$event_date = get_post_meta($event->ID, OBIE_EVENTS_PLUGIN_PREFIX . 'event_date', true);
				$event_start_time = get_post_meta($event->ID, OBIE_EVENTS_PLUGIN_PREFIX . 'event_start_time', true);
				$event_end_time = get_post_meta($event->ID, OBIE_EVENTS_PLUGIN_PREFIX . 'event_end_time', true);
				$event_all_day = get_post_meta($event->ID, OBIE_EVENTS_PLUGIN_PREFIX . 'event_all_day', true);
				$event_type = get_post_meta($event->ID, OBIE_EVENTS_PLUGIN_PREFIX . 'event_type', true);

				$formatted_date = date('l, j F Y', strtotime($event_date));
		?>
				<div class="event-item">
					<div class="event-title">
						<a href="<?php echo get_permalink($event->ID); ?>">
							<?php echo esc_html($event->post_title); ?>
						</a>
					</div>
					<div class="event-date"><?php echo $formatted_date; ?></div>
					<div class="event-type"><strong>Type:</strong> <?php echo $event_type === 'virtual' ? 'Virtual' : 'In Person'; ?></div>
					<?php if (!$event_all_day && ($event_start_time || $event_end_time)) : ?>
						<div class="event-time">
							<?php
							if ($event_start_time) echo date('g:i A', strtotime($event_start_time));
							if ($event_start_time && $event_end_time) echo ' - ';
							if ($event_end_time) echo date('g:i A', strtotime($event_end_time));
							?>
						</div>
					<?php endif; ?>
					<?php if ($event->post_excerpt) : ?>
						<div class="event-excerpt"><?php echo esc_html($event->post_excerpt); ?></div>
					<?php endif; ?>
				</div>
<?php
			}
		} else {
			echo '<div class="no-events">No upcoming events found</div>';
		}

		$html = ob_get_clean();

		wp_send_json_success(array(
			'html' => $html,
			'has_more' => $has_more
		));
	}
}
