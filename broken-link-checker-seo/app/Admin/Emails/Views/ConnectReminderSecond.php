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

echo sprintf( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	// Translators: 1 - The greeting, 2 - The site name, 3 - The settings URL.
	__( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		'%1$s

I noticed you still have not connected your account to Broken Link Checker on %2$s, so I just wanted to give you a friendly reminder.

Connecting your account takes just a minute and unlocks powerful features such as automatic broken link detection, actionable reports, link highlighting, and more.

Creating an account is completely free!

If you’re ready for more, you can go premium today. As a special offer just for you, purchase a subscription and your first month is on me. Use coupon code BLC1MONTHFREE during checkout.

Connect your account now: %3$s

P.S. Please don\'t share this coupon code with anyone else. It\'s exclusive to you.

Benjamin Rojas, President of AIOSEO', 'broken-link-checker-seo' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
	$greeting, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	esc_html( $siteName ),
	esc_url( $settingsUrl )
);

// phpcs:enable