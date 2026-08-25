<?php
/**
 * Core class — handles rewrite rules, content serving, and Markdown conversion.
 */

if ( ! defined( 'WPINC' ) ) {
	exit;
}

final class Serve_MD_Core {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	private function init(): void {
		add_filter( 'query_vars', [ $this, 'register_query_vars' ] );
		add_filter( 'request', [ $this, 'resolve_md_url' ] );
		add_action( 'template_redirect', [ $this, 'handle_markdown_request' ], 1 );
		add_action( 'wp_head', [ $this, 'add_markdown_discovery_link' ] );

		// Boot sub-components.
		Serve_MD_Admin::instance();
		Serve_MD_Metabox::instance();
	}

	/* ------------------------------------------------------------------
	 * Activation / Deactivation
	 * ----------------------------------------------------------------*/

	public static function activate(): void {
		Serve_MD_Logger::create_log_table();

		// Set default options if not present.
		if ( ! get_option( 'serve_md_settings' ) ) {
			update_option( 'serve_md_settings', Serve_MD_Admin::default_settings() );
		}

		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	/* ------------------------------------------------------------------
	 * URL Resolution
	 * ----------------------------------------------------------------*/

	public function register_query_vars( array $vars ): array {
		$vars[] = 'serve_md_format';
		return $vars;
	}

	/**
	 * Resolve .md URLs to the underlying post using url_to_postid().
	 * Works with all permalink structures (post-name, date-based, numeric, pages).
	 */
	public function resolve_md_url( array $query_vars ): array {
		$settings = self::get_settings();
		if ( empty( $settings['enable_md_url'] ) ) {
			return $query_vars;
		}

		$path = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
		$path = strtok( $path, '?' ); // Strip query string.

		if ( ! str_ends_with( $path, '.md' ) ) {
			return $query_vars;
		}

		$clean_path = substr( $path, 0, -3 ); // Strip .md suffix.
		$clean_url  = home_url( $clean_path );
		$post_id    = url_to_postid( $clean_url );

		if ( $post_id > 0 ) {
			// Pages resolve via page_id, not p; custom post types need post_type
			// alongside p. Using p for a page yields an empty query -> 404 -> the
			// canonical redirect strips the .md suffix (301).
			$pt = get_post_type( $post_id );
			if ( 'page' === $pt ) {
				return [ 'page_id' => $post_id, 'serve_md_format' => 'md' ];
			}
			$qv = [ 'p' => $post_id, 'serve_md_format' => 'md' ];
			if ( 'post' !== $pt ) {
				$qv['post_type'] = $pt;
			}
			return $qv;
		}

		return $query_vars;
	}

	/* ------------------------------------------------------------------
	 * Serve Markdown
	 * ----------------------------------------------------------------*/

	public function handle_markdown_request(): void {
		if ( ! is_singular() ) {
			return;
		}

		$settings = self::get_settings();

		$via_url    = ! empty( $settings['enable_md_url'] ) && get_query_var( 'serve_md_format' ) === 'md';
		$via_header = ! empty( $settings['enable_content_negotiation'] ) && $this->accepts_markdown();

		if ( ! $via_url && ! $via_header ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		// Check if post type is enabled.
		if ( ! in_array( $post->post_type, self::enabled_post_types(), true ) ) {
			return;
		}

		// Check per-post opt-out.
		if ( get_post_meta( $post->ID, '_serve_md_disabled', true ) ) {
			return;
		}

		// Check category / tag exclusions.
		if ( $this->is_excluded( $post ) ) {
			return;
		}

		// Password-protected posts must not bypass the password gate.
		if ( post_password_required( $post ) ) {
			return;
		}

		$markdown = $this->post_to_markdown( $post );

		// Log the request.
		Serve_MD_Logger::instance()->log_request( $post->ID, $via_url ? 'url' : 'header' );

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- External caching API constant
		}

		status_header( 200 );
		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );

		echo $markdown; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Intentional: raw text/markdown output, escaping would corrupt content
		exit;
	}

