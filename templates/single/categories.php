<?php
/**
 * Event category chips.
 *
 * Override: your-theme/blt-events/single/categories.php
 *
 * @var WP_Term[] $categories
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $categories ) ) {
	return;
}
?>
<div class="blt-event__categories">
	<?php foreach ( $categories as $term ) : ?>
		<a class="blt-event__category" href="<?php echo esc_url( (string) get_term_link( $term ) ); ?>"><?php echo esc_html( $term->name ); ?></a>
	<?php endforeach; ?>
</div>
