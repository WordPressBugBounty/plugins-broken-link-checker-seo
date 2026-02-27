<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound	

$siteName    = get_bloginfo( 'name' );
$settingsUrl = admin_url( 'admin.php?page=broken-link-checker#/settings' );

// Get the admin user's first name.
$adminEmail = get_option( 'admin_email' );
$adminUser  = get_user_by( 'email', $adminEmail );
$firstName  = '';
if ( $adminUser && ! empty( $adminUser->first_name ) ) {
	$firstName = $adminUser->first_name;
}

$greeting = ! empty( $firstName ) ? sprintf( 'Hi %s,', $firstName ) : 'Hi there,';

$message = sprintf(
	// Translators: 1 - The greeting, 2 - The site name.
	__( '%1$s

I noticed you still have not connected your account to Broken Link Checker on %2$s, so I just wanted to give you a friendly reminder.

Connecting your account takes just a minute and unlocks powerful features such as automatic broken link detection, actionable reports, link highlighting, and more.

Creating an account is completely free!

If you\'re ready for more, you can go premium today. As a special offer just for you, purchase a subscription and your first month is on me. Use coupon code BLC1MONTHFREE during checkout.

P.S. Please don\'t share this coupon code with anyone else. It\'s exclusive to you.

Benjamin Rojas, President of AIOSEO', 'broken-link-checker-seo' ),
	esc_html( $greeting ),
	esc_html( $siteName )
);

// Convert line breaks to HTML paragraphs and escape content.
$paragraphs  = explode( "\n\n", $message );
$couponCode  = 'BLC1MONTHFREE';
$buttonAdded = false;

foreach ( $paragraphs as $paragraph ) {
	$paragraph = trim( $paragraph );
	if ( empty( $paragraph ) ) {
		continue;
	}

	// Convert URLs to links, then escape for safe output.
	$paragraph = make_clickable( $paragraph );

	// Highlight the coupon code with bold and blue color.
	$hasCoupon = strpos( $paragraph, $couponCode ) !== false;
	if ( $hasCoupon ) {
		$paragraph = str_replace(
			$couponCode,
			'<strong style="color: #2271b1;">' . esc_html( $couponCode ) . '</strong>',
			$paragraph
		);
	}

	echo '<p>' . wp_kses_post( $paragraph ) . '</p>';

	// Add the button after the paragraph containing the coupon code.
	if ( $hasCoupon && ! $buttonAdded ) {
		?>
		<div class="email-cta">
			<a href="<?php echo esc_url( $settingsUrl ); ?>" class="email-button">
				<?php echo esc_html__( 'Connect Your Account Now', 'broken-link-checker-seo' ); ?>
			</a>
		</div>
		<?php
		$buttonAdded = true;
	}
}

// phpcs:enable
