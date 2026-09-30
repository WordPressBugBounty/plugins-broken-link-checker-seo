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

$preHeaderText = isset( $preHeader ) ? $preHeader : '';
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="x-apple-disable-message-reformatting">
	<meta name="color-scheme" content="light only">
	<meta name="supported-color-schemes" content="light only">
	<title><?php echo esc_html( AIOSEO_BROKEN_LINK_CHECKER_PLUGIN_NAME ); ?></title>
</head>
<body style="margin: 0; padding: 0; width: 100%; background-color: #f3f4f5; color: #141b38; font-family: Helvetica, Roboto, Arial, sans-serif; font-size: 14px; line-height: 22px;">
	<span style="display: none !important; visibility: hidden; opacity: 0; height: 0; width: 0; max-height: 0; max-width: 0; overflow: hidden; color: #f3f4f5; mso-hide: all;"><?php echo esc_html( $preHeaderText ); ?></span>

	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width: 100%; border-collapse: collapse; background-color: #f3f4f5; color: #141b38;">
		<tr>
			<td align="center" style="padding: 40px 10px; background-color: #f3f4f5; color: #141b38;">
				<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width: 600px; max-width: 600px; border-collapse: collapse; background-color: #ffffff; color: #141b38; border: 1px solid #e8e8eb;">
					<tr>
						<td style="padding: 24px 30px 0 30px; background-color: #ffffff; color: #141b38;">
							<?php require AIOSEO_BROKEN_LINK_CHECKER_DIR . '/app/Emails/Views/Header.php'; ?>
						</td>
					</tr>

					<tr>
						<td style="padding: 24px 30px 30px 30px; background-color: #ffffff; color: #141b38; font-family: Helvetica, Roboto, Arial, sans-serif; font-size: 14px; line-height: 22px;">
							<?php
							if ( ! empty( $contentFile ) && file_exists( $contentFile ) ) {
								require $contentFile;
							}
							?>
						</td>
					</tr>
				</table>

				<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width: 600px; max-width: 600px; border-collapse: collapse; background-color: #f3f4f5; color: #434960;">
					<tr>
						<td style="padding: 20px 30px 0 30px; background-color: #f3f4f5; color: #434960;">
							<?php require AIOSEO_BROKEN_LINK_CHECKER_DIR . '/app/Emails/Views/Footer.php'; ?>
						</td>
					</tr>
				</table>
			</td>
		</tr>
	</table>
</body>
</html>
<?php
// phpcs:enable