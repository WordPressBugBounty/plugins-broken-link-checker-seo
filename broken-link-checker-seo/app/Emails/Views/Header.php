<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// These are set by the method that requires this view, so they are function scope, not global. The
// sniff reads the file on its own and cannot tell the difference.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

// A hosted raster is deliberate: Outlook cannot render inline SVG and Gmail strips it.
$logoUrl   = 'https://static.aioseo.io/report/blc/text-logo.png';
$logoUrl2x = 'https://static.aioseo.io/report/blc/text-logo@2x.png';
$linkColor = \AIOSEO\BrokenLinkChecker\Emails\Emails::LINK_COLOR;

$pricingUrl = aioseoBrokenLinkChecker()->helpers->utmUrl(
	'https://aioseo.com/pricing-broken-link-checker/',
	'blc-email',
	'header-logo'
);

// phpcs:disable Generic.Files.LineLength.MaxExceeded
?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width: 100%; border-collapse: collapse; background-color: #ffffff; color: #141b38;">
	<tr>
		<td style="padding: 0 0 20px 0; border-bottom: 1px solid #e5e5e5; background-color: #ffffff; color: #141b38; line-height: 1;">
			<a href="<?php echo esc_url( $pricingUrl ); ?>" style="display: inline-block; color: <?php echo esc_attr( $linkColor ); ?>; text-decoration: none; line-height: 1;">
				<img
						src="<?php echo esc_url( $logoUrl ); ?>"
						srcset="<?php echo esc_url( $logoUrl2x ); ?> 2x"
						width="253"
						height="32"
						alt="<?php echo esc_attr( AIOSEO_BROKEN_LINK_CHECKER_PLUGIN_NAME ); ?>"
						style="display: block; border: 0; outline: none; text-decoration: none; width: 253px; height: 32px; max-width: 100%; line-height: 1; background-color: #ffffff;"
				/>
			</a>
		</td>
	</tr>
</table>
<?php
// phpcs:enable