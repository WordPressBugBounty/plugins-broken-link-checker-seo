<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable VariableAnalysis.CodeAnalysis.VariableAnalysis.UndefinedVariable

// Include shared styles.
require AIOSEO_BROKEN_LINK_CHECKER_DIR . '/app/Emails/Views/Styles.php';
?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body>
	<div class="email-container">
		<?php
		// Include shared header with logo.
		require AIOSEO_BROKEN_LINK_CHECKER_DIR . '/app/Emails/Views/Header.php';
		?>
		<div class="email-content">
			<?php
			// Include the email-specific content.
			if ( ! empty( $contentFile ) && file_exists( $contentFile ) ) {
				require $contentFile;
			}
			?>
		</div>
		<?php
		// Include shared footer.
		require AIOSEO_BROKEN_LINK_CHECKER_DIR . '/app/Emails/Views/Footer.php';
		?>
	</div>
</body>
</html>
<?php
// phpcs:enable
