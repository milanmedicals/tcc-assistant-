<?php
/**
 * Knowledge index: the assistant's own searchable database, built from the site's public content
 * (products, categories, blog posts, pages, FAQs). Answers are quoted from it; nothing is generated.
 *
 * One row per passage (about 650 characters). Search is MySQL/MariaDB FULLTEXT over stemmed terms,
 * re-ranked by how many of the question's words the passage actually contains.
 *
 * @package TCCAssistant
 */

defined( 'ABSPATH' ) || exit;

class TCCA_Index {

	const TOKV   = 5;    // Bump when word handling changes: forces every passage to be re-indexed.
	const STRONG = 0.6;  // Share of the question's words a passage must contain to be quoted as the answer.
	const WEAK   = 0.30; // Between WEAK and STRONG: give the closest passage, clearly hedged.

	private static $queue = array();

	/** @var string[] Query words that actually occur somewhere on this site (set by idf()). */
	private static $known = array();

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'tcca_index';
	}

	public static function enabled() {
		return '1' === TCCA_Core::get( 'idx_on' );
	}

	/* ---------------------------------------------------------------- schema */

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$t} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				type varchar(12) NOT NULL DEFAULT '',
				obj_id bigint(20) unsigned NOT NULL DEFAULT 0,
				part varchar(12) NOT NULL DEFAULT '',
				title varchar(200) NOT NULL DEFAULT '',
				url varchar(500) NOT NULL DEFAULT '',
				body mediumtext NOT NULL,
				terms mediumtext NOT NULL,
				title_terms text NOT NULL,
				hash char(32) NOT NULL DEFAULT '',
				updated datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY obj (type,obj_id),
				FULLTEXT KEY ft_terms (terms),
				FULLTEXT KEY ft_title (title_terms)
			) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}tcca_learn (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				sig char(32) NOT NULL DEFAULT '',
				tokens varchar(300) NOT NULL DEFAULT '',
				type varchar(12) NOT NULL DEFAULT '',
				obj_id bigint(20) unsigned NOT NULL DEFAULT 0,
				part varchar(12) NOT NULL DEFAULT '',
				pos int(11) NOT NULL DEFAULT 0,
				neg int(11) NOT NULL DEFAULT 0,
				updated datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uniq (sig,type,obj_id,part),
				KEY sig (sig)
			) {$charset};"
		);
	}

	/* ----------------------------------------------------------------- hooks */

	public static function boot() {
		add_action( 'save_post', array( __CLASS__, 'on_save' ), 99, 2 );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'on_product' ), 99 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete_post' ) );
		foreach ( array( 'created_product_cat', 'edited_product_cat' ) as $h ) {
			add_action( $h, array( __CLASS__, 'on_term' ), 99 );
		}
		add_action( 'delete_product_cat', array( __CLASS__, 'on_term_delete' ) );
		add_action( 'shutdown', array( __CLASS__, 'flush_queue' ) );
		add_action( 'tcca_drain', array( __CLASS__, 'drain' ) );
		add_action( 'tcca_build', array( __CLASS__, 'chain' ) );
		add_action( 'wp_ajax_tcca_rebuild', array( __CLASS__, 'ajax_rebuild' ) );
		add_action( 'wp_ajax_tcca_test', array( __CLASS__, 'ajax_test' ) );
	}

	private static function post_types() {
		$types = array();
		if ( '1' === TCCA_Core::get( 'idx_posts' ) ) {
			$types[] = 'post';
		}
		if ( '1' === TCCA_Core::get( 'idx_pages' ) ) {
			$types[] = 'page';
		}
		if ( '1' === TCCA_Core::get( 'idx_products' ) ) {
			$types[] = 'product';
		}
		foreach ( array_filter( array_map( 'sanitize_key', explode( ',', TCCA_Core::get( 'idx_cpts' ) ) ) ) as $c ) {
			if ( post_type_exists( $c ) ) {
				$types[] = $c;
			}
		}
		return array_values( array_unique( $types ) );
	}

	public static function on_save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! $post ) {
			return;
		}
		if ( in_array( $post->post_type, self::post_types(), true ) ) {
			self::$queue[ 'p' . $post_id ] = array( 'p', (int) $post_id );
		}
	}

	public static function on_product( $id ) {
		if ( '1' === TCCA_Core::get( 'idx_products' ) ) {
			self::$queue[ 'p' . $id ] = array( 'p', (int) $id );
		}
	}

	public static function on_delete_post( $id ) {
		self::remove( 'p', (int) $id );
	}

	public static function on_term( $term_id ) {
		if ( '1' === TCCA_Core::get( 'idx_cats' ) ) {
			self::$queue[ 't' . $term_id ] = array( 't', (int) $term_id );
		}
	}

	public static function on_term_delete( $term_id ) {
		self::remove( 't', (int) $term_id );
	}

	/**
	 * Update what changed in this request. A bulk import hands the rest to a background job.
	 */
	public static function flush_queue() {
		if ( ! self::$queue ) {
			return;
		}
		$items = array_values( self::$queue );
		self::$queue = array();
		foreach ( array_slice( $items, 0, 25 ) as $it ) {
			self::upsert( $it[0], $it[1] );
		}
		if ( count( $items ) > 25 ) {
			$pending = (array) get_option( 'tcca_queue', array() );
			update_option( 'tcca_queue', array_merge( $pending, array_slice( $items, 25 ) ), false );
			if ( ! wp_next_scheduled( 'tcca_drain' ) ) {
				wp_schedule_single_event( time() + 30, 'tcca_drain' );
			}
		}
	}

	public static function drain() {
		$pending = (array) get_option( 'tcca_queue', array() );
		foreach ( array_splice( $pending, 0, 40 ) as $it ) {
			self::upsert( $it[0], $it[1] );
		}
		update_option( 'tcca_queue', $pending, false );
		if ( $pending ) {
			wp_schedule_single_event( time() + 30, 'tcca_drain' );
		}
	}

	/* -------------------------------------------------------------- building */

	/**
	 * Should this post stay out of the assistant's knowledge?
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	private static function excluded( $post ) {
		if ( 'publish' !== $post->post_status || '' !== $post->post_password ) {
			return true;
		}
		$skip = array();
		foreach ( array( 'cart', 'checkout', 'myaccount' ) as $pg ) {
			if ( function_exists( 'wc_get_page_id' ) ) {
				$skip[] = (int) wc_get_page_id( $pg );
			}
		}
		foreach ( array_filter( array_map( 'trim', explode( ',', TCCA_Core::get( 'idx_exclude' ) ) ) ) as $x ) {
			if ( ctype_digit( $x ) ) {
				$skip[] = (int) $x;
			} elseif ( $post->post_name === sanitize_title( $x ) ) {
				return true;
			}
		}
		if ( in_array( (int) $post->ID, $skip, true ) ) {
			return true;
		}
		// Respect "noindex" set by the common SEO plugins.
		if ( '1' === (string) get_post_meta( $post->ID, '_yoast_wpseo_meta-robots-noindex', true ) || false !== stripos( (string) maybe_serialize( get_post_meta( $post->ID, 'rank_math_robots', true ) ), 'noindex' ) ) {
			return true;
		}
		return (bool) apply_filters( 'tcca_exclude_post', false, $post );
	}

	/**
	 * Split text into passages of about $max characters on paragraph/sentence boundaries.
	 *
	 * @param string $text Plain text.
	 * @param int    $max  Characters.
	 * @return string[]
	 */
	public static function chunk( $text, $max = 650 ) {
		$out = array();
		$cur = '';
		$add = static function ( $piece ) use ( &$out, &$cur, $max ) {
			if ( '' !== $cur && mb_strlen( $cur ) + mb_strlen( $piece ) + 1 > $max ) {
				$out[] = $cur;
				$cur   = '';
			}
			$cur .= ( '' === $cur ? '' : "\n" ) . $piece;
		};
		foreach ( preg_split( "/\n+/", (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( mb_strlen( $line ) > $max ) {
				foreach ( preg_split( '/(?<=[.!?])\s+/u', $line ) as $sentence ) {
					$add( $sentence );
				}
			} else {
				$add( $line );
			}
		}
		if ( '' !== $cur ) {
			$out[] = $cur;
		}
		return array_slice( $out, 0, 40 );
	}

	/**
	 * Passages for one object: array of [ part, title, text ].
	 *
	 * @param string $kind 'p' post-like or 't' category.
	 * @param int    $id   ID.
	 * @return array{url:string,rows:array}|null Null when it must not be indexed.
	 */
	private static function build( $kind, $id ) {
		$rows = array();
		if ( 't' === $kind ) {
			$term = get_term( $id, 'product_cat' );
			if ( ! $term || is_wp_error( $term ) ) {
				return null;
			}
			$desc = TCCA_Product::plain( $term->description );
			foreach ( self::chunk( $term->name . ( '' !== $desc ? "\n" . $desc : '' ) ) as $c ) {
				$rows[] = array( 'cat', html_entity_decode( $term->name ), $c );
			}
			$link = get_term_link( $term );
			return $rows ? array( 'type' => 'cat', 'url' => is_wp_error( $link ) ? '' : $link, 'rows' => $rows ) : null;
		}
		$post = get_post( $id );
		if ( ! $post || self::excluded( $post ) ) {
			return null;
		}
		$url = get_permalink( $id );
		if ( 'product' === $post->post_type ) {
			$p = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : false;
			if ( ! $p || ! $p->is_visible() ) {
				return null;
			}
			$pr   = TCCA_Product::profile( $p );
			$head = array( $pr['name'] );
			foreach ( $pr['comps'] as $c ) {
				$head[] = $c['name'] . ( $c['generic'] ? ' (' . $c['generic'] . ')' : '' ) . ( $c['class'] ? ' ' . $c['class'] : '' );
			}
			$head = array_merge( $head, $pr['cats'], array_values( $pr['attrs'] ) );
			$over = implode( "\n", array_filter( array( implode( ', ', $head ), $pr['short'], $pr['about'], $pr['how'] ) ) );
			foreach ( self::chunk( $over ) as $c ) {
				$rows[] = array( 'about', $pr['name'], $c );
			}
			foreach ( self::chunk( $pr['uses'] ) as $c ) {
				$rows[] = array( 'uses', $pr['name'], $c );
			}
			foreach ( self::chunk( $pr['safety'] ) as $c ) {
				$rows[] = array( 'safety', $pr['name'], $c );
			}
			foreach ( $pr['faqs'] as $f ) {
				$rows[] = array( 'faq', $pr['name'], trim( $f['q'] ) . "\n" . TCCA_Product::clip( TCCA_Product::plain( $f['a'] ), 900 ) );
			}
			return $rows ? array( 'type' => 'product', 'url' => $url, 'rows' => $rows ) : null;
		}
		$body = TCCA_Product::plain( strip_shortcodes( preg_replace( '/<!--.*?-->/s', '', (string) $post->post_content ) ) );
		$text = trim( $post->post_excerpt ? TCCA_Product::plain( $post->post_excerpt ) . "\n" . $body : $body );
		foreach ( self::chunk( $text ) as $c ) {
			$rows[] = array( 'body', wp_strip_all_tags( $post->post_title ), $c );
		}
		return $rows ? array( 'type' => 'page' === $post->post_type ? 'page' : 'post', 'url' => $url, 'rows' => $rows ) : null;
	}

	/**
	 * Index terms: stemmed, concept-mapped, duplicates kept so frequency counts.
	 */
	private static function terms( $text ) {
		return implode( ' ', TCCA_KB::tokens( $text, false ) );
	}

	/**
	 * Create or refresh the passages for one object. Unchanged content is skipped.
	 *
	 * @param string $kind 'p' or 't'.
	 * @param int    $id   ID.
	 * @return string 'skipped'|'updated'|'removed'
	 */
	public static function upsert( $kind, $id ) {
		global $wpdb;
		$t   = self::table();
		$b   = self::build( $kind, $id );
		$typ = $b ? $b['type'] : ( 't' === $kind ? 'cat' : '' );
		if ( ! $b ) {
			self::remove( $kind, $id );
			return 'removed';
		}
		$hash = md5( wp_json_encode( array( self::TOKV, $b['url'], $b['rows'] ) ) );
		$old  = $wpdb->get_var( $wpdb->prepare( "SELECT hash FROM {$t} WHERE type = %s AND obj_id = %d LIMIT 1", $typ, $id ) ); // phpcs:ignore WordPress.DB
		if ( $old === $hash ) {
			return 'skipped';
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE type = %s AND obj_id = %d", $typ, $id ) ); // phpcs:ignore WordPress.DB
		update_option( 'tcca_idxv', (int) get_option( 'tcca_idxv', 0 ) + 1, false );
		delete_transient( 'tcca_n' );
		foreach ( $b['rows'] as $r ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB
				$t,
				array(
					'type'        => $typ,
					'obj_id'      => $id,
					'part'        => $r[0],
					'title'       => mb_substr( $r[1], 0, 200 ),
					'url'         => mb_substr( $b['url'], 0, 500 ),
					'body'        => $r[2],
					'terms'       => self::terms( $r[2] ),
					'title_terms' => self::terms( $r[1] ),
					'hash'        => $hash,
					'updated'     => gmdate( 'Y-m-d H:i:s' ),
				),
				array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}
		return 'updated';
	}

	public static function remove( $kind, $id ) {
		global $wpdb;
		$types = 't' === $kind ? array( 'cat' ) : array( 'product', 'page', 'post' );
		foreach ( $types as $typ ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE type = %s AND obj_id = %d', $typ, $id ) ); // phpcs:ignore WordPress.DB
		}
	}

	/* --------------------------------------------------------------- rebuild */

	/**
	 * Every object that should be indexed right now.
	 *
	 * @return array<int,array{0:string,1:int}>
	 */
	private static function all_ids() {
		$list = array();
		$pt   = self::post_types();
		if ( $pt ) {
			foreach ( get_posts( array( 'post_type' => $pt, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true, 'orderby' => 'ID', 'order' => 'ASC' ) ) as $id ) {
				$list[] = array( 'p', (int) $id );
			}
		}
		if ( '1' === TCCA_Core::get( 'idx_cats' ) ) {
			$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $tid ) {
					$list[] = array( 't', (int) $tid );
				}
			}
		}
		return $list;
	}

	/**
	 * One slice of a full rebuild. Call repeatedly from offset 0 until done.
	 *
	 * @param int $offset Start.
	 * @param int $size   Items per slice.
	 * @return array{done:bool,next:int,total:int,updated:int}
	 */
	public static function rebuild_step( $offset, $size = 20 ) {
		if ( 0 === $offset ) {
			update_option( 'tcca_rb_list', self::all_ids(), false );
		}
		$list    = (array) get_option( 'tcca_rb_list', array() );
		$total   = count( $list );
		$updated = 0;
		foreach ( array_slice( $list, $offset, $size ) as $it ) {
			if ( 'skipped' !== self::upsert( $it[0], $it[1] ) ) {
				$updated++;
			}
		}
		$next = $offset + $size;
		$done = $next >= $total;
		if ( $done ) {
			self::finish( $list );
		}
		return array( 'done' => $done, 'next' => $next, 'total' => $total, 'updated' => $updated );
	}

	/**
	 * Remove passages of content that no longer exists or is no longer indexed; rebuild the spelling vocabulary.
	 */
	private static function finish( $list ) {
		global $wpdb;
		$t    = self::table();
		$keep = array();
		foreach ( $list as $it ) {
			$keep[ $it[0] . $it[1] ] = true;
		}
		foreach ( (array) $wpdb->get_results( "SELECT DISTINCT type, obj_id FROM {$t}" ) as $r ) { // phpcs:ignore WordPress.DB
			$k = ( 'cat' === $r->type ? 't' : 'p' ) . $r->obj_id;
			if ( ! isset( $keep[ $k ] ) ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE type = %s AND obj_id = %d", $r->type, $r->obj_id ) ); // phpcs:ignore WordPress.DB
			}
		}
		$vocab = array();
		foreach ( (array) $wpdb->get_col( "SELECT DISTINCT title FROM {$t}" ) as $title ) { // phpcs:ignore WordPress.DB
			foreach ( preg_split( '/[^a-z0-9]+/', strtolower( $title ), -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
				if ( strlen( $w ) >= 4 && ! ctype_digit( $w ) ) {
					$vocab[ $w ] = 1;
				}
			}
		}
		update_option( 'tcca_vocab', array_slice( $vocab, 0, 8000, true ), false );
		update_option( 'tcca_built', time(), false );
		delete_option( 'tcca_rb_list' );
	}

	/**
	 * Background full rebuild (cron): works through the list a slice at a time.
	 */
	public static function chain() {
		$off = (int) get_option( 'tcca_rb_off', 0 );
		$r   = self::rebuild_step( $off, 25 );
		if ( $r['done'] ) {
			delete_option( 'tcca_rb_off' );
			return;
		}
		update_option( 'tcca_rb_off', $r['next'], false );
		wp_schedule_single_event( time() + 20, 'tcca_build' );
	}

	public static function schedule_full() {
		delete_option( 'tcca_rb_off' );
		if ( ! wp_next_scheduled( 'tcca_build' ) ) {
			wp_schedule_single_event( time() + 5, 'tcca_build' );
		}
	}

	public static function ajax_rebuild() {
		check_ajax_referer( 'tcca_admin' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		wp_send_json_success( self::rebuild_step( max( 0, (int) ( $_POST['offset'] ?? 0 ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	public static function ajax_test() {
		check_ajax_referer( 'tcca_admin' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		$q    = sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$hits = self::search( $q, 6 );
		$out  = array();
		foreach ( $hits as $h ) {
			$out[] = array( 'title' => $h['title'], 'type' => $h['type'] . '/' . $h['part'], 'cov' => round( $h['cov'], 2 ), 'text' => mb_substr( $h['body'], 0, 160 ) );
		}
		$ans = $hits && $hits[0]['cov'] >= self::STRONG ? 'answer' : ( $hits && $hits[0]['cov'] >= self::WEAK ? 'links only' : 'no answer' );
		wp_send_json_success( array( 'verdict' => $ans, 'hits' => $out ) );
	}

	public static function stats() {
		global $wpdb;
		$t = self::table();
		return array(
			'rows'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ), // phpcs:ignore WordPress.DB
			'types' => (array) $wpdb->get_results( "SELECT type, COUNT(DISTINCT obj_id) n, COUNT(*) c FROM {$t} GROUP BY type" ), // phpcs:ignore WordPress.DB
			'built' => (int) get_option( 'tcca_built', 0 ),
		);
	}

	/* ---------------------------------------------------------------- search */

	/**
	 * Correct likely typos against words that appear in your product, category and article titles.
	 */
	private static function fix_typos( $query ) {
		$vocab = (array) get_option( 'tcca_vocab', array() );
		if ( ! $vocab ) {
			return $query;
		}
		$out = array();
		foreach ( preg_split( '/[^a-z0-9]+/', strtolower( $query ), -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
			if ( strlen( $w ) < 5 || isset( $vocab[ $w ] ) || isset( $vocab[ rtrim( $w, 's' ) ] ) || ctype_digit( $w ) ) {
				$out[] = $w;
				continue;
			}
			$best = $w;
			$dist = strlen( $w ) >= 8 ? 3 : 2;
			foreach ( $vocab as $v => $_ ) {
				if ( $v[0] === $w[0] && abs( strlen( $v ) - strlen( $w ) ) < $dist ) {
					$d = levenshtein( $w, $v );
					if ( $d < $dist ) {
						$dist = $d;
						$best = $v;
					}
				}
			}
			$out[] = $best;
		}
		return implode( ' ', $out );
	}

	/**
	 * How informative each query word is on THIS site: rare words (a drug name) count far more than common ones
	 * ("pen", "ship"). Words the site has never used get an average weight, so unknown topics lower confidence.
	 *
	 * @param string[] $qt Tokens.
	 * @return array<string,float>
	 */
	private static function idf( $qt ) {
		global $wpdb;
		$t = self::table();
		$n = (int) get_transient( 'tcca_n' );
		if ( $n < 1 ) {
			$n = max( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ) ); // phpcs:ignore WordPress.DB
			set_transient( 'tcca_n', $n, HOUR_IN_SECONDS );
		}
		$ver = (int) get_option( 'tcca_idxv', 0 );
		$out = array();
		$unk = array();
		foreach ( $qt as $w ) {
			$k  = 'tcca_df_' . $ver . '_' . md5( $w );
			$df = get_transient( $k );
			if ( false === $df ) {
				$df = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE MATCH(terms) AGAINST (%s IN BOOLEAN MODE) OR MATCH(title_terms) AGAINST (%s IN BOOLEAN MODE)", '+' . $w, '+' . $w ) ); // phpcs:ignore WordPress.DB
				set_transient( $k, $df, 6 * HOUR_IN_SECONDS );
			}
			if ( (int) $df < 1 ) {
				$unk[] = $w;
				continue;
			}
			$out[ $w ] = log( 1 + ( $n - (int) $df + 0.5 ) / ( (int) $df + 0.5 ) );
		}
		$known = array_keys( $out );
		$avg   = $out ? array_sum( $out ) / count( $out ) : 1.0;
		foreach ( $unk as $w ) {
			$out[ $w ] = $avg;
		}
		self::$known = $known;
		return $out;
	}

	/**
	 * Patterns the shop has learned from customers (thumbs up/down, tapped suggestions) that resemble this question.
	 *
	 * @param string[] $qt Tokens.
	 * @return array<string,array{pos:int,neg:int,type:string,obj_id:int,part:string}> Keyed "type|obj|part".
	 */
	private static function learned( $qt ) {
		global $wpdb;
		$rows = get_transient( 'tcca_learn_cache' );
		if ( ! is_array( $rows ) ) {
			$rows = (array) $wpdb->get_results( "SELECT tokens, type, obj_id, part, pos, neg FROM {$wpdb->prefix}tcca_learn ORDER BY updated DESC LIMIT 600", ARRAY_A ); // phpcs:ignore WordPress.DB
			set_transient( 'tcca_learn_cache', $rows, 5 * MINUTE_IN_SECONDS );
		}
		$out = array();
		$set = array_flip( $qt );
		foreach ( $rows as $r ) {
			$tok = array_filter( explode( ' ', $r['tokens'] ) );
			if ( ! $tok ) {
				continue;
			}
			$inter = 0;
			foreach ( $tok as $w ) {
				if ( isset( $set[ $w ] ) ) {
					$inter++;
				}
			}
			$jac = $inter / ( count( $tok ) + count( $qt ) - $inter );
			if ( $jac >= 0.6 ) {
				$k = $r['type'] . '|' . $r['obj_id'] . '|' . $r['part'];
				if ( ! isset( $out[ $k ] ) ) {
					$out[ $k ] = array( 'pos' => 0, 'neg' => 0, 'type' => $r['type'], 'obj_id' => (int) $r['obj_id'], 'part' => $r['part'] );
				}
				$out[ $k ]['pos'] += (int) $r['pos'];
				$out[ $k ]['neg'] += (int) $r['neg'];
			}
		}
		return $out;
	}

	/**
	 * Has this kind of question been marked "not helpful" for a given built-in answer more often than "helpful"?
	 */
	public static function demoted( $query, $type, $obj, $part ) {
		$qt = array_values( array_filter( TCCA_KB::tokens( $query ), static function ( $w ) {
			return strlen( $w ) >= 3;
		} ) );
		if ( ! $qt ) {
			return false;
		}
		$l = self::learned( $qt );
		$k = $type . '|' . $obj . '|' . $part;
		return isset( $l[ $k ] ) && $l[ $k ]['neg'] > $l[ $k ]['pos'];
	}

	/**
	 * Remember that an answer helped (or did not) for this kind of question.
	 *
	 * @param string $query Visitor question.
	 * @param array  $trace { t:type, o:obj_id, p:part }.
	 * @param int    $delta +1 helped, -1 did not.
	 */
	public static function learn( $query, $trace, $delta ) {
		global $wpdb;
		$tok = array_values( array_filter( TCCA_KB::tokens( $query ), static function ( $w ) {
			return strlen( $w ) >= 3;
		} ) );
		if ( ! $tok || empty( $trace['t'] ) || ! in_array( $trace['t'], array( 'product', 'cat', 'page', 'post', 'kb' ), true ) ) {
			return;
		}
		sort( $tok );
		$tok = array_slice( $tok, 0, 12 );
		$sig = md5( implode( ' ', $tok ) );
		$tbl = $wpdb->prefix . 'tcca_learn';
		$col = $delta > 0 ? 'pos' : 'neg';
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$tbl} (sig, tokens, type, obj_id, part, {$col}, updated) VALUES (%s, %s, %s, %d, %s, 1, %s) ON DUPLICATE KEY UPDATE {$col} = {$col} + 1, updated = VALUES(updated)", $sig, implode( ' ', $tok ), $trace['t'], (int) $trace['o'], substr( sanitize_key( $trace['p'] ), 0, 12 ), gmdate( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore WordPress.DB
		delete_transient( 'tcca_learn_cache' );
	}

	/**
	 * Best passages for a question. Each hit has 'cov': the rarity-weighted share of the question's words that
	 * the passage contains (0..1), after learned corrections. That number decides answer / hedge / ask again.
	 *
	 * @param string $query Question.
	 * @param int    $limit Max passages.
	 * @return array<int,array>
	 */
	public static function search( $query, $limit = 8 ) {
		global $wpdb;
		$q  = self::fix_typos( $query );
		$qt = array_values( array_filter( TCCA_KB::tokens( $q ), static function ( $w ) {
			return strlen( $w ) >= 3;
		} ) );
		if ( ! $qt ) {
			return array();
		}
		$idf = self::idf( $qt );
		$sum   = array_sum( $idf );
		$known = self::$known;
		$key = array_search( max( $idf ), $idf, true ); // The most telling word in the question.
		$ft  = implode( ' ', $qt );
		$t   = self::table();
		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT id, type, obj_id, part, title, url, body, terms, title_terms,
					MATCH(title_terms) AGAINST (%s) AS ts, MATCH(terms) AGAINST (%s) AS bs
				FROM {$t}
				WHERE MATCH(terms) AGAINST (%s) OR MATCH(title_terms) AGAINST (%s)
				ORDER BY (MATCH(title_terms) AGAINST (%s) * 2 + MATCH(terms) AGAINST (%s)) DESC
				LIMIT %d",
				$ft, $ft, $ft, $ft, $ft, $ft, max( 24, $limit * 3 )
			),
			ARRAY_A
		);
		$learn = self::learned( $qt );
		$have  = array();
		foreach ( $rows as $r ) {
			$have[ $r['type'] . '|' . $r['obj_id'] . '|' . $r['part'] ] = true;
		}
		// A passage customers confirmed for this kind of question is considered even if the words differ.
		foreach ( $learn as $k => $l ) {
			if ( $l['pos'] > $l['neg'] && ! isset( $have[ $k ] ) ) {
				$extra = $wpdb->get_row( $wpdb->prepare( "SELECT id, type, obj_id, part, title, url, body, terms, title_terms, 0 AS ts, 0 AS bs FROM {$t} WHERE type = %s AND obj_id = %d AND part = %s LIMIT 1", $l['type'], $l['obj_id'], $l['part'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
				if ( $extra ) {
					$rows[] = $extra;
				}
			}
		}
		if ( ! $rows ) {
			return array();
		}
		$max = 0.0001;
		foreach ( $rows as $r ) {
			$max = max( $max, 2 * (float) $r['ts'] + (float) $r['bs'] );
		}
		foreach ( $rows as &$r ) {
			$hay = array_flip( array_merge( explode( ' ', $r['terms'] ), explode( ' ', $r['title_terms'] ) ) );
			$got = 0.0;
			foreach ( $idf as $w => $weight ) {
				if ( isset( $hay[ $w ] ) ) {
					$got += $weight;
				}
			}
			$cov = $sum > 0 ? $got / $sum : 0.0;
			if ( count( $known ) >= 2 && count( array_intersect_key( array_flip( $known ), $hay ) ) < 2 ) {
				$cov = min( $cov, 0.28 ); // A multi-part question needs a passage that covers at least two of its real topics.
			}
			if ( ! isset( $hay[ $key ] ) ) {
				$cov = min( $cov, 0.28 ); // Missing the question's key word: not even a "closest match".
			}
			if ( 1 === count( $qt ) && ! in_array( $qt[0], explode( ' ', $r['title_terms'] ), true ) ) {
				$cov = min( $cov, 0.5 ); // A single word is only decisive when it is in the title.
			}
			$rank = 0.5 * ( ( 2 * (float) $r['ts'] + (float) $r['bs'] ) / $max ) + 0.5 * $cov;
			$lk   = $r['type'] . '|' . $r['obj_id'] . '|' . $r['part'];
			if ( isset( $learn[ $lk ] ) ) {
				$l = $learn[ $lk ];
				if ( $l['pos'] > $l['neg'] ) {
					$rank += 1.0; // What customers confirmed outranks raw text matching.
					$cov   = max( $cov, 0.65 ); // Customers confirmed this answer for this question.
				} elseif ( $l['neg'] > $l['pos'] ) {
					$rank -= 1.0;
					$cov   = min( $cov, 0.25 ); // Customers said it was wrong.
				}
			}
			$r['cov']  = $cov;
			$r['rank'] = $rank;
			$r['qt']   = $qt;
			$r['key']  = $key;
		}
		unset( $r );
		usort( $rows, static function ( $a, $b ) {
			return $b['rank'] <=> $a['rank'];
		} );
		return array_slice( $rows, 0, $limit );
	}

	/* --------------------------------------------------------------- answers */

	/**
	 * The sentences of a passage that best match the question, verbatim.
	 */
	public static function snippet( $body, $qt, $max = 380 ) {
		$parts = preg_split( '/(?<=[.!?])\s+|\n+/u', trim( $body ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! $parts ) {
			return TCCA_Product::clip( $body, $max );
		}
		$best  = 0;
		$score = -1;
		foreach ( $parts as $i => $s ) {
			$tok = array_flip( TCCA_KB::tokens( $s ) );
			$sc  = 0;
			foreach ( $qt as $w ) {
				if ( isset( $tok[ $w ] ) ) {
					$sc++;
				}
			}
			if ( $sc > $score ) {
				$score = $sc;
				$best  = $i;
			}
		}
		$out = $parts[ $best ];
		for ( $j = $best + 1; $j < count( $parts ) && mb_strlen( $out ) + mb_strlen( $parts[ $j ] ) + 1 <= $max; $j++ ) {
			$out .= ( "\n" === substr( $parts[ $j ], 0, 1 ) ? '' : ' ' ) . $parts[ $j ];
		}
		return TCCA_Product::clip( $out, $max );
	}

	private static function label( $h ) {
		$title = $h['title'];
		switch ( $h['type'] . '/' . $h['part'] ) {
			case 'product/uses':
				return sprintf( __( 'From the Uses section of %s:', 'tcc-assistant' ), $title );
			case 'product/safety':
				return sprintf( __( 'From the Safety section of %s:', 'tcc-assistant' ), $title );
			case 'product/faq':
				return sprintf( __( 'From the FAQ on %s:', 'tcc-assistant' ), $title );
			case 'product/about':
				return sprintf( __( 'From the %s page:', 'tcc-assistant' ), $title );
			case 'cat/cat':
				return sprintf( __( 'About %s:', 'tcc-assistant' ), $title );
			case 'page/body':
				return sprintf( __( 'From the page “%s”:', 'tcc-assistant' ), $title );
			default:
				return sprintf( __( 'From our article “%s”:', 'tcc-assistant' ), $title );
		}
	}

	private static function article_card( $h ) {
		$kind = array( 'product' => __( 'Product', 'tcc-assistant' ), 'cat' => __( 'Category', 'tcc-assistant' ), 'page' => __( 'Page', 'tcc-assistant' ), 'post' => __( 'Article', 'tcc-assistant' ) );
		return array(
			'type'    => 'article',
			'title'   => $h['title'],
			'excerpt' => self::snippet( $h['body'], $h['qt'], 150 ),
			'url'     => $h['url'],
			'kind'    => isset( $kind[ $h['type'] ] ) ? $kind[ $h['type'] ] : '',
		);
	}

	/**
	 * Other results from different pages, as link cards.
	 */
	private static function related( $hits, $skip_key, $n, $min ) {
		$cards = array();
		$seen  = array( $skip_key => true );
		foreach ( $hits as $h ) {
			$k = $h['type'] . $h['obj_id'];
			if ( isset( $seen[ $k ] ) || $h['cov'] < $min || '' === $h['url'] ) {
				continue;
			}
			$seen[ $k ] = true;
			$cards[]    = self::article_card( $h );
			if ( count( $cards ) >= $n ) {
				break;
			}
		}
		return $cards;
	}

	/**
	 * The answer, quoted from the best passage. Null unless the match is strong.
	 */
	public static function answer( $hits, $with_human = false ) {
		if ( ! $hits || $hits[0]['cov'] < self::STRONG ) {
			return null;
		}
		$h     = $hits[0];
		$label = self::label( $h );
		if ( 'faq' === $h['part'] ) {
			$qa   = explode( "\n", $h['body'], 2 );
			$text = $qa[0] . "\n" . ( isset( $qa[1] ) ? $qa[1] : '' );
		} else {
			$text = $label . "\n" . self::snippet( $h['body'], $h['qt'] );
		}
		$cards = array();
		if ( 'product' === $h['type'] && function_exists( 'wc_get_product' ) && ( $p = wc_get_product( (int) $h['obj_id'] ) ) ) {
			$cards[] = TCCA_Engine::product_card( $p );
		}
		$cards   = array_merge( $cards, self::related( $hits, $h['type'] . $h['obj_id'], 2, 0.45 ) );
		$actions = array();
		if ( '' !== $h['url'] ) {
			$actions[] = array( 'type' => 'link', 'label' => __( 'Read more', 'tcc-assistant' ), 'url' => $h['url'] );
		}
		if ( in_array( $h['type'], array( 'post', 'product' ), true ) && in_array( $h['part'], array( 'body', 'safety', 'uses', 'about' ), true ) ) {
			$text .= "\n\n" . __( 'This is general information from our site, not advice for your situation.', 'tcc-assistant' );
		}
		if ( $with_human ) {
			$actions = array_merge( $actions, TCCA_Engine::human_actions() );
		}
		return TCCA_Engine::out( 'knowledge', $text, array( 'cards' => $cards, 'actions' => $actions, 'fb' => true, 'trace' => array( 't' => $h['type'], 'o' => (int) $h['obj_id'], 'p' => $h['part'] ) ) );
	}

	/**
	 * Is the best passage specifically about what was asked (a full match whose page title shares the question's words)?
	 */
	public static function specific( $hits ) {
		if ( ! $hits || $hits[0]['cov'] < 0.7 || ! in_array( $hits[0]['type'], array( 'post', 'page' ), true ) ) {
			return false;
		}
		return (bool) array_intersect( explode( ' ', $hits[0]['title_terms'] ), $hits[0]['qt'] );
	}

	/**
	 * Medium confidence: still give the best passage, but say plainly it is the closest match, and keep a human one tap away.
	 */
	public static function closest( $hits ) {
		if ( ! $hits || $hits[0]['cov'] < self::WEAK || $hits[0]['cov'] >= self::STRONG ) {
			return null;
		}
		$h    = $hits[0];
		$text = __( 'This is the closest information I have, in case it helps:', 'tcc-assistant' ) . "\n" . self::label( $h ) . "\n" . ( 'faq' === $h['part'] ? $h['body'] : self::snippet( $h['body'], $h['qt'] ) );
		$acts = array();
		if ( '' !== $h['url'] ) {
			$acts[] = array( 'type' => 'link', 'label' => __( 'Read more', 'tcc-assistant' ), 'url' => $h['url'] );
		}
		return TCCA_Engine::out( 'knowledge_mid', $text, array(
			'cards'   => self::related( $hits, $h['type'] . $h['obj_id'], 2, self::WEAK ),
			'actions' => array_merge( $acts, TCCA_Engine::human_actions() ),
			'fb'      => true,
			'trace'   => array( 't' => $h['type'], 'o' => (int) $h['obj_id'], 'p' => $h['part'] ),
		) );
	}

	/**
	 * Low confidence: ask what they meant, offering the nearest topics as tappable suggestions.
	 *
	 * @param array  $hits  Search hits.
	 * @param string $query The original question (kept so a tapped suggestion teaches the assistant).
	 * @return array|null Chips of [label, message, from].
	 */
	public static function suggestions( $hits, $query ) {
		$chips = array();
		$seen  = array();
		foreach ( $hits as $h ) {
			if ( $h['cov'] < 0.12 || isset( $seen[ $h['type'] . $h['obj_id'] ] ) || 'cat' === $h['type'] && ! $h['title'] ) {
				continue;
			}
			$seen[ $h['type'] . $h['obj_id'] ] = true;
			$chips[] = array( mb_strimwidth( $h['title'], 0, 44, '…' ), $h['title'], $query );
			if ( count( $chips ) >= 3 ) {
				break;
			}
		}
		return $chips;
	}

	/**
	 * Passages as context for the optional language model (retrieval-augmented answers).
	 */
	public static function context( $hits, $n = 3 ) {
		$out = array();
		foreach ( array_slice( $hits, 0, $n ) as $h ) {
			if ( $h['cov'] >= self::WEAK ) {
				$out[] = array( 'q' => $h['title'], 'a' => $h['title'] . ': ' . $h['body'] );
			}
		}
		return $out;
	}
}
