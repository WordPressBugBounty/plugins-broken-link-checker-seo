<?php
namespace AIOSEO\BrokenLinkChecker\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AIOSEO\BrokenLinkChecker\Models;

/**
 * Streams the Broken Links report to a CSV file.
 *
 * @since 1.3.1
 */
class Export {
	/**
	 * The admin-post action the download is requested with.
	 *
	 * @since 1.3.1
	 *
	 * @var string
	 */
	public $action = 'aioseo_blc_export_link_statuses';

	/**
	 * How many rows we hold in memory at a time.
	 *
	 * @since 1.3.1
	 *
	 * @var int
	 */
	private $rowsPerChunk = 500;

	/**
	 * How many of a URL's locations one query reads.
	 *
	 * A page size, not a cap: {@see self::getOccurrences()} walks every page. A link in a site-wide
	 * element really does sit in thousands of places, and the file says so rather than stopping quietly.
	 *
	 * @since 1.3.1
	 *
	 * @var int
	 */
	const LOCATIONS_PER_PAGE = 500;

	/**
	 * Class constructor.
	 *
	 * @since 1.3.1
	 */
	public function __construct() {
		add_action( 'admin_post_' . $this->action, [ $this, 'export' ] );
	}

	/**
	 * Returns the URL that starts the download.
	 *
	 * @since 1.3.1
	 *
	 * @return string The URL.
	 */
	public function getUrl() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . $this->action ), $this->action );
	}

	/**
	 * Streams every row matching the requested filter and search term as a CSV file.
	 *
	 * @since 1.3.1
	 *
	 * @return void
	 */
	public function export() {
		if (
			! aioseoBrokenLinkChecker()->access->isAdmin() &&
			! current_user_can( 'aioseo_blc_broken_links_page' )
		) {
			wp_die( esc_html__( 'You are not allowed to export broken links.', 'broken-link-checker-seo' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( $this->action );

		$filter = $this->sanitizeFilter( isset( $_GET['filter'] ) ? sanitize_text_field( wp_unslash( $_GET['filter'] ) ) : '' );
		// Normalised by the model, which is what decides whether a term narrows anything at all.
		// Sanitised by Link::normalizeSearchTerm() and escaped for LIKE in the query. Not sanitize_text_field(),
		// which strips tags and so emptied a term like '<script>x</script>' into "no search".
		$searchTerm = isset( $_GET['searchTerm'] )
			? wp_unslash( $_GET['searchTerm'] ) // phpcs:ignore HM.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: null;
		$source     = isset( $_GET['source'] ) ? sanitize_text_field( wp_unslash( $_GET['source'] ) ) : '';
		$type       = isset( $_GET['media'] ) ? sanitize_text_field( wp_unslash( $_GET['media'] ) ) : '';

		// The same narrowing the report applies, so the file matches what was on screen.
		$whereClause = Models\Link::getLinkWhereClause( $searchTerm );
		$whereClause = Models\Link::addSourceClause( $whereClause, $source );
		$whereClause = Models\Link::addTypeClause( $whereClause, $type );

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		}

		// So that a lost connection doesn't kill the request part-way through writing a row. The loop
		// below decides when to stop instead, at the next chunk boundary.
		ignore_user_abort( true );

		// A buffer the site started would hold the whole file in memory and let stray notices into it.
		for ( $level = ob_get_level(); 0 < $level; $level-- ) {
			if ( ! ob_end_clean() ) {
				break;
			}
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $this->getFileName( $filter ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		// Excel reads a UTF-8 file as the system encoding unless it finds a byte order mark.
		echo "\xEF\xBB\xBF";
		echo $this->encodeRow( $this->getHeaderRow() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		$afterHash = null;
		while ( true ) {
			$rows = Models\LinkStatus::exportRows( $filter, $whereClause, $afterHash, $this->rowsPerChunk );
			if ( empty( $rows ) ) {
				break;
			}

			$postIds   = [];
			$primeMeta = false;
			foreach ( $rows as $row ) {
				// The hash rather than the URL: the collation folds case, so two spellings compare equal
				// and the cursor could not say which of them it had already emitted.
				$afterHash = $row->url_hash;

				foreach ( $this->getObjectRefs( $row ) as $ref ) {
					if ( ! in_array( $ref['type'], [ 'post', 'post_meta', 'menu_item' ], true ) ) {
						continue;
					}

					$postIds[] = $ref['id'];

					// A custom field's row is resolved through the post's meta, so that has to be primed too
					// or every one of them costs a query of its own.
					$primeMeta = $primeMeta || 'post_meta' === $ref['type'];
				}
			}

			// One query for the chunk's posts, so the capability, title and permalink lookups are cache hits.
			$postIds = array_unique( $postIds );
			if ( ! empty( $postIds ) ) {
				_prime_post_caches( $postIds, false, $primeMeta );
			}

			foreach ( $rows as $row ) {
				foreach ( $this->getRows( $row, $whereClause ) as $csvRow ) {
					echo $this->encodeRow( $csvRow ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
			}

			if ( 0 < ob_get_level() ) {
				ob_flush();
			}

			flush();

			$this->forgetPosts( $postIds );

			// Nobody is left to receive the rest of the file, and reading the whole table into a dead
			// connection is minutes of queries. The flush above is what makes the abort visible here.
			if ( connection_aborted() ) {
				break;
			}

			if ( count( $rows ) < $this->rowsPerChunk ) {
				break;
			}
		}

		exit;
	}

	/**
	 * Encodes the given fields as a CSV record.
	 *
	 * NOTE: Not fputcsv(), which applies its own escape character - it writes `a\"b` as a value no
	 * compliant reader parses back, and disabling that needs the PHP 7.4 $escape parameter.
	 *
	 * @since 1.3.1
	 *
	 * @param  array  $fields The fields.
	 * @return string         The record.
	 */
	private function encodeRow( $fields ) {
		$encoded = [];
		foreach ( $fields as $field ) {
			$encoded[] = '"' . str_replace( '"', '""', $this->defuseFormula( (string) $field ) ) . '"';
		}

		return implode( ',', $encoded ) . "\r\n";
	}

	/**
	 * Prefixes a field a spreadsheet would evaluate instead of display.
	 *
	 * NOTE: Anchor text and post titles come from post content, so anyone who can publish otherwise
	 * decides what the admin opening the file runs - =HYPERLINK(), WEBSERVICE(), =cmd|...
	 *
	 * @since 1.3.1
	 *
	 * @param  string $field The field.
	 * @return string        The field.
	 */
	private function defuseFormula( $field ) {
		return '' !== $field && false !== strpos( "=+-@\t\r", $field[0] ) ? "'" . $field : $field;
	}

	/**
	 * Returns the objects the given row reports.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Renamed from getPostIds(); reads the object references.
	 *
	 * @param  object $row The link status row.
	 * @return array       The object references.
	 */
	private function getObjectRefs( $row ) {
		if ( empty( $row->object_refs ) ) {
			return [];
		}

		$refs = [];
		foreach ( Models\Link::splitObjectRefs( $row->object_refs ) as $reference ) {
			$parsed = Models\Link::parseObjectRef( $reference );
			if ( $parsed['id'] ) {
				$refs[] = $parsed;
			}
		}

		return $refs;
	}

	/**
	 * Returns the first of the row's objects the current user may edit.
	 *
	 * NOTE: Mirrors {@see Models\Link::rowQuery()}, which drops the objects the user can't edit. Without
	 * it the export hands out titles and permalinks the report on screen withholds. Each kind is checked
	 * against its own capability, so a post's does not stand in for a term's.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Renamed from getEditablePostId(); returns any kind of object.
	 *
	 * @param  object     $row The link status row.
	 * @return array|null      The object reference, or null if there is none.
	 */
	private function getEditableObject( $row ) {
		foreach ( $this->getObjectRefs( $row ) as $ref ) {
			$type = aioseoBrokenLinkChecker()->objects->get( $ref['type'] );

			if ( $type->canEdit( $ref['id'], $ref['subtype'] ) ) {
				return array_merge( $ref, [ 'objectType' => $type ] );
			}
		}

		return null;
	}

	/**
	 * Drops the given posts from the caches, so memory follows the chunk instead of the whole export.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Drops the post meta the custom-field rows were resolved through.
	 *
	 * @param  array $postIds The post IDs.
	 * @return void
	 */
	private function forgetPosts( $postIds ) {
		foreach ( $postIds as $postId ) {
			wp_cache_delete( $postId, 'posts' );
			wp_cache_delete( $postId, 'post_meta' );
		}

		aioseoBrokenLinkChecker()->helpers->resetPostTitles();
	}

	/**
	 * Returns the given filter if the report offers it, and all otherwise.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $filter The requested filter.
	 * @return string         The filter.
	 */
	private function sanitizeFilter( $filter ) {
		return in_array( $filter, [ 'all', 'broken', 'redirects', 'good', 'not-checked', 'dismissed' ], true ) ? $filter : 'all';
	}

	/**
	 * Returns the file name for the download.
	 *
	 * @since 1.3.1
	 *
	 * @param  string $filter The active filter.
	 * @return string         The file name.
	 */
	private function getFileName( $filter ) {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = $host ? $host : 'site';

		return sanitize_file_name( $host . '-links-' . $filter . '-' . date_i18n( 'Y-m-d' ) . '.csv' );
	}

	/**
	 * Returns the header row.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Added the reason column.
	 *
	 * @return array The header row.
	 */
	private function getHeaderRow() {
		return [
			__( 'URL', 'broken-link-checker-seo' ),
			__( 'Link Text', 'broken-link-checker-seo' ),
			__( 'Status', 'broken-link-checker-seo' ),
			__( 'HTTP Status Code', 'broken-link-checker-seo' ),
			__( 'Reason', 'broken-link-checker-seo' ),
			__( 'Source', 'broken-link-checker-seo' ),
			__( 'Found In', 'broken-link-checker-seo' ),
			__( 'View URL', 'broken-link-checker-seo' ),
			__( 'Edit URL', 'broken-link-checker-seo' ),
			// Named for what it is: the URL's own total, repeated on each of its rows, which now number
			// the same. "Locations" beside a row that is itself one location read as this row's count.
			__( 'Total Locations', 'broken-link-checker-seo' ),
			__( 'Last Checked', 'broken-link-checker-seo' )
		];
	}

	/**
	 * Returns the CSV rows for the given link status row, one per location the URL was found in.
	 *
	 * NOTE: A URL found in several places used to export as one row describing the first of them, so the
	 * file said less than the report it came from.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 One row per location rather than per URL.
	 *
	 * @param  object $row         The link status row.
	 * @param  string $whereClause The WHERE clause the report was narrowed by.
	 * @return array               The CSV rows.
	 */
	private function getRows( $row, $whereClause = '' ) {
		$occurrences = $this->getOccurrences( $row, $whereClause );
		if ( empty( $occurrences ) ) {
			// Nothing here the current user may edit, so the row carries the URL and its status alone -
			// which is what the report shows them too.
			return [ $this->getRow( $row ) ];
		}

		$rows = [];
		foreach ( $occurrences as $occurrence ) {
			$rows[] = $this->getRow( $row, $occurrence );
		}

		return $rows;
	}

	/**
	 * Returns the link rows behind the given link status, decorated with their location.
	 *
	 * @since 1.3.1
	 *
	 * @param  object $row         The link status row.
	 * @param  string $whereClause The WHERE clause the report was narrowed by.
	 * @return array               The link rows.
	 */
	private function getOccurrences( $row, $whereClause = '' ) {
		if ( empty( $row->link_status_id ) ) {
			return [];
		}

		// A URL found once is already described by its own row, and asking per status would cost a query
		// for every line of the file.
		if ( 2 > ( isset( $row->total_links ) ? (int) $row->total_links : 0 ) ) {
			return [];
		}

		// Every page of them. A cap here read as a complete file: nothing in it said otherwise, the
		// locations column went on printing the true total, and which of them survived was whatever the
		// storage engine happened to return. An export is the one place completeness is the point.
		$occurrences = [];
		$offset      = 0;
		do {
			$page = Models\Link::rowQuery( (int) $row->link_status_id, self::LOCATIONS_PER_PAGE, $offset, $whereClause );
			if ( empty( $page ) ) {
				break;
			}

			$occurrences = array_merge( $occurrences, $page );
			$offset     += self::LOCATIONS_PER_PAGE;
		} while ( self::LOCATIONS_PER_PAGE === count( $page ) );

		return $occurrences;
	}

	/**
	 * Returns a single CSV row for the given link status row.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Takes the location to describe, where the URL has more than one.
	 * @version 1.3.1 Added the reason column.
	 *
	 * @param  object      $row        The link status row.
	 * @param  object|null $occurrence The location to describe, or null to resolve the first editable one.
	 * @return array                   The CSV row.
	 */
	private function getRow( $row, $occurrence = null ) {
		$sourceLabel   = '';
		$locationLabel = '';
		$permalink     = '';
		$editLink      = '';
		$anchor        = '';

		if ( $occurrence ) {
			$context       = isset( $occurrence->context ) ? (array) $occurrence->context : [];
			$sourceLabel   = isset( $context['sourceLabel'] ) ? $context['sourceLabel'] : '';
			$locationLabel = isset( $context['locationLabel'] ) ? $context['locationLabel'] : '';
			$permalink     = isset( $context['permalink'] ) ? $context['permalink'] : '';
			$editLink      = isset( $context['editLink'] ) ? $context['editLink'] : '';

			// Each location has its own link text, which the aggregated row could never carry.
			$anchor = ! empty( $occurrence->anchor ) ? $occurrence->anchor : '';
		} else {
			$object = $this->getEditableObject( $row );
			if ( $object ) {
				$sourceLabel   = $object['objectType']->sourceLabel();
				$locationLabel = $object['objectType']->locationLabel( $object['id'], $object['subtype'] );
				$permalink     = $object['objectType']->viewUrl( $object['id'], $object['subtype'] );
				$editLink      = $object['objectType']->editUrl( $object['id'], $object['subtype'] );
			}

			// The anchor belongs to the object we report only while the URL was found in a single link.
			$anchor = $object && 1 === (int) $row->total_links && ! empty( $row->anchor ) ? $row->anchor : '';
		}

		return [
			$row->url,
			$anchor,
			$this->getStatusLabel( $row ),
			! empty( $row->http_status_code ) ? (int) $row->http_status_code : '',
			$this->getReasonLabel( $row ),
			$sourceLabel,
			$locationLabel,
			$permalink ? $permalink : '',
			$editLink ? $editLink : '',
			// The links found, matching the report's own count and the rows the report expands to. The
			// distinct-object count is a different number and was labelled as this one.
			! empty( $row->total_links ) ? (int) $row->total_links : 0,
			! empty( $row->last_scan_date ) ? get_date_from_gmt( $row->last_scan_date, 'Y-m-d H:i' ) : ''
		];
	}

	/**
	 * Returns the status label for the given link status row.
	 *
	 * NOTE: Mirrors the states the report's status column renders.
	 *
	 * @since   1.3.1
	 * @version 1.3.1 Tells a retry apart from a link nothing has checked yet.
	 *
	 * @param  object $row The link status row.
	 * @return string      The status label.
	 */
	private function getStatusLabel( $row ) {
		if ( empty( $row->last_scan_date ) ) {
			return __( 'Pending', 'broken-link-checker-seo' );
		}

		if ( ! empty( $row->needs_additional_scan ) ) {
			return __( 'Retrying', 'broken-link-checker-seo' );
		}

		if ( ! empty( $row->broken ) ) {
			return __( 'Broken', 'broken-link-checker-seo' );
		}

		if ( ! empty( $row->redirect_count ) ) {
			return __( 'Redirect', 'broken-link-checker-seo' );
		}

		return __( 'OK', 'broken-link-checker-seo' );
	}

	/**
	 * Returns the reason label for the given link status row.
	 *
	 * NOTE: Short labels rather than the report's sentences, which are written to be read beside the
	 * link and are too long for a spreadsheet cell.
	 *
	 * @since 1.3.1
	 *
	 * @param  object $row The link status row.
	 * @return string      The reason label, or an empty string when the status code says enough.
	 */
	private function getReasonLabel( $row ) {
		$labels = [
			'blocked'             => __( 'Blocked', 'broken-link-checker-seo' ),
			'timeout'             => __( 'Timed out', 'broken-link-checker-seo' ),
			'unreachable'         => __( 'Unreachable', 'broken-link-checker-seo' ),
			'invalid-host'        => __( 'Invalid address', 'broken-link-checker-seo' ),
			'invalid-certificate' => __( 'Certificate error', 'broken-link-checker-seo' ),
			'login-required'      => __( 'Login required', 'broken-link-checker-seo' )
		];

		$reason = Models\LinkStatus::reasonFromLog( isset( $row->log ) ? $row->log : '' );

		return isset( $labels[ $reason ] ) ? $labels[ $reason ] : '';
	}
}