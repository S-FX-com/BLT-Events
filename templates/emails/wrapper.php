<?php
/**
 * HTML shell around every email the plugin sends.
 *
 * Override: your-theme/blt-events/emails/wrapper.php
 *
 * @var string      $body         The message body (already run through wpautop).
 * @var string      $subject
 * @var string      $type         Email type (registration, pending, reminder_24h, ...).
 * @var object|null $registration Registration row when there is one.
 * @var string      $site_name
 * @var string      $site_url
 * @var string      $accent       Hex colour from Settings > Appearance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php echo esc_html( $subject ); ?></title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1f2937;">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 12px;">
		<tr>
			<td align="center">
				<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;">
					<tr>
						<td style="background:<?php echo esc_attr( $accent ); ?>;padding:18px 28px;color:#ffffff;font-size:18px;font-weight:700;">
							<a href="<?php echo esc_url( $site_url ); ?>" style="color:#ffffff;text-decoration:none;"><?php echo esc_html( $site_name ); ?></a>
						</td>
					</tr>
					<tr>
						<td style="padding:28px;font-size:15px;line-height:1.6;">
							<?php echo wp_kses_post( $body ); ?>
						</td>
					</tr>
					<tr>
						<td style="padding:16px 28px;border-top:1px solid #e5e7eb;font-size:12px;color:#6b7280;">
							<?php
							printf(
								/* translators: %s: site name (linked). */
								esc_html__( 'Sent by %s', 'blt-events' ),
								'<a href="' . esc_url( $site_url ) . '" style="color:#6b7280;">' . esc_html( $site_name ) . '</a>'
							);
							?>
						</td>
					</tr>
				</table>
			</td>
		</tr>
	</table>
</body>
</html>
