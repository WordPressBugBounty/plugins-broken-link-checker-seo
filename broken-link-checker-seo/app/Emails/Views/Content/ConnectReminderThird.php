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
	__( 'connect your free account', 'broken-link-checker-seo' )
);

// The link inventory is built locally, so we know the site's link count even though nothing
// has been checked yet. Fall back to generic copy if the scan hasn't found anything.
$totalLinks = 0;
if ( ! empty( aioseoBrokenLinkChecker()->main->linkStatus->data ) ) {
	$totalLinks = (int) aioseoBrokenLinkChecker()->main->linkStatus->data->getTotalLinks();
}

if ( 0 < $totalLinks ) {
	$linkCountSentence = sprintf(
		// Translators: 1 - The number of links found, 2 - The site name.
		_n(
			'Broken Link Checker has found %1$s link across the posts and pages on %2$s.',
			'Broken Link Checker has found %1$s links across the posts and pages on %2$s.',
			$totalLinks,
			'broken-link-checker-seo'
		),
		esc_html( number_format_i18n( $totalLinks ) ),
		esc_html( $siteName )
	);

	$message = sprintf(
		// Translators: 1 - The greeting, 2 - A sentence stating how many links were found, 3 - The settings link HTML.
		__( '%1$s

%2$s

None of that is being checked, because the plugin still isn\'t connected to your account.

A link that has quietly stopped working looks exactly like one that hasn\'t, right up until someone clicks it.

It takes a minute to %3$s and start checking.

Benjamin Rojas, President of AIOSEO', 'broken-link-checker-seo' ),
		esc_html( $greeting ),
		$linkCountSentence,
		$settingsLink
	);
} else {
	$message = sprintf(
		// Translators: 1 - The greeting, 2 - The site name, 3 - The settings link HTML.
		__( '%1$s

Broken Link Checker is installed on %2$s but still isn\'t connected to your account, so it hasn\'t checked a single link.

Any link on your site could already be pointing at a page that no longer exists, and there\'s no way for you to know which.

It takes a minute to %3$s and start checking.

Benjamin Rojas, President of AIOSEO', 'broken-link-checker-seo' ),
		esc_html( $greeting ),
		esc_html( $siteName ),
		$settingsLink
	);
}

echo aioseoBrokenLinkChecker()->emails->renderParagraphs( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

// phpcs:enable