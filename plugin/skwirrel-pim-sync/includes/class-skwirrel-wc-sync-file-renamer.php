<?php
/**
 * Skwirrel File Renamer.
 *
 * Works out which already imported files would get a new name under the configured file
 * naming mode (Settings → Files). This class only looks: it never touches the media
 * library or the disk.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Skwirrel_WC_Sync_File_Renamer {

	public const STATUS_RENAME   = 'rename';
	public const STATUS_DONE     = 'done';
	public const STATUS_NO_NAME  = 'no_name';
	public const STATUS_UNUSABLE = 'unusable';
	public const STATUS_MISSING  = 'missing';

	private const META_FILE_NAME = '_skwirrel_file_name';
	private const META_HASH      = '_skwirrel_url_hash';
	private const META_URL       = '_skwirrel_source_url';

	/** Attachments inspected per query, so thousands of files never sit in memory at once. */
	private const CHUNK = 500;

	/** Skipped files listed in the preview (the counts always cover all of them). */
	private const SKIPPED_LIST_LIMIT = 25;

	/**
	 * Whether renaming is allowed on this site.
	 *
	 * Sites that offload uploads to remote storage return false: renaming would change files
	 * the offload plugin has already copied. The per-file local-disk check still applies.
	 */
	public static function can_rename(): bool {
		/**
		 * Filters whether existing Skwirrel files may be renamed.
		 *
		 * @param bool $can_rename Default true.
		 */
		return (bool) apply_filters( 'skwirrel_wc_sync_can_rename_files', true );
	}

	/**
	 * Preview of the rename job. Read-only.
	 *
	 * @param int $page     1-based page of the rename list.
	 * @param int $per_page Rows per page.
	 * @return array{
	 *     supported: bool,
	 *     counts: array<string,int>,
	 *     rows: array<int,array{id:int,old:string,new:string,status:string}>,
	 *     skipped: array<int,array{id:int,old:string,new:string,status:string}>,
	 *     pages: int,
	 *     page: int
	 * }
	 */
	public static function preview( int $page = 1, int $per_page = 50 ): array {
		$result = [
			'supported' => Skwirrel_WC_Sync_File_Naming::MODE_CURRENT !== Skwirrel_WC_Sync_File_Naming::get_mode(),
			'counts'    => [
				self::STATUS_RENAME   => 0,
				self::STATUS_DONE     => 0,
				self::STATUS_NO_NAME  => 0,
				self::STATUS_UNUSABLE => 0,
				self::STATUS_MISSING  => 0,
			],
			'rows'      => [],
			'skipped'   => [],
			'pages'     => 1,
			'page'      => max( 1, $page ),
		];
		if ( ! $result['supported'] ) {
			return $result;
		}

		$per_page = max( 1, $per_page );
		$first    = ( $result['page'] - 1 ) * $per_page;
		$reserved = [];
		$chunk    = 1;
		$fetched  = 0;
		do {
			$ids     = get_posts(
				[
					'post_type'              => 'attachment',
					'post_status'            => 'any',
					'meta_key'               => self::META_HASH, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- only files this plugin imported.
					'posts_per_page'         => self::CHUNK,
					'paged'                  => $chunk,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
				]
			);
			$fetched = count( $ids );
			foreach ( $ids as $id ) {
				$item = self::classify( (int) $id, $reserved );
				++$result['counts'][ $item['status'] ];
				if ( self::STATUS_RENAME === $item['status'] ) {
					$index = $result['counts'][ self::STATUS_RENAME ] - 1;
					if ( $index >= $first && $index < $first + $per_page ) {
						$result['rows'][] = $item;
					}
				} elseif ( self::STATUS_DONE !== $item['status'] && count( $result['skipped'] ) < self::SKIPPED_LIST_LIMIT ) {
					$result['skipped'][] = $item;
				}
			}
			++$chunk;
		} while ( self::CHUNK === $fetched );

		$result['pages'] = max( 1, (int) ceil( $result['counts'][ self::STATUS_RENAME ] / $per_page ) );
		return $result;
	}

	/**
	 * Decide what would happen to one attachment.
	 *
	 * @param int                      $attachment_id Attachment ID.
	 * @param array<string,bool>       $reserved      Names already given out in this run (directory/name),
	 *                                                so two files never plan the same new name.
	 * @return array{id:int,old:string,new:string,status:string}
	 */
	public static function classify( int $attachment_id, array &$reserved ): array {
		$path = get_attached_file( $attachment_id );
		$item = [
			'id'     => $attachment_id,
			'old'    => is_string( $path ) ? basename( $path ) : '',
			'new'    => '',
			'status' => self::STATUS_MISSING,
		];
		// Remote-only files (offload plugins) have no local copy to rename.
		if ( ! is_string( $path ) || '' === $path || ! file_exists( $path ) ) {
			return $item;
		}

		$stored = trim( (string) get_post_meta( $attachment_id, self::META_FILE_NAME, true ) );
		if ( '' === $stored ) {
			$item['status'] = self::STATUS_NO_NAME;
			return $item;
		}

		$dir    = dirname( $path );
		$old    = basename( $path );
		$ext    = pathinfo( $old, PATHINFO_EXTENSION );
		$prefix = Skwirrel_WC_Sync_File_Naming::MODE_PREFIX === Skwirrel_WC_Sync_File_Naming::get_mode() ? Skwirrel_WC_Sync_File_Naming::get_prefix() : '';

		if ( '' !== $prefix && str_starts_with( $old, $prefix ) ) {
			$item['status'] = self::STATUS_DONE;
			return $item;
		}

		$bare = Skwirrel_WC_Sync_File_Naming::derive( $dir, $stored, $ext, true, false );
		if ( '' === $bare ) {
			$item['status'] = self::STATUS_UNUSABLE;
			return $item;
		}
		$url = (string) get_post_meta( $attachment_id, self::META_URL, true );
		if ( self::is_named_like( $old, $bare ) || self::is_named_like( $old, Skwirrel_WC_Sync_File_Naming::filtered_name( $bare, $url, 'rename' ) ) ) {
			$item['status'] = self::STATUS_DONE;
			return $item;
		}

		$new = Skwirrel_WC_Sync_File_Naming::derive( $dir, $stored, $ext, true );
		$new = Skwirrel_WC_Sync_File_Naming::finalize( $new, $dir, $url, 'rename' );
		$new = self::reserve( $dir, $new, $reserved );
		if ( $new === $old ) {
			$item['status'] = self::STATUS_DONE;
			return $item;
		}

		$item['new']    = $new;
		$item['status'] = self::STATUS_RENAME;
		return $item;
	}

	/**
	 * Whether a file already carries the name the mode gives it.
	 *
	 * Besides the bare name, that includes what WordPress and the duplicate rule make of it: a
	 * `-N` suffix (a clash with an existing file) and a `-scaled` copy of a big image. Without this
	 * those files look unrenamed after every run and would be renamed again to `-N+1`.
	 *
	 * @param string $current Current file name.
	 * @param string $bare    Name the mode gives the file, before any uniqueness suffix.
	 */
	public static function is_named_like( string $current, string $bare ): bool {
		if ( $current === $bare ) {
			return true;
		}
		$stem = pathinfo( $bare, PATHINFO_FILENAME );
		$ext  = pathinfo( $bare, PATHINFO_EXTENSION );
		return 1 === preg_match( '/^' . preg_quote( $stem, '/' ) . '(-[0-9]+)?(-scaled)?\.' . preg_quote( $ext, '/' ) . '$/', $current );
	}

	/**
	 * Claim a name in a directory for this run, adding -1, -2 when an earlier file claimed it.
	 *
	 * @param array<string,bool> $reserved Names already given out (directory/name).
	 */
	private static function reserve( string $dir, string $name, array &$reserved ): string {
		$candidate = $name;
		$stem      = pathinfo( $name, PATHINFO_FILENAME );
		$ext       = pathinfo( $name, PATHINFO_EXTENSION );
		$n         = 0;
		while ( isset( $reserved[ $dir . '/' . $candidate ] ) ) {
			++$n;
			$candidate = $stem . '-' . $n . ( '' !== $ext ? '.' . $ext : '' );
			if ( file_exists( $dir . '/' . $candidate ) ) {
				// Taken on disk as well: keep counting.
				$reserved[ $dir . '/' . $candidate ] = true;
			}
		}
		$reserved[ $dir . '/' . $candidate ] = true;
		return $candidate;
	}
}
