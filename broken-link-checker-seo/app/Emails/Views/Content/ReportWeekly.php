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

$content      = $data['content'];
$changedCount = $content->getChangedCount();
$overflowText = $content->getOverflowText();
$linkColor    = \AIOSEO\BrokenLinkChecker\Emails\Emails::LINK_COLOR;
?>
<h1 style="margin: 0 0 16px 0; padding: 0; background-color: #ffffff; color: #141b38; font-family: Helvetica, Roboto, Arial, sans-serif; font-size: 20px; font-weight: 700; line-height: 26px;">
	<?php
	// A count is the wrong headline when the count only looks low because nothing was checked.
	if ( $content->isQuotaSpent() ) {
		echo esc_html(
			sprintf(
				// Translators: 1 - The site domain.
				__( 'Checking paused on %1$s', 'broken-link-checker-seo' ),
				$content->getSiteDomain()
			)
		);
	} else {
		echo esc_html(
			sprintf(
				// Translators: 1 - A number of links, 2 - The site domain.
				_n(
					'%1$s link broke on %2$s this week',
					'%1$s links broke on %2$s this week',
					$changedCount,
					'broken-link-checker-seo'
				),
				number_format_i18n( $changedCount ),
				$content->getSiteDomain()
			)
		);
	}
	?>
</h1>

<?php require AIOSEO_BROKEN_LINK_CHECKER_DIR . '/app/Emails/Views/QuotaNotice.php'; ?>

<p style="margin: 0 0 20px 0; padding: 0; background-color: #ffffff; color: #434960; font-family: Helvetica, Roboto, Arial, sans-serif; font-size: 14px; line-height: 22px;">
	<?php
	if ( $content->isQuotaSpent() ) {
		esc_html_e( 'These links broke before checking stopped, and are still broken.', 'broken-link-checker-seo' );
	} else {
		esc_html_e( 'These links were working the last time we reported and are not anymore.', 'broken-link-checker-seo' );
	}
	?>
</p>

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="width: 100%; border-collapse: collapse; background-color: #ffffff; color: #141b38;">
	<thead>
		<tr>
			<th align="left" style="padding: 8px 10px 8px 0; border-bottom: 1px solid #e5e5e5; background-color: #ffffff; color: #434960; font-family: Helvetica, Roboto, Arial, sans-serif; font-size: 12px; font-weight: 700; line-height: 18px; text-align: left;">
				<?php esc_html_e( 'Broken link', 'broken-link-checker-seo' ); ?>
			</th>
			<th align="left" style="padding: 8px 0 8px 10px; border-bottom: 1px solid #e5e5e5; background-color: #ffffff; color: #434960; font-family: Helvetica, Roboto, Arial, sans-serif; font-size: 12px; font-weight: 700; line-height: 18px; text-align: left; width: 90px;">
				<?php esc_html_e( 'Status', 'broken-link-checker-seo' ); ?>
			</th>
		</tr>
	</thead>

	<tbody>
		<?php foreach ( $content->getChangedLinks() as $link ) : ?>
			<tr>
				<td style="padding: 12px 10px 12px 0; border-bottom: 1px solid #f0f0f2; background-color: #ffffff; color: #141b38; font-family: Helvetica, Roboto, Arial, sans-serif; font-size: 14px; line-height: 20px; word-break: break-all;">
					<span style="color: #141b38; font-family: Helvetica, Roboto, Arial, sans-serif; font-size: 14px; line-height: 20px;"><?php echo esc_html( $link['url'] ); ?></span>

					<?php if ( $link['postTitle'] ) : ?>
						<br />
						<span style="color: #434960; font-family: Helvetica, Roboto, Arial, sans-serif; font-size: 12px; line-height: 18px;">
							<?php
							printf(
								// Translators: 1 - Opening link tag, 2 - The title of the post the link is on, 3 - Closing link tag.
								esc_html__( 'on %1$s%2$s%3$s', 'broken-link-checker-seo' ),
								$link['postUrl'] ? '<a href="' . esc_url( $link['postUrl'] ) . '" style="color: ' . esc_attr( $linkColor ) . '; text-decoration: underline;">' : '',
								esc_html( $link['postTitle'] ),
								$link['postUrl'] ? '</a>' : ''
							);
							?>
						</span>
					<?php endif; ?>
				</td>

				<td align="left" style="padding: 12px 0 12px 10px; border-bottom: 1px solid #f0f0f2; background-color: #ffffff; color: #ab2039; font-family: Helvetica, Roboto, Arial, sans-serif; font-size: 14px; line-height: 20px; text-align: left; width: 90px;">
					<?php echo esc_html( $link['statusText'] ); ?>
				</td>
			</tr>
		<?php endforeach; ?>

		<?php if ( $overflowText ) : ?>
			<tr>
				<td colspan="2" style="padding: 12px 0; background-color: #ffffff; color: #434960; font-family: Helvetica, Roboto, Arial, sans-serif; font-size: 13px; line-height: 20px;"><?php echo esc_html( $overflowText ); ?></td>
			</tr>
		<?php endif; ?>
	</tbody>
</table>

<?php
$ctaButton = aioseoBrokenLinkChecker()->emails->renderButton(
	$content->getReportUrl(),
	__( 'Fix these links', 'broken-link-checker-seo' )
);

echo $ctaButton; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
?>
<?php
// phpcs:enable