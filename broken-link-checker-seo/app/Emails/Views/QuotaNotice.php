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

/** @var \AIOSEO\BrokenLinkChecker\Emails\Reports\Content $content */

// Included by both report views, which each set $content before requiring this.
if ( ! $content->isQuotaSpent() ) {
	return;
}

$quota      = $content->getQuota();
$upgradeUrl = aioseoBrokenLinkChecker()->helpers->utmUrl(
	AIOSEO_BROKEN_LINK_CHECKER_MARKETING_URL . 'pricing-broken-link-checker/',
	'email-report',
	'quota-spent'
);
?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width: 100%; border-collapse: collapse; margin: 0 0 22px 0; background-color: #fdf3f5; color: #6d2233;">
	<tr>
		<td style="padding: 16px 18px; border: 1px solid #f0c2cb; border-left: 4px solid #ab2039; background-color: #fdf3f5; color: #6d2233; font-family: Helvetica, Roboto, Arial, sans-serif;">
			<span style="display: block; margin-bottom: 5px; background-color: #fdf3f5; color: #8f1a30; font-family: Helvetica, Roboto, Arial, sans-serif; font-size: 14px; font-weight: 700; line-height: 20px;">
				<?php
				echo esc_html(
					sprintf(
						// Translators: 1 - A number of links, e.g. "5,000".
						_n(
							'You have used the %1$s link in your plan.',
							'You have used all %1$s links in your plan.',
							$quota['total'],
							'broken-link-checker-seo'
						),
						number_format_i18n( $quota['total'] )
					)
				);
				?>
			</span>

			<span style="display: block; background-color: #fdf3f5; color: #6d2233; font-family: Helvetica, Roboto, Arial, sans-serif; font-size: 13px; line-height: 20px;">
				<?php
				if ( $content->isQuotaNoticeBrief() ) {
					echo esc_html( $content->getQuotaResetText() );
				} else {
					echo esc_html(
						sprintf(
							// Translators: 1 - A sentence naming when the quota comes back.
							__( 'Nothing on your site is being checked, so anything that breaks now will not appear in this report. %1$s', 'broken-link-checker-seo' ),
							$content->getQuotaResetText()
						)
					);
				}
				?>
			</span>

			<a href="<?php echo esc_url( $upgradeUrl ); ?>" style="display: inline-block; margin-top: 10px; background-color: #fdf3f5; color: #ab2039; font-family: Helvetica, Roboto, Arial, sans-serif; font-size: 13px; font-weight: 700; line-height: 20px; text-decoration: underline;">
				<?php esc_html_e( 'Upgrade for a higher limit', 'broken-link-checker-seo' ); ?>
			</a>
		</td>
	</tr>
</table>