	/* ------------------------------------------------------------------
	 * Auto-discovery Link Tag
	 * ----------------------------------------------------------------*/

	public function add_markdown_discovery_link(): void {
		$settings = self::get_settings();
		if ( empty( $settings['enable_discovery_link'] ) ) {
			return;
		}

		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( ! in_array( $post->post_type, self::enabled_post_types(), true ) ) {
			return;
		}

		if ( get_post_meta( $post->ID, '_serve_md_disabled', true ) ) {
			return;
		}

		if ( $this->is_excluded( $post ) ) {
			return;
		}

		if ( post_password_required( $post ) ) {
			return;
		}

		$md_url = self::get_markdown_url( $post );

		printf(
			'<link rel="alternate" type="text/markdown" href="%s" title="%s (Markdown)" />' . "\n",
			esc_url( $md_url ),
			esc_attr( get_the_title( $post ) )
		);
	}

	/* ------------------------------------------------------------------
	 * Public helpers used by Metabox
	 * ----------------------------------------------------------------*/

	/**
	 * Get enabled post types from settings.
	 */
	public static function enabled_post_types(): array {
		$settings = self::get_settings();
		return $settings['post_types'] ?? [ 'post', 'page' ];
	}

	/**
	 * Build the .md URL for a given post.
	 */
	public static function get_markdown_url( WP_Post $post ): string {
		$permalink = get_permalink( $post );

		if ( str_ends_with( $permalink, '/' ) ) {
			return rtrim( $permalink, '/' ) . '.md';
		}

		return $permalink . '.md';
	}

	/* ------------------------------------------------------------------
	 * Settings helper
	 * ----------------------------------------------------------------*/

	public static function get_settings(): array {
		static $cache = null;
		if ( $cache === null ) {
			$cache = wp_parse_args(
				get_option( 'serve_md_settings', [] ),
				Serve_MD_Admin::default_settings()
			);
		}
		return $cache;
	}

	/* ------------------------------------------------------------------
	 * Exclusion checks
	 * ----------------------------------------------------------------*/

	private function is_excluded( WP_Post $post ): bool {
		$settings = self::get_settings();

		// Category exclusion.
		if ( ! empty( $settings['exclude_categories'] ) ) {
			$post_cats = wp_get_post_categories( $post->ID );
			if ( array_intersect( $post_cats, $settings['exclude_categories'] ) ) {
				return true;
			}
		}

		// Tag exclusion.
		if ( ! empty( $settings['exclude_tags'] ) ) {
			$post_tags = wp_get_post_tags( $post->ID, [ 'fields' => 'ids' ] );
			if ( array_intersect( $post_tags, $settings['exclude_tags'] ) ) {
				return true;
			}
		}

		return false;
	}

	/* ------------------------------------------------------------------
	 * Accept header check
	 * ----------------------------------------------------------------*/

