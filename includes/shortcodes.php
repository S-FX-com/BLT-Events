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
		), $atts, 'Obie_Events_Calendar');

		$view = sanitize_text_field($atts['view']);
		$events_per_page = intval($atts['events_per_page']);

		// Enqueue scripts específicos para el calendario
		wp_enqueue_script('obie-events-calendar', OBIE_EVENTS_PLUGIN_URL . 'assets/js/calendar.js', array('jquery'), '1.0', true);
		wp_localize_script('obie-events-calendar', 'obieCalendarData', array(
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('obie_events_calendar_nonce'),
			'eventsPerPage' => $events_per_page
		));

		// Obtener categorías y tipos de eventos para los filtros
		$categories = get_terms(array(
			'taxonomy' => 'event_category',
			'hide_empty' => false,
		));

		$event_types = array(
			'virtual' => 'Virtual',
			'in_person' => 'In Person',
			'hybrid' => 'Hybrid'
		);

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

			<!-- Filtros y Búsqueda (solo para vista lista) -->
			<div id="calendar-filters" class="calendar-filters <?php echo $view === 'list' ? 'active' : ''; ?>">
				<div class="filters-row">
					<!-- Barra de búsqueda -->
					<div class="search-container">
						<input type="text" id="event-search" placeholder="Search events..." class="search-input">
						<button type="button" id="clear-search" class="clear-search-btn">×</button>
					</div>

					<!-- Filtro por tipo de evento -->
					<div class="filter-group">
						<label for="event-type-filter">Event Type:</label>
						<select id="event-type-filter" class="filter-select">
							<option value="">All Types</option>
							<?php foreach ($event_types as $value => $label): ?>
								<option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<!-- Filtro por categoría -->
					<div class="filter-group">
						<label for="event-category-filter">Category:</label>
						<select id="event-category-filter" class="filter-select">
							<option value="">All Categories</option>
							<?php if (!empty($categories) && !is_wp_error($categories)): ?>
								<?php foreach ($categories as $category): ?>
									<option value="<?php echo esc_attr($category->term_id); ?>"><?php echo esc_html($category->name); ?></option>
								<?php endforeach; ?>
							<?php endif; ?>
						</select>
					</div>

					<!-- Botón para limpiar filtros -->
					<div class="filter-actions">
						<button type="button" id="clear-filters" class="clear-filters-btn">Clear Filters</button>
					</div>
				</div>
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

			/* Filtros y búsqueda */
			.calendar-filters {
				display: none;
				background: #f8fafc;
				border-radius: 12px;
				padding: 1.5rem;
				margin-bottom: 2rem;
				border: 1px solid #e5e7eb;
			}

			.calendar-filters.active {
				display: block;
			}

			.filters-row {
				display: grid;
				grid-template-columns: 2fr 1fr 1fr auto;
				gap: 1rem;
				align-items: end;
			}

			.search-container {
				position: relative;
			}

			.search-input {
				width: 100%;
				padding: 0.75rem 2.5rem 0.75rem 1rem;
				border: 1px solid #d1d5db;
				border-radius: 8px;
				font-size: 1rem;
				background: white;
				transition: border-color 0.2s ease, box-shadow 0.2s ease;
			}

			.search-input:focus {
				outline: none;
				border-color: #6366f1;
				box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
			}

			.clear-search-btn {
				position: absolute;
				right: 0.75rem;
				top: 50%;
				transform: translateY(-50%);
				background: none;
				border: none;
				font-size: 1.5rem;
				color: #9ca3af;
				cursor: pointer;
				padding: 0;
				width: 24px;
				height: 24px;
				display: flex;
				align-items: center;
				justify-content: center;
				border-radius: 50%;
				transition: all 0.2s ease;
			}

			.clear-search-btn:hover {
				background: #f3f4f6;
				color: #374151;
			}

			.filter-group {
				display: flex;
				flex-direction: column;
			}

			.filter-group label {
				font-size: 0.875rem;
				font-weight: 500;
				color: #374151;
				margin-bottom: 0.5rem;
			}

			.filter-select {
				padding: 0.75rem;
				border: 1px solid #d1d5db;
				border-radius: 8px;
				font-size: 1rem;
				background: white;
				color: #374151;
				cursor: pointer;
				transition: border-color 0.2s ease, box-shadow 0.2s ease;
			}

			.filter-select:focus {
				outline: none;
				border-color: #6366f1;
				box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
			}

			.filter-actions {
				display: flex;
				align-items: end;
			}

			.clear-filters-btn {
				background: #ef4444;
				color: white;
				border: none;
				padding: 0.75rem 1rem;
				border-radius: 8px;
				cursor: pointer;
				font-size: 0.875rem;
				font-weight: 500;
				transition: background-color 0.2s ease;
				white-space: nowrap;
			}

			.clear-filters-btn:hover {
				background: #dc2626;
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

			.event-meta {
				display: flex;
				gap: 1rem;
				margin-bottom: 1rem;
				flex-wrap: wrap;
			}

			.event-type-badge,
			.event-category-badge {
				display: inline-flex;
				align-items: center;
				padding: 0.25rem 0.75rem;
				border-radius: 20px;
				font-size: 0.75rem;
				font-weight: 500;
				text-transform: uppercase;
				letter-spacing: 0.05em;
			}

			.event-type-badge {
				background: #dbeafe;
				color: #1e40af;
			}

			.event-type-badge.virtual {
				background: #fef3c7;
				color: #92400e;
			}

			.event-type-badge.in_person {
				background: #d1fae5;
				color: #065f46;
			}

			.event-type-badge.hybrid {
				background: #e0e7ff;
				color: #3730a3;
			}

			.event-category-badge {
				background: #f3f4f6;
				color: #374151;
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

			.day-event.virtual {
				background: #f59e0b;
			}

			.day-event.in_person {
				background: #10b981;
			}

			.day-event.hybrid {
				background: #8b5cf6;
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

				.filters-row {
					grid-template-columns: 1fr;
					gap: 1rem;
				}

				.filter-group {
					margin-bottom: 0.5rem;
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

				.event-meta {
					gap: 0.5rem;
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

				.filters-row {
					grid-template-columns: 1fr;
				}

				.search-container {
					margin-bottom: 1rem;
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
				var searchTimeout;

				// Variables para filtros
				var currentSearch = '';
				var currentEventType = '';
				var currentCategory = '';

				// Cambio de vista
				$('.view-toggle-btn').on('click', function() {
					var newView = $(this).data('view');
					if (newView === currentView) return;

					$('.view-toggle-btn').removeClass('active');
					$(this).addClass('active');
					$('.calendar-view').removeClass('active');
					$('#calendar-' + newView + '-view').addClass('active');

					// Mostrar/ocultar filtros
					if (newView === 'list') {
						$('.calendar-filters').addClass('active');
					} else {
						$('.calendar-filters').removeClass('active');
					}

					currentView = newView;

					if (newView === 'list') {
						currentPage = 0;
						loadEventsList(0);
					} else {
						loadEventsMonth(currentMonth, currentYear);
					}
				});

				// Búsqueda en tiempo real
				$('#event-search').on('input', function() {
					var searchTerm = $(this).val().trim();
					currentSearch = searchTerm;

					// Mostrar/ocultar botón de limpiar búsqueda
					if (searchTerm) {
						$('#clear-search').show();
					} else {
						$('#clear-search').hide();
					}

					// Debounce para evitar demasiadas peticiones
					clearTimeout(searchTimeout);
					searchTimeout = setTimeout(function() {
						currentPage = 0;
						loadEventsList(0);
					}, 300);
				});

				// Limpiar búsqueda
				$('#clear-search').on('click', function() {
					$('#event-search').val('');
					currentSearch = '';
					$(this).hide();
					currentPage = 0;
					loadEventsList(0);
				});

				// Filtros
				$('#event-type-filter, #event-category-filter').on('change', function() {
					currentEventType = $('#event-type-filter').val();
					currentCategory = $('#event-category-filter').val();
					currentPage = 0;
					loadEventsList(0);
				});

				// Limpiar todos los filtros
				$('#clear-filters').on('click', function() {
					$('#event-search').val('');
					$('#event-type-filter').val('');
					$('#event-category-filter').val('');
					$('#clear-search').hide();

					currentSearch = '';
					currentEventType = '';
					currentCategory = '';
					currentPage = 0;

					loadEventsList(0);
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
							search: currentSearch,
							event_type: currentEventType,
							category: currentCategory,
							nonce: obieCalendarData.nonce
						},
						success: function(response) {
							if (response.success) {
								$('#events-list-container').html(response.data.html);
								$('#list-prev').prop('disabled', page === 0);
								$('#list-next').prop('disabled', !response.data.has_more);
							} else {
								$('#events-list-container').html('<div class="no-events">No events found</div>');
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

<?php return ob_get_clean();
	}

	// Función AJAX para obtener eventos en vista mensual
	public static function ajax_get_events_month()
	{
		check_ajax_referer('obie_events_calendar_nonce', 'nonce');

		$month = intval($_POST['month']);
		$year = intval($_POST['year']);

		// Crear fechas de inicio y fin del mes
		$start_date = sprintf('%04d-%02d-01', $year, $month);
		$end_date = date('Y-m-t', strtotime($start_date));

		// Obtener eventos del mes
		$args = array(
			'post_type' => 'event',
			'posts_per_page' => -1,
			'post_status' => 'publish',
			'meta_query' => array(
				array(
					'key' => OBIE_EVENTS_PLUGIN_PREFIX . 'event_date',
					'value' => array($start_date, $end_date),
					'compare' => 'BETWEEN',
					'type' => 'DATE'
				)
			),
			'orderby' => 'meta_value',
			'meta_key' => OBIE_EVENTS_PLUGIN_PREFIX . 'event_date',
			'order' => 'ASC'
		);

		$events = new WP_Query($args);

		// Organizar eventos por fecha
		$events_by_date = array();
		if ($events->have_posts()) {
			while ($events->have_posts()) {
				$events->the_post();
				$event_id = get_the_ID();
				$event_date = get_post_meta($event_id, OBIE_EVENTS_PLUGIN_PREFIX . 'event_date', true);
				$event_type = get_post_meta($event_id, OBIE_EVENTS_PLUGIN_PREFIX . 'event_type', true);

				if (!isset($events_by_date[$event_date])) {
					$events_by_date[$event_date] = array();
				}

				$events_by_date[$event_date][] = array(
					'title' => get_the_title(),
					'url' => get_permalink(),
					'type' => $event_type
				);
			}
		}
		wp_reset_postdata();

		// Generar calendario
		$html = '';

		// Obtener el primer día del mes y cuántos días tiene
		$first_day = date('w', strtotime($start_date)); // 0 = domingo
		$days_in_month = date('t', strtotime($start_date));

		// Días del mes anterior para completar la primera semana
		$prev_month = $month - 1;
		$prev_year = $year;
		if ($prev_month < 1) {
			$prev_month = 12;
			$prev_year--;
		}
		$days_in_prev_month = date('t', strtotime("$prev_year-$prev_month-01"));

		$day_counter = 1;
		$next_month_day = 1;

		// Generar 6 semanas (42 días) para asegurar que el calendario esté completo
		for ($i = 0; $i < 42; $i++) {
			$day_class = 'calendar-day';
			$day_number = '';
			$current_date = '';

			if ($i < $first_day) {
				// Días del mes anterior
				$day_number = $days_in_prev_month - $first_day + $i + 1;
				$day_class .= ' other-month';
				$prev_month_padded = sprintf('%02d', $prev_month);
				$current_date = "$prev_year-$prev_month_padded-" . sprintf('%02d', $day_number);
			} elseif ($day_counter <= $days_in_month) {
				// Días del mes actual
				$day_number = $day_counter;
				$current_date = sprintf('%04d-%02d-%02d', $year, $month, $day_counter);

				// Verificar si es hoy
				if ($current_date === current_time('Y-m-d')) {
					$day_class .= ' today';
				}

				$day_counter++;
			} else {
				// Días del mes siguiente
				$day_number = $next_month_day;
				$day_class .= ' other-month';
				$next_month = $month + 1;
				$next_year = $year;
				if ($next_month > 12) {
					$next_month = 1;
					$next_year++;
				}
				$current_date = sprintf('%04d-%02d-%02d', $next_year, $next_month, $next_month_day);
				$next_month_day++;
			}

			$html .= '<div class="' . $day_class . '" data-date="' . $current_date . '">';
			$html .= '<div class="day-number">' . $day_number . '</div>';

			// Agregar eventos del día
			if (isset($events_by_date[$current_date])) {
				$html .= '<div class="day-events">';
				foreach ($events_by_date[$current_date] as $event) {
					$event_class = 'day-event';
					if ($event['type']) {
						$event_class .= ' ' . esc_attr($event['type']);
					}
					$html .= '<div class="' . $event_class . '" title="' . esc_attr($event['title']) . '">';
					$html .= '<a href="' . esc_url($event['url']) . '">' . esc_html(wp_trim_words($event['title'], 3)) . '</a>';
					$html .= '</div>';
				}
				$html .= '</div>';
			}

			$html .= '</div>';

			// Si hemos completado una semana y ya pasamos todos los días del mes, podemos parar
			if ($i % 7 === 6 && $day_counter > $days_in_month && $next_month_day > 7) {
				break;
			}
		}

		wp_send_json_success(array(
			'html' => $html
		));
	}

	// Función AJAX para obtener eventos en vista lista con filtros
	public static function ajax_get_events_list()
	{
		check_ajax_referer('obie_events_calendar_nonce', 'nonce');

		$page = intval($_POST['page']);
		$per_page = intval($_POST['per_page']);
		$search = sanitize_text_field($_POST['search'] ?? '');
		$event_type = sanitize_text_field($_POST['event_type'] ?? '');
		$category = intval($_POST['category'] ?? 0);

		$args = array(
			'post_type' => 'event',
			'posts_per_page' => $per_page,
			'offset' => $page * $per_page,
			'post_status' => 'publish',
			'orderby' => 'meta_value',
			'meta_key' => OBIE_EVENTS_PLUGIN_PREFIX . 'event_date',
			'order' => 'ASC',
			'meta_query' => array(
				array(
					'key' => OBIE_EVENTS_PLUGIN_PREFIX . 'event_date',
					'value' => current_time('Y-m-d'),
					'compare' => '>='
				)
			),
			'tax_query' => array()
		);

		// Agregar búsqueda por título y contenido
		if (!empty($search)) {
			$args['s'] = $search;
		}

		// Filtro por tipo de evento
		if (!empty($event_type)) {
			$args['meta_query'][] = array(
				'key' => OBIE_EVENTS_PLUGIN_PREFIX . 'event_type',
				'value' => $event_type,
				'compare' => '='
			);
		}

		// Filtro por categoría
		if (!empty($category)) {
			$args['tax_query'][] = array(
				'taxonomy' => 'event_category',
				'field' => 'term_id',
				'terms' => $category
			);
		}

		$events = new WP_Query($args);

		$html = '';

		if ($events->have_posts()) {
			while ($events->have_posts()) {
				$events->the_post();
				$event_id = get_the_ID();
				$event_date = get_post_meta($event_id, OBIE_EVENTS_PLUGIN_PREFIX . 'event_date', true);
				$event_time = get_post_meta($event_id, OBIE_EVENTS_PLUGIN_PREFIX . 'event_time', true);
				$event_type = get_post_meta($event_id, OBIE_EVENTS_PLUGIN_PREFIX . 'event_type', true);
				$categories = get_the_terms($event_id, 'event_category');

				// Formatear fecha
				$formatted_date = '';
				if ($event_date) {
					$date_obj = DateTime::createFromFormat('Y-m-d', $event_date);
					if ($date_obj) {
						$formatted_date = $date_obj->format('F j, Y');
					}
				}

				// Formatear hora
				$formatted_time = '';
				if ($event_time) {
					$time_obj = DateTime::createFromFormat('H:i', $event_time);
					if ($time_obj) {
						$formatted_time = $time_obj->format('g:i A');
					}
				}

				// Obtener extracto
				$excerpt = get_the_excerpt();
				if (empty($excerpt)) {
					$excerpt = wp_trim_words(get_the_content(), 20);
				}

				$html .= '<div class="event-item">';

				// Meta información (badges)
				$html .= '<div class="event-meta">';

				// Badge de tipo de evento
				if ($event_type) {
					$type_labels = array(
						'virtual' => 'Virtual',
						'in_person' => 'In Person',
						'hybrid' => 'Hybrid'
					);
					$type_label = isset($type_labels[$event_type]) ? $type_labels[$event_type] : ucfirst($event_type);
					$html .= '<span class="event-type-badge ' . esc_attr($event_type) . '">' . esc_html($type_label) . '</span>';
				}

				// Badge de categoría
				if ($categories && !is_wp_error($categories)) {
					foreach ($categories as $category) {
						$html .= '<span class="event-category-badge">' . esc_html($category->name) . '</span>';
						break; // Solo mostrar la primera categoría
					}
				}

				$html .= '</div>';

				// Título del evento
				$html .= '<h3 class="event-title"><a href="' . get_permalink() . '">' . get_the_title() . '</a></h3>';

				// Fecha y hora
				if ($formatted_date) {
					$html .= '<div class="event-date">' . esc_html($formatted_date) . '</div>';
				}
				if ($formatted_time) {
					$html .= '<div class="event-time">' . esc_html($formatted_time) . '</div>';
				}

				// Extracto
				if ($excerpt) {
					$html .= '<div class="event-excerpt">' . wp_kses_post($excerpt) . '</div>';
				}

				$html .= '</div>';
			}
		} else {
			$html = '<div class="no-events">No events found matching your criteria.</div>';
		}

		wp_reset_postdata();

		// Verificar si hay más eventos
		$total_events = $events->found_posts;
		$has_more = ($page + 1) * $per_page < $total_events;

		wp_send_json_success(array(
			'html' => $html,
			'has_more' => $has_more,
			'total' => $total_events
		));
	}
}
