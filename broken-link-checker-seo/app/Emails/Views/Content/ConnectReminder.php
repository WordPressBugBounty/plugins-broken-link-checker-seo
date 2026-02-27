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

// Create a hyperlink for the settings URL.
$settingsLink = sprintf(
	'<a href="%1$s">%2$s</a>',
	esc_url( $settingsUrl ),
	esc_html__( 'connect your free account here', 'broken-link-checker-seo' )
);

$message = sprintf(
	// Translators: 1 - The greeting, 2 - The site name, 3 - The settings link HTML.
	__( '%1$s

I noticed it\'s been more than a week since you installed Broken Link Checker on %2$s, but you haven\'t connected your free account yet.

I don\'t want you to miss out on the benefits of using Broken Link Checker and potentially being penalized by search engines for broken links.

You can %3$s on your site.

If you have any questions or need help, just reply to this email.

Benjamin Rojas, President of AIOSEO', 'broken-link-checker-seo' ),
	esc_html( $greeting ),
	esc_html( $siteName ),
	$settingsLink
);

// Convert line breaks to HTML paragraphs and escape content.
$paragraphs = explode( "\n\n", $message );
foreach ( $paragraphs as $paragraph ) {
	$paragraph = trim( $paragraph );
	if ( empty( $paragraph ) ) {
		continue;
	}

	// Convert URLs to links, then escape for safe output.
	$paragraph = make_clickable( $paragraph );
	echo '<p>' . wp_kses_post( $paragraph ) . '</p>';
}

// phpcs:enable
