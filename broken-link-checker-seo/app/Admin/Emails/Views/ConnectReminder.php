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

I noticed it\'s been more than a week since you installed Broken Link Checker on %2$s, but you haven\'t connected your free account yet.

I don\'t want you to miss out on the benefits of using Broken Link Checker and potentially being penalized by search engines for broken links.

You can connect your free account on your site here:
%3$s

If you have any questions or need help, just reply to this email.

Benjamin Rojas, President of AIOSEO', 'broken-link-checker-seo' ), // phpcs:ignore Generic.Files.LineLength.MaxExceeded
	$greeting, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	esc_html( $siteName ),
	esc_url( $settingsUrl )
);

// phpcs:enable