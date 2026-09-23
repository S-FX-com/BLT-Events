<?php
/**
 * "Log in for Member Rates" prompt, shown to logged-out visitors when the
 * event has role-restricted ticket types on sale.
 *
 * Override: your-theme/blt-events/registration/member-login.php
 *
 * @var int    $event_id
 * @var string $login_url Login screen that returns to the registration form.
 *
 * @package BLT_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="blt-member-login" role="note">
	<span class="blt-member-login__icon"><?php echo BLT_Events_Templates::icon( 'lock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?></span>
	<div class="blt-member-login__text">
		<p class="blt-member-login__title"><?php esc_html_e( 'Member rates available', 'blt-events' ); ?></p>
		<p class="blt-member-login__desc"><?php esc_html_e( 'Members can register at a reduced rate. Log in to see the tickets available to you.', 'blt-events' ); ?></p>
	</div>
	<a class="blt-member-login__btn" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Log in for Member Rates', 'blt-events' ); ?></a>
</div>
