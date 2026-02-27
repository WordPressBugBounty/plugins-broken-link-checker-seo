<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Generate unsubscribe URL with a token for authentication.
$adminEmail = get_option( 'admin_email' );
$token      = wp_create_nonce( 'aioseo_blc_unsubscribe_' . $adminEmail );

$unsubscribeUrl = add_query_arg(
	[
		'aioseo_blc_action' => 'unsubscribe',
		'email'             => rawurlencode( $adminEmail ),
		'token'             => $token
	],
	home_url()
);

// Get the site domain for the footer message.
$siteUrl    = home_url();
$siteHost   = wp_parse_url( $siteUrl, PHP_URL_HOST );
$siteDomain = $siteHost ? $siteHost : $siteUrl;
?>
<div class="email-footer">
	<p>
		<?php
		// Translators: 1 - The site domain.
		echo esc_html( sprintf( __( 'This email was sent from your website %1$s.', 'broken-link-checker-seo' ), $siteDomain ) );
		?>
	</p>
	<p>
		<?php
			printf(
				wp_kses_post(
					// Translators: 1 - Unsubscribe URL.
					__( 'Don\'t want to receive these emails? <a href="%1$s">Unsubscribe</a>', 'broken-link-checker-seo' )
				),
				esc_url( $unsubscribeUrl )
			);
			?>
	</p>
</div>