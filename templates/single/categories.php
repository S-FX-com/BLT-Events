<?php
/**
 * Event category chips above the title.
 *
 * Override: your-theme/blt-events/single/categories.php
 *
 * @var array $categories WP_Term[]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $categories ) ) {
	return;
}
?>
<ul class="blt-event__categories" role="list">
	<?php foreach ( $categories as $term ) : ?>
		<li class="blt-event__category-item">
			<a class="blt-event__category" href="<?php echo esc_url( (string) get_term_link( $term ) ); ?>"><?php echo esc_html( $term->name ); ?></a>
		</li>
	<?php endforeach; ?>
</ul>