	private function accepts_markdown(): bool {
		$accept = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ?? '' ) );
		if ( $accept === '' ) {
			return false;
		}

		foreach ( explode( ',', $accept ) as $range ) {
			$parts      = explode( ';', $range );
			$media_type = strtolower( trim( $parts[0] ) );

			if ( $media_type !== 'text/markdown' ) {
				continue;
			}

			// Check for explicit q=0 rejection.
			for ( $i = 1, $len = count( $parts ); $i < $len; $i++ ) {
				$param = strtolower( trim( $parts[ $i ] ) );
				if ( preg_match( '/^q\s*=\s*0(\.0{0,3})?$/', $param ) ) {
					return false;
				}
			}

			return true;
		}

		return false;
	}

	/* ------------------------------------------------------------------
	 * Markdown conversion
	 * ----------------------------------------------------------------*/

	private function post_to_markdown( WP_Post $post ): string {
		$frontmatter = $this->build_frontmatter( $post );
		$body        = $this->html_to_markdown( $post );

		return $frontmatter . $body . "\n";
	}

	/**
	 * Build YAML frontmatter from settings-driven field list.
	 */
	private function build_frontmatter( WP_Post $post ): string {
		$s    = self::get_settings();
		$meta = [];

		if ( ! empty( $s['fm_url'] ) ) {
			$meta['url'] = get_permalink( $post );
		}

		if ( ! empty( $s['fm_title'] ) ) {
			$meta['title'] = get_the_title( $post );
		}

		if ( ! empty( $s['fm_author'] ) ) {
			$author = get_userdata( $post->post_author );
			if ( $author ) {
				$meta['author'] = [
					'name' => $author->display_name,
					'url'  => get_author_posts_url( $author->ID ),
				];
			}
		}

		if ( ! empty( $s['fm_date'] ) ) {
			$meta['date'] = get_the_date( 'c', $post );
		}

		if ( ! empty( $s['fm_modified'] ) ) {
			$meta['modified'] = get_the_modified_date( 'c', $post );
		}

		if ( ! empty( $s['fm_type'] ) ) {
			$meta['type'] = $post->post_type;
		}

		if ( ! empty( $s['fm_summary'] ) && has_excerpt( $post ) ) {
			$meta['summary'] = wp_strip_all_tags( get_the_excerpt( $post ) );
		}

		if ( ! empty( $s['fm_categories'] ) ) {
			$cats = wp_get_post_categories( $post->ID, [ 'fields' => 'names' ] );
			if ( ! empty( $cats ) ) {
				$meta['categories'] = array_values( $cats );
			}
		}

		if ( ! empty( $s['fm_tags'] ) ) {
			$tags = wp_get_post_tags( $post->ID, [ 'fields' => 'names' ] );
			if ( ! empty( $tags ) ) {
				$meta['tags'] = array_values( $tags );
			}
		}

		if ( ! empty( $s['fm_image'] ) ) {
			$thumb = get_post_thumbnail_id( $post );
			if ( $thumb ) {
				$meta['image'] = wp_get_attachment_url( $thumb );
			}
		}

		if ( ! empty( $s['fm_published'] ) ) {
			$meta['published'] = $post->post_status === 'publish';
		}

		// Custom static fields.
		if ( ! empty( $s['custom_fields'] ) ) {
			$lines = array_filter( array_map( 'trim', explode( "\n", $s['custom_fields'] ) ) );
			foreach ( $lines as $line ) {
				$parts = explode( ':', $line, 2 );
				if ( count( $parts ) === 2 ) {
					$meta[ trim( $parts[0] ) ] = trim( $parts[1] );
				}
			}
		}

		// Post meta keys.
		if ( ! empty( $s['meta_keys'] ) ) {
			$keys = array_filter( array_map( 'trim', explode( "\n", $s['meta_keys'] ) ) );
			foreach ( $keys as $mk ) {
				$val = get_post_meta( $post->ID, $mk, true );
				if ( $val !== '' && $val !== false ) {
					$meta[ $mk ] = $val;
				}
			}
		}

		if ( empty( $meta ) ) {
			return '';
		}

		return "---\n" . $this->array_to_yaml( $meta ) . "---\n\n";
	}

	/* ------------------------------------------------------------------
	 * YAML serializer
	 * ----------------------------------------------------------------*/

	private function array_to_yaml( array $data, int $indent = 0 ): string {
		$yaml   = '';
		$prefix = str_repeat( '  ', $indent );

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) && ! array_is_list( $value ) ) {
				$yaml .= $prefix . $this->yaml_key( $key ) . ":\n";
				$yaml .= $this->array_to_yaml( $value, $indent + 1 );
			} elseif ( is_array( $value ) ) {
				$yaml .= $prefix . $this->yaml_key( $key ) . ":\n";
				foreach ( $value as $item ) {
					$yaml .= $prefix . '  - ' . $this->yaml_value( $item ) . "\n";
				}
			} else {
				$yaml .= $prefix . $this->yaml_key( $key ) . ': ' . $this->yaml_value( $value ) . "\n";
			}
		}

		return $yaml;
	}

	private function yaml_key( string $key ): string {
		return $key;
	}

	private function yaml_value( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_null( $value ) ) {
			return 'null';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		if ( preg_match( '/[:#\[\]{}|>*&!%@`,\n]/', (string) $value ) || $value === '' ) {
			return "'" . str_replace( "'", "''", (string) $value ) . "'";
		}
		return (string) $value;
	}

	/* ------------------------------------------------------------------
	 * HTML → Markdown converter
	 * ----------------------------------------------------------------*/

	private function html_to_markdown( WP_Post $post ): string {
		// Render inside the main loop when this post IS the queried object, so
		// theme filters that gate on in_the_loop()/is_main_query() run — e.g. a
		// calendar or table a theme injects into the_content only during the main
		// loop. A bare apply_filters() leaves in_the_loop() false and drops them.
		// Falls back to the raw filter for any post that is not the current query.
		if ( get_queried_object_id() === $post->ID && have_posts() ) {
			ob_start();
			while ( have_posts() ) {
				the_post();
				the_content();
			}
			$html = (string) ob_get_clean();
			rewind_posts();
		} else {
			$html = apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Calling WP core filter, not registering one
		}
		$html = trim( $html );

		if ( empty( $html ) ) {
			return '';
		}

		$md = $html;

		// Preserve <pre><code> blocks.
		$pre_blocks = [];
		$md = preg_replace_callback( '/<pre[^>]*><code[^>]*>(.*?)<\/code><\/pre>/si', function ( $m ) use ( &$pre_blocks ) {
			$key = '%%PREBLOCK_' . count( $pre_blocks ) . '%%';
			$pre_blocks[ $key ] = "\n```\n" . html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) . "\n```\n";
			return $key;
		}, $md );

		$md = preg_replace_callback( '/<pre[^>]*>(.*?)<\/pre>/si', function ( $m ) use ( &$pre_blocks ) {
			$key = '%%PREBLOCK_' . count( $pre_blocks ) . '%%';
			$pre_blocks[ $key ] = "\n```\n" . html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) . "\n```\n";
			return $key;
		}, $md );

		// Inline code.
		$md = preg_replace( '/<code[^>]*>(.*?)<\/code>/si', '`$1`', $md );

		// Headings.
		for ( $i = 6; $i >= 1; $i-- ) {
			$hashes = str_repeat( '#', $i );
			$md = preg_replace( '/<h' . $i . '[^>]*>(.*?)<\/h' . $i . '>/si', "\n" . $hashes . ' $1' . "\n", $md );
		}

		// Images.
		$md = preg_replace( '/<img[^>]+alt=["\']([^"\']*)["\'][^>]+src=["\']([^"\']*)["\'][^>]*\/?>/si', '![$1]($2)', $md );
		$md = preg_replace( '/<img[^>]+src=["\']([^"\']*)["\'][^>]+alt=["\']([^"\']*)["\'][^>]*\/?>/si', '![$2]($1)', $md );
		$md = preg_replace( '/<img[^>]+src=["\']([^"\']*)["\'][^>]*\/?>/si', '![]($1)', $md );

		// Links. Collapse whitespace in the label: block markup pretty-prints
		// anchors across lines (e.g. button links), and raw newlines/indentation
		// inside [ ... ] break the Markdown link.
		$md = preg_replace_callback(
			'/<a[^>]+href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/si',
			function ( $m ) {
				$text = trim( preg_replace( '/\s+/', ' ', $m[2] ) );
				return '[' . $text . '](' . $m[1] . ')';
			},
			$md
		);

		// Bold.
		$md = preg_replace( '/<(strong|b)>(.*?)<\/\1>/si', '**$2**', $md );

		// Italic.
		$md = preg_replace( '/<(em|i)>(.*?)<\/\1>/si', '*$2*', $md );

		// Strikethrough.
		$md = preg_replace( '/<(del|s|strike)>(.*?)<\/\1>/si', '~~$2~~', $md );

		// Blockquotes.
		$md = preg_replace_callback( '/<blockquote[^>]*>(.*?)<\/blockquote>/si', function ( $m ) {
			$inner = wp_strip_all_tags( $m[1] );
			$lines = explode( "\n", trim( $inner ) );
			return "\n" . implode( "\n", array_map( fn( $l ) => '> ' . trim( $l ), $lines ) ) . "\n";
		}, $md );

		// Lists.
		$md = preg_replace( '/<ul[^>]*>/si', "\n", $md );
		$md = preg_replace( '/<\/ul>/si', "\n", $md );
		$md = preg_replace( '/<ol[^>]*>/si', "\n", $md );
		$md = preg_replace( '/<\/ol>/si', "\n", $md );
		$md = preg_replace( '/<li[^>]*>(.*?)<\/li>/si', '- $1' . "\n", $md );

		// Horizontal rules.
		$md = preg_replace( '/<hr[^>]*\/?>/si', "\n---\n", $md );

		// Paragraphs.
		$md = preg_replace( '/<p[^>]*>(.*?)<\/p>/si', "\n" . '$1' . "\n", $md );

		// Line breaks.
		$md = preg_replace( '/<br[^>]*\/?>/si', "  \n", $md );

		// Figure / figcaption.
		$md = preg_replace( '/<\/?figure[^>]*>/si', "\n", $md );
		$md = preg_replace( '/<figcaption[^>]*>(.*?)<\/figcaption>/si', '*$1*' . "\n", $md );

		// Tables — basic support for simple WordPress tables.
		$md = preg_replace_callback( '/<table[^>]*>(.*?)<\/table>/si', [ $this, 'convert_html_table' ], $md );

		// Strip remaining HTML.
		$md = wp_strip_all_tags( $md );

		// Decode entities.
		$md = html_entity_decode( $md, ENT_QUOTES, 'UTF-8' );

		// Left-trim every line: pretty-printed block HTML leaves deep indentation
		// that Markdown misreads as indented code blocks (4+ leading spaces). This
		// runs before the pre blocks are restored, so fenced code keeps its layout.
		$md = preg_replace( '/^[ \t]+/m', '', $md );

		// Restore pre blocks.
		foreach ( $pre_blocks as $key => $block ) {
			$md = str_replace( $key, $block, $md );
		}

		// Collapse excessive blank lines.
		$md = preg_replace( '/\n{3,}/', "\n\n", $md );

		return '# ' . get_the_title( $post ) . "\n\n" . trim( $md ) . "\n";
	}

	/**
	 * Convert an HTML table to a Markdown table.
	 */
	private function convert_html_table( array $match ): string {
		$html = $match[1];
		$rows = [];

		preg_match_all( '/<tr[^>]*>(.*?)<\/tr>/si', $html, $tr_matches );

		foreach ( $tr_matches[1] as $tr ) {
			$cells = [];
			preg_match_all( '/<(?:td|th)[^>]*>(.*?)<\/(?:td|th)>/si', $tr, $td_matches );
			foreach ( $td_matches[1] as $cell ) {
				$cells[] = trim( wp_strip_all_tags( $cell ) );
			}
			$rows[] = $cells;
		}

		if ( empty( $rows ) ) {
			return $match[0];
		}

		$md = "\n";
		foreach ( $rows as $i => $cells ) {
			$md .= '| ' . implode( ' | ', $cells ) . " |\n";
			if ( $i === 0 ) {
				$md .= '| ' . implode( ' | ', array_fill( 0, count( $cells ), '---' ) ) . " |\n";
			}
		}

		return $md . "\n";
	}
}
