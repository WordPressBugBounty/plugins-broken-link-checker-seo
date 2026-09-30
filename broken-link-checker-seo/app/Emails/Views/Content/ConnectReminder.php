<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable	

$siteName    = get_bloginfo( 'name' );
$settingsUrl = admin_url( 'admin.php?page=broken-link-checker#/settings' );

// Get the recipient's first name.
$recipient = get_user_by( 'email', isset( $recipientEmail ) ? $recipientEmail : get_option( 'admin_email' ) );
$firstName = ( $recipient && ! empty( $recipient->first_name ) ) ? $recipient->first_name : '';

$greeting = ! empty( $firstName ) ? sprintf( 'Hi %s,', $firstName ) : 'Hi there,';

// Create a hyperlink for the settings URL.
$settingsLink = aioseoBrokenLinkChecker()->emails->renderLink(
	$settingsUrl,
	__( 'connect your free account here', 'broken-link-checker-seo' )
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

echo aioseoBrokenLinkChecker()->emails->renderParagraphs( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

// phpcs:enable