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
	__( 'connect it here', 'broken-link-checker-seo' )
);

$message = sprintf(
	// Translators: 1 - The greeting, 2 - The site name, 3 - The settings link HTML.
	__( '%1$s

Two months ago you installed Broken Link Checker on %2$s. It still isn\'t connected, so it hasn\'t checked any of your links yet.

Broken links cost you twice. Visitors hit a dead end and leave, and search engines treat them as a signal that the page isn\'t maintained.

The longer a site has been running, the more of its outbound links have quietly stopped working.

If something got in the way — a problem signing up, a question about how the scanning works, anything at all — just reply to this email and I\'ll help you sort it out.

Otherwise you can %3$s whenever you\'re ready.

Benjamin Rojas, President of AIOSEO', 'broken-link-checker-seo' ),
	esc_html( $greeting ),
	esc_html( $siteName ),
	$settingsLink
);

echo aioseoBrokenLinkChecker()->emails->renderParagraphs( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

// phpcs:enable