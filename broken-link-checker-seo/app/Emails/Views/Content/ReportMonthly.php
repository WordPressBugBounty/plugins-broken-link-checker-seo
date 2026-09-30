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
$totals       = $content->getTotals();
$overflowText = $content->getOverflowText();
$linkColor    = \AIOSEO\BrokenLinkChecker\Emails\Emails::LINK_COLOR;

$scorecard = [
	[
		'label' => __( 'Links checked', 'broken-link-checker-seo' ),
		'value' => $totals['checked'],
		'color' => '#141b38'
	],
	[
		'label' => __( 'Broken links', 'broken-link-checker-seo' ),
		'value' => $totals['broken'],
		'color' => '#ab2039'
	],
	[
		'label' => __( 'Redirects', 'broken-link-checker-seo' ),
		'value' => $totals['redirects'],
		'color' => '#be6903'
	],
	[
		'label' => __( 'New broken links', 'broken-link-checker-seo' ),
		'value' => $totals['new'],
		'color' => '#141b38'
	]
];

// Added rather than swapped in: without it the scorecard quietly under-reports and reads like good
// news. Only a spent quota is alarming - a verdict waiting on its re-check is just not in yet.
if ( 0 < $totals['unchecked'] ) {
	$spent = $content->isQuotaSpent();

	$scorecard[] = [
		'label' => $content->getUncheckedLabel( $totals ),
		'value' => $totals['unchecked'],
		'color' => $spent ? '#ab2039' : '#141b38',
		'spent' => $spent
	];
}
?>
<h1 style="margin: 0 0 16px 0; padding: 0; background-color: #ffffff; color: #141b38; font-family: Helvetica, Roboto, Arial, sans-serif; font-size: 20px; font-weight: 700; line-height: 26px;">
	<?php
	echo esc_html(
		sprintf(
			// Translators: 1 - A month and year, e.g. "July 2026".
			__( 'Your link report for %1$s', 'broken-link-checker-seo' ),
			$content->getPeriodLabel()
		)
	);
	?>
</h1>

<p style="margin: 0 0 20px 0; padding: 0; background-color: #ffffff; color: #434960; font-family: Helvetica, Roboto, Arial, sans-serif; font-size: 14px; line-height: 22px;">
	<?php
	echo esc_html(
		sprintf(
			// Translators: 1 - The site domain.
			__( 'Here is how the links on %1$s are holding up.', 'broken-link-checker-seo' ),
			$content->getSiteDomain()
		)
	);
	?>
</p>

<?php require AIOSEO_BROKEN_LINK_CHECKER_DIR . '/app/Emails/Views/QuotaNotice.php'; ?>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width: 100%; border-collapse: collapse; background-color: #ffffff; color: #141b38;">
	<tbody>
		<?php foreach ( $scorecard as $index => $metric ) : ?>
			<?php if ( 0 === $index % 2 ) : ?>
				<tr>
			<?php endif; ?>

			<td width="50%" style="width: 50%; padding: 14px 10px; border: 1px solid <?php echo empty( $metric['spent'] ) ? '#e8e8eb' : '#f0c2cb'; ?>; background-color: <?php echo empty( $metric['spent'] ) ? '#f8f9fa' : '#fdf3f5'; ?>; color: <?php echo esc_attr( $metric['color'] ); ?>; font-family: Helvetica, Roboto, Arial, sans-serif;">
				<span style="display: block; color: <?php echo esc_attr( $metric['color'] ); ?>; font-family: Helvetica, Roboto, Arial, sans-serif; font-size: 24px; font-weight: 700; line-height: 30px;">
					<?php echo esc_html( number_format_i18n( $metric['value'] ) ); ?>
				</span>

				<span style="display: block; color: #434960; font-family: Helvetica, Roboto, Arial, sans-serif; font-size: 12px; line-height: 18px;">
					<?php echo esc_html( $metric['label'] ); ?>
				</span>
			</td>

			<?php if ( 1 === $index % 2 ) : ?>
				</tr>
			<?php endif; ?>
		<?php endforeach; ?>

		<?php if ( 1 === count( $scorecard ) % 2 ) : ?>
			<td width="50%" style="width: 50%; padding: 14px 10px; border: 1px solid #ffffff; background-color: #ffffff;">&nbsp;</td>
			</tr>
		<?php endif; ?>
	</tbody>
</table>

<?php if ( $content->isAllClear() && ! $totals['broken'] ) : ?>
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width: 100%; border-collapse: collapse; margin-top: 20px; background-color: #ecfdf5; color: #077647;">
		<tr>
			<td style="padding: 16px; background-color: #ecfdf5; color: #077647; font-family: Helvetica, Roboto, Arial, sans-serif; font-size: 14px; line-height: 22px;">
				<?php
				echo esc_html(
					sprintf(
						// Translators: 1 - A month and year, e.g. "July 2026".
						__( 'Not a single link broke in %1$s. Nothing needs your attention.', 'broken-link-checker-seo' ),
						$content->getPeriodLabel()
					)
				);
				?>
			</td>
		</tr>
	</table>
<?php elseif ( $content->isAllClear() ) : ?>
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width: 100%; border-collapse: collapse; margin-top: 20px; background-color: #fdf7ed; color: #8a4d02;">
		<tr>
			<td style="padding: 16px; background-color: #fdf7ed; color: #8a4d02; font-family: Helvetica, Roboto, Arial, sans-serif; font-size: 14px; line-height: 22px;">
				<?php
				echo esc_html(
					sprintf(
						// Translators: 1 - A month and year, e.g. "July 2026", 2 - A number of links.
						_n(
							'No new links broke in %1$s, but %2$s link is still broken and needs fixing.',
							'No new links broke in %1$s, but %2$s links are still broken and need fixing.',
							$totals['broken'],
							'broken-link-checker-seo'
						),
						$content->getPeriodLabel(),
						number_format_i18n( $totals['broken'] )
					)
				);
				?>
			</td>
		</tr>
	</table>
<?php else : ?>
	<h2 style="margin: 28px 0 12px 0; padding: 0; background-color: #ffffff; color: #141b38; font-family: Helvetica, Roboto, Arial, sans-serif; font-size: 16px; font-weight: 700; line-height: 22px;">
		<?php esc_html_e( 'New this month', 'broken-link-checker-seo' ); ?>
	</h2>

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
<?php endif; ?>

<?php
$ctaButton = aioseoBrokenLinkChecker()->emails->renderButton(
	$content->getReportUrl(),
	__( 'View full report', 'broken-link-checker-seo' )
);

echo $ctaButton; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
?>
<?php
// phpcs:enable