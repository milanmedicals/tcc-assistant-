<?php
/**
 * What the assistant earned, and what it learned about demand.
 *
 *  TCCA_Events: a small log of sales-relevant moments (product shown, added to cart, checkout tapped, order
 *  placed after a chat, brand names resolved, searches that found nothing). It holds no names and no free text
 *  except the search words of a miss, which are redacted. Revenue is attributed only when the visitor's browser
 *  carries the chat's random session id (a first-party cookie set once they use the chat; see "Measure sales").
 *
 *  TCCA_Leads: people who asked to be told when a product is back, or asked you to source something you do not
 *  list. These hold an email address, are shown to you in Insights, and are deleted after the retention window.
 *
 * @package TCCAssistant
 */

defined( 'ABSPATH' ) || exit;

class TCCA_Events {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'tcca_events';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$t} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created datetime NOT NULL,
				session varchar(32) NOT NULL DEFAULT '',
				type varchar(12) NOT NULL DEFAULT '',
				pid bigint(20) unsigned NOT NULL DEFAULT 0,
				ref varchar(120) NOT NULL DEFAULT '',
				qty int(11) NOT NULL DEFAULT 0,
				v1 decimal(12,2) NOT NULL DEFAULT 0,
				v2 decimal(12,2) NOT NULL DEFAULT 0,
				v3 decimal(12,2) NOT NULL DEFAULT 0,
				note varchar(120) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY created (created),
				KEY type_created (type,created),
				KEY session (session)
			) {$charset};"
		);
	}

	public static function boot() {
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_order' ), 20, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_order' ), 20, 1 );
		add_action( 'tcca_purge', array( __CLASS__, 'purge' ) );
	}

	/**
	 * Shop staff testing the chat are not counted (it would flatter the numbers), unless a filter says so.
	 */
	public static function counts_this_visitor() {
		$staff = is_user_logged_in() && current_user_can( 'manage_woocommerce' );
		return ! $staff || (bool) apply_filters( 'tcca_track_staff', false );
	}

	/**
	 * Record one moment. Duplicates (same visitor, same thing, same day) are ignored for the "seen" kinds.
	 *
	 * @param string $type    show|add|checkout|order|brand|concern|miss|restock|request
	 * @param string $session Chat session id.
	 * @param array  $a       pid, ref, qty, v1, v2, v3, note.
	 */
	public static function log( $type, $session, $a = array() ) {
		$needs_attr = in_array( $type, array( 'show', 'add', 'checkout', 'order' ), true );
		if ( ( $needs_attr && '1' !== TCCA_Core::get( 'attr_on' ) ) || ( ! $needs_attr && '1' !== TCCA_Core::get( 'log_on' ) ) || ! self::counts_this_visitor() ) {
			return;
		}
		global $wpdb;
		$t       = self::table();
		$session = substr( preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $session ) ), 0, 32 );
		$a       = array_merge( array( 'pid' => 0, 'ref' => '', 'qty' => 0, 'v1' => 0, 'v2' => 0, 'v3' => 0, 'note' => '' ), $a );
		$ref     = mb_substr( TCCA_Core::redact( (string) $a['ref'] ), 0, 120 );
		if ( in_array( $type, array( 'show', 'brand', 'concern', 'miss', 'checkout' ), true ) ) {
			$dup = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE session = %s AND type = %s AND pid = %d AND ref = %s AND created > %s LIMIT 1", $session, $type, (int) $a['pid'], $ref, gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
			if ( $dup ) {
				return;
			}
		}
		TCCA_Insights::mirror( $type, $a ); // Optional: shows chat activity on the customer's TCC Insights timeline.
		$wpdb->insert( // phpcs:ignore WordPress.DB
			$t,
			array(
				'created' => gmdate( 'Y-m-d H:i:s' ),
				'session' => $session,
				'type'    => substr( sanitize_key( $type ), 0, 12 ),
				'pid'     => absint( $a['pid'] ),
				'ref'     => $ref,
				'qty'     => (int) $a['qty'],
				'v1'      => (float) $a['v1'],
				'v2'      => (float) $a['v2'],
				'v3'      => (float) $a['v3'],
				'note'    => mb_substr( sanitize_text_field( (string) $a['note'] ), 0, 120 ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%d', '%f', '%f', '%f', '%s' )
		);
	}

	/**
	 * The session id the browser carries (set by the widget once the visitor uses the chat).
	 */
	private static function cookie_sid() {
		$sid = isset( $_COOKIE['tcca_sid'] ) ? preg_replace( '/[^a-z0-9]/', '', strtolower( sanitize_text_field( wp_unslash( $_COOKIE['tcca_sid'] ) ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return strlen( $sid ) >= 8 ? substr( $sid, 0, 32 ) : '';
	}

	/**
	 * An order was placed: was it after a chat, and did the chat add or show any of what was bought?
	 *
	 * @param int|WC_Order $a Order or order id (classic checkout) or order (blocks checkout).
	 */
	public static function on_order( $a ) {
		if ( '1' !== TCCA_Core::get( 'attr_on' ) || ! self::counts_this_visitor() ) {
			return;
		}
		$order = is_object( $a ) ? $a : wc_get_order( absint( $a ) );
		$sid   = self::cookie_sid();
		if ( ! $order || '' === $sid || $order->get_meta( '_tcca_session' ) ) {
			return;
		}
		global $wpdb;
		$t     = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
		$chat  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}tcca_log WHERE session = %s AND created >= %s", $sid, $since ) ); // phpcs:ignore WordPress.DB
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT type, pid FROM {$t} WHERE session = %s AND type IN ('add','show') AND pid > 0 AND created >= %s", $sid, $since ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $chat && ! $rows ) {
			return;
		}
		$added = array();
		$shown = array();
		foreach ( $rows as $r ) {
			if ( 'add' === $r['type'] ) {
				$added[ (int) $r['pid'] ] = true;
			} else {
				$shown[ (int) $r['pid'] ] = true;
			}
		}
		$v_add = 0.0;
		$v_shw = 0.0;
		$n     = 0;
		foreach ( $order->get_items() as $it ) {
			$n  += (int) $it->get_quantity();
			$pid = (int) $it->get_product_id();
			$lt  = (float) $it->get_total();
			if ( isset( $added[ $pid ] ) ) {
				$v_add += $lt;
			} elseif ( isset( $shown[ $pid ] ) ) {
				$v_shw += $lt;
			}
		}
		$note = '';
		foreach ( (array) $order->get_coupon_codes() as $code ) {
			$cp = new WC_Coupon( $code );
			if ( $cp->get_meta( '_tcca_ekey' ) ) {
				$note = 'chatcode;disc=' . number_format( (float) $order->get_discount_total(), 2, '.', '' );
				break;
			}
		}
		$order->update_meta_data( '_tcca_session', $sid );
		$order->update_meta_data( '_tcca_added', $v_add );
		$order->save_meta_data();
		self::log( 'order', $sid, array( 'ref' => (string) $order->get_id(), 'qty' => $n, 'v1' => (float) $order->get_total(), 'v2' => $v_add, 'v3' => $v_shw, 'note' => $note ) );
	}

	/**
	 * Keep events for 180 days (they hold no personal data); leads are purged by TCCA_Leads.
	 */
	public static function purge() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created < %s', gmdate( 'Y-m-d H:i:s', time() - 180 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
		TCCA_Leads::purge();
	}

	/* ---------------------------------------------------------------- report */

	/**
	 * Everything the Insights tab shows about sales impact.
	 *
	 * @param int $days Window.
	 * @return array
	 */
	public static function report( $days = 30 ) {
		$key = 'tcca_rep_' . (int) $days;
		$hit = get_transient( $key );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		global $wpdb;
		$t     = self::table();
		$log   = $wpdb->prefix . 'tcca_log';
		$since = gmdate( 'Y-m-d H:i:s', time() - (int) $days * DAY_IN_SECONDS );
		$q     = static function ( $sql, $args = array() ) use ( $wpdb ) {
			return $args ? $wpdb->prepare( $sql, $args ) : $sql; // phpcs:ignore WordPress.DB
		};
		$r = array( 'days' => (int) $days );
		$r['chats']     = (int) $wpdb->get_var( $q( "SELECT COUNT(DISTINCT session) FROM {$log} WHERE created >= %s", array( $since ) ) ); // phpcs:ignore WordPress.DB
		$r['shown']     = (int) $wpdb->get_var( $q( "SELECT COUNT(DISTINCT session) FROM {$t} WHERE type = 'show' AND created >= %s", array( $since ) ) ); // phpcs:ignore WordPress.DB
		$r['added']     = (int) $wpdb->get_var( $q( "SELECT COUNT(DISTINCT session) FROM {$t} WHERE type = 'add' AND created >= %s", array( $since ) ) ); // phpcs:ignore WordPress.DB
		$r['items']     = (int) $wpdb->get_var( $q( "SELECT COALESCE(SUM(qty),0) FROM {$t} WHERE type = 'add' AND created >= %s", array( $since ) ) ); // phpcs:ignore WordPress.DB
		$r['checkouts'] = (int) $wpdb->get_var( $q( "SELECT COUNT(DISTINCT session) FROM {$t} WHERE type = 'checkout' AND created >= %s", array( $since ) ) ); // phpcs:ignore WordPress.DB

		// Orders placed after a chat, re-checked against each order's CURRENT status so cancelled, failed and refunded ones do not count.
		$r += array( 'orders' => 0, 'revenue' => 0.0, 'direct' => 0.0, 'waiting' => 0, 'waiting_value' => 0.0, 'discount' => 0.0, 'coded' => 0 );
		$rows = (array) $wpdb->get_results( $q( "SELECT ref, v2, note FROM {$t} WHERE type = 'order' AND created >= %s ORDER BY id DESC LIMIT 500", array( $since ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( $rows as $row ) {
			$o = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $row['ref'] ) : false;
			if ( ! $o ) {
				continue;
			}
			$net = max( 0.0, (float) $o->get_total() - (float) $o->get_total_refunded() );
			if ( in_array( $o->get_status(), array( 'processing', 'completed' ), true ) ) {
				$r['orders']++;
				$r['revenue'] += $net;
				$r['direct']  += min( $net, (float) $row['v2'] );
				if ( preg_match( '/disc=([\d.]+)/', (string) $row['note'], $m ) ) {
					$r['discount'] += (float) $m[1];
					$r['coded']++;
				}
			} elseif ( in_array( $o->get_status(), array( 'on-hold', 'pending' ), true ) ) {
				$r['waiting']++;
				$r['waiting_value'] += $net;
			}
		}

		// Chat discount codes issued and used.
		$ids = get_posts( array( 'post_type' => 'shop_coupon', 'post_status' => 'any', 'posts_per_page' => 500, 'fields' => 'ids', 'no_found_rows' => true, 'meta_key' => '_tcca_ekey', 'date_query' => array( array( 'after' => (int) $days . ' days ago' ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$r['codes']      = count( $ids );
		$r['codes_used'] = 0;
		foreach ( $ids as $id ) {
			if ( (int) get_post_meta( $id, 'usage_count', true ) > 0 ) {
				$r['codes_used']++;
			}
		}

		$top = static function ( $type, $limit = 8 ) use ( $wpdb, $t, $since ) {
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT ref, COUNT(DISTINCT session) c FROM {$t} WHERE type = %s AND created >= %s AND ref <> '' GROUP BY ref ORDER BY c DESC, MAX(id) DESC LIMIT %d", $type, $since, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
		};
		$r['miss']    = $top( 'miss', 10 );
		$r['brands']  = $top( 'brand', 8 );
		$r['concern'] = $top( 'concern', 8 );
		$byp = static function ( $type ) use ( $wpdb, $t, $since ) {
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT pid, COUNT(DISTINCT session) c, COALESCE(SUM(qty),0) q FROM {$t} WHERE type = %s AND pid > 0 AND created >= %s GROUP BY pid ORDER BY c DESC LIMIT 6", $type, $since ), ARRAY_A ); // phpcs:ignore WordPress.DB
		};
		$r['top_shown'] = $byp( 'show' );
		$r['top_added'] = $byp( 'add' );
		$lt             = TCCA_Leads::table();
		$r['restock']   = (int) $wpdb->get_var( $q( "SELECT COUNT(*) FROM {$lt} WHERE kind = 'restock' AND created >= %s", array( $since ) ) ); // phpcs:ignore WordPress.DB
		$r['restock_back'] = (int) $wpdb->get_var( $q( "SELECT COUNT(*) FROM {$lt} WHERE kind = 'restock' AND notified IS NOT NULL AND created >= %s", array( $since ) ) ); // phpcs:ignore WordPress.DB
		$r['requests']  = (int) $wpdb->get_var( $q( "SELECT COUNT(*) FROM {$lt} WHERE kind = 'request' AND created >= %s", array( $since ) ) ); // phpcs:ignore WordPress.DB
		set_transient( $key, $r, 5 * MINUTE_IN_SECONDS );
		return $r;
	}
}

class TCCA_Leads {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'tcca_leads';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$t} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created datetime NOT NULL,
				kind varchar(10) NOT NULL DEFAULT '',
				pid bigint(20) unsigned NOT NULL DEFAULT 0,
				term varchar(160) NOT NULL DEFAULT '',
				email varchar(190) NOT NULL DEFAULT '',
				name varchar(80) NOT NULL DEFAULT '',
				session varchar(32) NOT NULL DEFAULT '',
				status varchar(10) NOT NULL DEFAULT 'new',
				notified datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY kind_status (kind,status),
				KEY pid (pid),
				KEY created (created)
			) {$charset};"
		);
	}

	public static function boot() {
		add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'on_stock_status' ), 20, 2 );
		add_action( 'woocommerce_variation_set_stock_status', array( __CLASS__, 'on_stock_status' ), 20, 2 );
		add_action( 'admin_post_tcca_lead_done', array( __CLASS__, 'mark_done' ) );
	}

	/**
	 * Save a lead. One per email per product (or per request wording).
	 *
	 * @return string 'ok'|'dupe'|'error'
	 */
	public static function add( $kind, $email, $pid, $term, $name, $session ) {
		global $wpdb;
		$email = strtolower( sanitize_email( $email ) );
		if ( ! in_array( $kind, array( 'restock', 'request' ), true ) || ! is_email( $email ) ) {
			return 'error';
		}
		$t    = self::table();
		$term = mb_substr( sanitize_text_field( $term ), 0, 160 );
		$dup  = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE kind = %s AND email = %s AND pid = %d AND term = %s AND status = 'new' LIMIT 1", $kind, $email, (int) $pid, $term ) ); // phpcs:ignore WordPress.DB
		if ( $dup ) {
			return 'dupe';
		}
		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB
			$t,
			array(
				'created' => gmdate( 'Y-m-d H:i:s' ),
				'kind'    => $kind,
				'pid'     => absint( $pid ),
				'term'    => $term,
				'email'   => $email,
				'name'    => mb_substr( sanitize_text_field( $name ), 0, 80 ),
				'session' => substr( preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $session ) ), 0, 32 ),
				'status'  => 'new',
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( ! $ok ) {
			return 'error';
		}
		TCCA_Events::log( $kind, $session, array( 'pid' => (int) $pid, 'ref' => $term ) );
		delete_transient( 'tcca_rep_30' );
		return 'ok';
	}

	/**
	 * A product came back: tell everyone who asked, once.
	 *
	 * @param int    $product_id Product or variation id.
	 * @param string $status     New stock status.
	 */
	public static function on_stock_status( $product_id, $status ) {
		if ( 'instock' !== $status || '1' !== TCCA_Core::get( 'restock_on' ) ) {
			return;
		}
		$ids = array( (int) $product_id );
		$p   = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
		if ( $p && $p->is_type( 'variation' ) ) {
			$ids[] = (int) $p->get_parent_id();
		}
		global $wpdb;
		$t    = self::table();
		$in   = implode( ',', array_map( 'intval', $ids ) );
		$rows = (array) $wpdb->get_results( "SELECT id, email, pid FROM {$t} WHERE kind = 'restock' AND status = 'new' AND notified IS NULL AND pid IN ({$in}) LIMIT 200", ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( $rows as $r ) {
			$prod = wc_get_product( (int) $r['pid'] );
			if ( ! $prod || ! $prod->is_in_stock() ) {
				continue;
			}
			$name = wp_strip_all_tags( $prod->get_name() );
			$ok   = TCCA_Mail::send(
				$r['email'],
				sprintf( /* translators: %s: product */ __( '%s is back in stock', 'tcc-assistant' ), $name ),
				array(
					sprintf( __( 'Good news: %s is back in stock.', 'tcc-assistant' ), $name ),
					__( 'You asked us to let you know. This is the only email we will send about it.', 'tcc-assistant' ),
				),
				array( __( 'View the product', 'tcc-assistant' ), get_permalink( $prod->get_id() ) )
			);
			if ( $ok ) {
				$wpdb->update( $t, array( 'notified' => gmdate( 'Y-m-d H:i:s' ), 'status' => 'done' ), array( 'id' => (int) $r['id'] ), array( '%s', '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB
			}
		}
	}

	/**
	 * Open leads for the Insights tab.
	 *
	 * @return array
	 */
	public static function open( $kind, $limit = 30 ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, created, pid, term, email, name FROM ' . self::table() . " WHERE kind = %s AND status = 'new' ORDER BY id DESC LIMIT %d", $kind, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Per-product count of people waiting for a restock.
	 *
	 * @return array
	 */
	public static function waiting() {
		global $wpdb;
		return (array) $wpdb->get_results( 'SELECT pid, COUNT(*) c FROM ' . self::table() . " WHERE kind = 'restock' AND status = 'new' AND notified IS NULL GROUP BY pid ORDER BY c DESC LIMIT 15", ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public static function mark_done() {
		check_admin_referer( 'tcca_lead_done' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed' );
		}
		global $wpdb;
		$wpdb->update( self::table(), array( 'status' => 'done' ), array( 'id' => absint( $_POST['id'] ?? 0 ) ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB
		wp_safe_redirect( admin_url( 'options-general.php?page=tcca&tab=insights&saved=1' ) );
		exit;
	}

	/**
	 * Delete finished leads and stale ones after the retention window (they hold an email address).
	 */
	public static function purge() {
		global $wpdb;
		$t   = self::table();
		$cut = gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) TCCA_Core::get( 'retention_days' ) ) * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE status = 'done' AND created < %s", $cut ) ); // phpcs:ignore WordPress.DB
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE created < %s", gmdate( 'Y-m-d H:i:s', time() - 180 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
	}
}
