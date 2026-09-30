<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// These are set by the method that requires this view, so they are function scope, not global. The
// sniff reads the file on its own and cannot tell the difference.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

// phpcs:disable VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable
// phpcs:disable Generic.Files.LineLength.MaxExceeded

$unsubscribeEmail = isset( $recipientEmail ) ? $recipientEmail : get_option( 'admin_email' );
$unsubscribeType  = isset( $emailType ) ? $emailType : \AIOSEO\BrokenLinkChecker\Emails\Emails::TYPE_REMINDER;
$unsubscribeUrl   = aioseoBrokenLinkChecker()->emails->getUnsubscribeUrl( $unsubscribeEmail, $unsubscribeType );

// Only a report has settings to manage, so the reminders keep the shorter line.
$isReport    = \AIOSEO\BrokenLinkChecker\Emails\Emails::TYPE_REPORT === $unsubscribeType;
$settingsUrl = admin_url( 'admin.php?page=broken-link-checker#/settings' );
$linkStyle   = 'color: #434960; text-decoration: underline;';

$siteUrl    = home_url();
$siteHost   = wp_parse_url( $siteUrl, PHP_URL_HOST );
$siteDomain = $siteHost ? $siteHost : $siteUrl;
?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width: 100%; border-collapse: collapse; background-color: #f3f4f5; color: #434960;">
	<tr>
		<td align="center" style="padding: 0 0 8px 0; background-color: #f3f4f5; color: #434960; font-family: Helvetica, Roboto, Arial, sans-serif; font-size: 12px; line-height: 18px; text-align: center;">
			<?php
			// Translators: 1 - The site domain.
			echo esc_html( sprintf( __( 'This email was sent from your website %1$s.', 'broken-link-checker-seo' ), $siteDomain ) );
			?>
		</td>
	</tr>

	<tr>
		<td align="center" style="padding: 0; background-color: #f3f4f5; color: #434960; font-family: Helvetica, Roboto, Arial, sans-serif; font-size: 12px; line-height: 18px; text-align: center;">
			<?php
			if ( $isReport ) {
				printf(
					// Translators: 1 - Opening link tag, 2 - Closing link tag, 3 - Opening link tag, 4 - Closing link tag.
					esc_html__( 'Don\'t want to receive these emails? %1$sUnsubscribe%2$s or %3$smanage these reports%4$s', 'broken-link-checker-seo' ),
					'<a href="' . esc_url( $unsubscribeUrl ) . '" style="' . esc_attr( $linkStyle ) . '">',
					'</a>',
					'<a href="' . esc_url( $settingsUrl ) . '" style="' . esc_attr( $linkStyle ) . '">',
					'</a>'
				);
			} else {
				printf(
					// Translators: 1 - Opening link tag, 2 - Closing link tag.
					esc_html__( 'Don\'t want to receive these emails? %1$sUnsubscribe%2$s', 'broken-link-checker-seo' ),
					'<a href="' . esc_url( $unsubscribeUrl ) . '" style="' . esc_attr( $linkStyle ) . '">',
					'</a>'
				);
			}
			?>
		</td>
	</tr>
</table>
<?php
// phpcs:enable