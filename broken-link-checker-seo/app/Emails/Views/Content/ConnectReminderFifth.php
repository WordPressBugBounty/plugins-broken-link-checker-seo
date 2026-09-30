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

$settingsLink = aioseoBrokenLinkChecker()->emails->renderLink(
	$settingsUrl,
	__( 'connect your account', 'broken-link-checker-seo' )
);

$message = sprintf(
	// Translators: 1 - The greeting, 2 - The site name, 3 - The settings link HTML.
	__( '%1$s

This is the last email I\'ll send you about connecting Broken Link Checker on %2$s.

If you don\'t need it after all, there\'s nothing to do. You can deactivate the plugin and you won\'t hear from me about this again.

If you do still want your links checked, you can %3$s and the first scan starts straight away.

Either way, thanks for giving it a try.

Benjamin Rojas, President of AIOSEO', 'broken-link-checker-seo' ),
	esc_html( $greeting ),
	esc_html( $siteName ),
	$settingsLink
);

echo aioseoBrokenLinkChecker()->emails->renderParagraphs( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

// phpcs:enable