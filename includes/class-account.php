<?php
/**
 * Customer lookup by email, member (club) number or order number.
 *
 * Customers: signed-in users are recognised automatically; guests prove they own the email with a 6-digit code
 * (sent to the address on file, never shown). Staff (capability manage_woocommerce) can look anyone up directly.
 * Only orders, member number and first name are ever shown to customers; never addresses, phones or payment data.
 *
 * @package TCCAssistant
 */

defined( 'ABSPATH' ) || exit;

class TCCA_Account {

	/** @var string Verification token sent with the current request (from the browser's session). */
	private static $vtoken = '';

	public static function set_context( $ctx ) {
		self::$vtoken = isset( $ctx['vtoken'] ) ? preg_replace( '/[^a-f0-9]/', '', (string) $ctx['vtoken'] ) : '';
	}

	/* ---------------------------------------------------------------- identity */

	public static function staff() {
		return is_user_logged_in() && current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Who is this visitor, provably? Signed-in user, or someone who entered the emailed code.
	 *
	 * @return array|null { uid, email, via: login|code }
	 */
	public static function identity() {
		if ( is_user_logged_in() ) {
			$u = wp_get_current_user();
			return array( 'uid' => (int) $u->ID, 'email' => strtolower( (string) $u->user_email ), 'via' => 'login' );
		}
		if ( 32 === strlen( self::$vtoken ) ) {
			$v = get_transient( 'tcca_v_' . md5( self::$vtoken ) );
			if ( is_array( $v ) ) {
				return array( 'uid' => (int) $v['uid'], 'email' => (string) $v['email'], 'via' => 'code' );
			}
		}
		return null;
	}

	/* ------------------------------------------------------------- recognition */

	/**
	 * Pull an email, an 8-digit member number and an order number out of free text.
	 *
	 * @return array{email:string,member:string,order:string}
	 */
	public static function extract( $m ) {
		$out = array( 'email' => '', 'member' => '', 'order' => '' );
		if ( preg_match( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $m, $x ) ) {
			$out['email'] = strtolower( $x[0] );
		}
		if ( preg_match( '/(?<!\d)(\d{4})[\s\-]?(\d{4})(?!\d)/', $m, $x ) ) {
			$raw = $x[1] . $x[2];
			if ( ! function_exists( 'tcc_member_no_valid' ) || tcc_member_no_valid( $raw ) ) {
				$out['member'] = $raw;
			}
		}
		if ( '' === $out['member'] && preg_match( '/(?:order\s*(?:no\.?|number|#)?\s*|#)(\d{2,10})\b/i', $m, $x ) ) {
			$out['order'] = $x[1];
		}
		return $out;
	}

	/**
	 * A single typed identifier: an email, an 8-digit member number, or an order number.
	 */
	public static function extract_identifier( $s ) {
		$s   = trim( (string) $s );
		$out = array( 'email' => '', 'member' => '', 'order' => '' );
		if ( is_email( $s ) ) {
			$out['email'] = strtolower( $s );
			return $out;
		}
		$d = preg_replace( '/\D/', '', ltrim( $s, '#' ) );
		if ( 8 === strlen( $d ) && ( ! function_exists( 'tcc_member_no_valid' ) || tcc_member_no_valid( $d ) ) ) {
			$out['member'] = $d;
		} elseif ( '' !== $d && strlen( $d ) <= 10 ) {
			$out['order'] = $d;
		}
		return $out;
	}

	/**
	 * Does the visitor want their own account, orders or membership details?
	 */
	public static function intent( $m ) {
		return (bool) preg_match( '/\b(my (?:account|details|profile|membership|orders|order history|purchase history|past orders|previous orders|recent orders|member(?:ship)? (?:card|number|no|id)|club (?:card|number|no|id))|order history|purchase history|past orders|previous orders|(?:what(?:\'s| is)|show|tell|find|get|check|look ?up)(?: me)?(?: my)? (?:member(?:ship)?|club) (?:number|no|id|card)|(?:member(?:ship)?|club) (?:number|no|id|card)|(?:forgot|lost|don\'?t know|do not know|dont know|don\'?t have|can\'?t find|cant find|no)(?: my)? (?:order|member|club)(?: ?(?:number|no|id))?|find (?:my )?(?:account|orders?|details)|look ?up (?:my )?(?:account|orders?|customer)|verify (?:my )?(?:email|account|identity)|(?:forgot|lost|don\'?t (?:know|have|remember)|do not (?:know|have|remember)|can\'?t (?:find|remember)|cant (?:find|remember)|no)(?: my)? (?:e-?mail|password|order|member|club))\b/i', $m );
	}

	/* ---------------------------------------------------------------- orders */

	/**
	 * Orders that belong to an identity: its account orders plus guest orders placed with the same email.
	 *
	 * @return WC_Order[]
	 */
	public static function orders( $ident, $limit = 5 ) {
		$all = array();
		$st  = array_keys( wc_get_order_statuses() );
		if ( $ident['uid'] ) {
			foreach ( wc_get_orders( array( 'customer_id' => $ident['uid'], 'limit' => $limit, 'orderby' => 'date', 'order' => 'DESC', 'status' => $st ) ) as $o ) {
				$all[ $o->get_id() ] = $o;
			}
		}
		if ( $ident['email'] ) {
			foreach ( wc_get_orders( array( 'billing_email' => $ident['email'], 'limit' => $limit, 'orderby' => 'date', 'order' => 'DESC', 'status' => $st ) ) as $o ) {
				$all[ $o->get_id() ] = $o;
			}
		}
		usort( $all, static function ( $a, $b ) {
			return $b->get_date_created() <=> $a->get_date_created();
		} );
		return array_slice( array_values( $all ), 0, $limit );
	}

	private static function order_row( $o ) {
		return array(
			'number' => $o->get_order_number(),
			'status' => wc_get_order_status_name( $o->get_status() ),
			'tone'   => function_exists( 'tcc_status_tone' ) ? tcc_status_tone( $o->get_status() ) : 'prog',
			'date'   => $o->get_date_created() ? wp_date( 'j M Y', $o->get_date_created()->getTimestamp() ) : '',
			'total'  => trim( html_entity_decode( wp_strip_all_tags( $o->get_formatted_order_total() ), ENT_QUOTES, 'UTF-8' ) ),
		);
	}

	/**
	 * Club membership details from the theme (member number and join date), if the theme has them.
	 *
	 * @return array|null { no, since }
	 */
	public static function member( $uid ) {
		if ( ! $uid || ! function_exists( 'tcc_member_no' ) ) {
			return null;
		}
		$raw = (string) tcc_member_no( $uid, false );
		if ( '' === $raw ) {
			return null;
		}
		$since = function_exists( 'tcc_member_since' ) ? tcc_member_since( $uid ) : '';
		if ( is_numeric( $since ) && $since > 0 ) {
			$since = wp_date( 'j M Y', (int) $since );
		}
		return array( 'no' => function_exists( 'tcc_member_no_format' ) ? tcc_member_no_format( $raw ) : $raw, 'since' => (string) $since );
	}

	/* --------------------------------------------------------------- handlers */

	/**
	 * Entry point from the conversation engine.
	 *
	 * @return array|null Payload, or null when this is not an account question.
	 */
	public static function handle( $m ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return null;
		}
		$x     = self::extract( $m );
		$any   = '' !== $x['email'] || '' !== $x['member'] || '' !== $x['order'];
		$lone  = $any && str_word_count( preg_replace( '/[^A-Za-z ]+/', ' ', preg_replace( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '', $m ) ) ) <= 2;
		// Guest tracking with order number + billing email already has its own proof; leave it to the order flow.
		if ( '' !== $x['order'] && '' !== $x['email'] && preg_match( '/\b(track\w*|where|status|parcel|shipment|delivery)\b/i', $m ) ) {
			return null;
		}
		if ( self::staff() && ( $any || preg_match( '/^\s*(?:please\s+|can you\s+)?(?:find|look ?up|search(?: for)?|who is|show)\b.+|\b(?:customer|member|club)\b.+/i', $m ) ) && ! preg_match( '/\b(my|me|i)\b/i', $m ) ) {
			return self::staff_lookup( $m, $x );
		}
		// Policy questions about an order ("can I return my order", "how do I pay"), and plain tracking, belong to the FAQ and order flows.
		if ( preg_match( '/\b(return\w*|refund\w*|cancel\w*|exchange|pay|payment|invoice|change|edit|modify|amend|discount|coupon)\b/i', $m ) && ! $any ) {
			return null;
		}
		if ( preg_match( '/\b(track\w*|where|status|arriv\w*|shipped|dispatched|delayed)\b/i', $m ) && ! preg_match( '/\b(history|all|list|forgot|lost|find|look ?up|verify|club|member)\b/i', $m ) && ! $any ) {
			return null;
		}
		$wants = self::intent( $m );
		if ( ! $wants && ! ( $lone && ( '' !== $x['email'] || '' !== $x['member'] ) ) ) {
			return null;
		}
		$ident = self::identity();
		if ( $ident ) {
			// Someone else's email or club number: never show it, even though this visitor is verified as themselves.
			$own = self::member( (int) $ident['uid'] );
			if ( ( '' !== $x['email'] && $x['email'] !== $ident['email'] ) || ( '' !== $x['member'] && ( ! $own || preg_replace( '/\D/', '', $own['no'] ) !== $x['member'] ) ) ) {
				return TCCA_Engine::out( 'account', __( 'I can only show the account you are signed in or verified with. To look up a different email or club number, I would need to verify that one separately, or a pharmacist can help.', 'tcc-assistant' ), array( 'actions' => TCCA_Engine::human_actions() ) );
			}
			return self::summary( $ident );
		}
		if ( $any ) {
			return self::start( $x );
		}
		return TCCA_Engine::out( 'account', __( 'I can find your orders and club membership. To keep your details private, I will email you a 6-digit code first. Enter any one of: your email, your club number, or an order number.', 'tcc-assistant' ), array( 'form' => 'verify' ) );
	}

	/**
	 * What a verified customer may see: first name, club membership, recent orders.
	 */
	public static function summary( $ident ) {
		$user  = $ident['uid'] ? get_userdata( $ident['uid'] ) : ( $ident['email'] ? get_user_by( 'email', $ident['email'] ) : false );
		$name  = $user ? trim( (string) $user->first_name ) : '';
		$uid   = $user ? (int) $user->ID : 0;
		$rows  = array_map( array( __CLASS__, 'order_row' ), self::orders( $ident, 5 ) );
		$card  = array( 'type' => 'account', 'name' => $name, 'member' => self::member( $uid ), 'orders' => $rows, 'via' => $ident['via'] );
		$chips = array();
		foreach ( array_slice( $rows, 0, 3 ) as $r ) {
			$chips[] = array( 'Track #' . $r['number'], 'Track order #' . $r['number'] );
		}
		$actions = array();
		if ( ! $card['member'] && function_exists( 'wc_get_page_permalink' ) ) {
			$actions[] = array( 'type' => 'link', 'label' => __( 'Join the club', 'tcc-assistant' ), 'url' => wc_get_page_permalink( 'myaccount' ) );
		}
		$text = ( $name ? sprintf( __( 'Thanks %s, you are verified. Here is what I found:', 'tcc-assistant' ), $name ) : __( 'You are verified. Here is what I found:', 'tcc-assistant' ) );
		if ( ! $rows ) {
			$text = __( 'You are verified, but I do not see any orders for this email yet.', 'tcc-assistant' );
		}
		return TCCA_Engine::out( 'account', $text, array( 'cards' => array( $card ), 'chips' => $chips, 'actions' => $actions ) );
	}

	/* ---------------------------------------------------------- verification */

	/**
	 * Send a 6-digit code to the email on file for whatever the visitor typed. The reply never reveals whether
	 * anything matched (no account enumeration).
	 */
	public static function start( $x ) {
		$generic = __( 'If that matches a customer, a 6-digit code is on its way to the email on file. It is valid for 10 minutes. Enter it below.', 'tcc-assistant' );
		if ( ! TCCA_Core::rate_ok( 'verify_start', 5, HOUR_IN_SECONDS ) ) {
			return TCCA_Engine::out( 'account', __( 'Too many attempts from this device. Please try again in an hour, or ask a pharmacist.', 'tcc-assistant' ), array( 'actions' => TCCA_Engine::human_actions(), 'answered' => false ) );
		}
		$target = '';
		if ( '' !== $x['email'] ) {
			$exists = get_user_by( 'email', $x['email'] ) || wc_get_orders( array( 'billing_email' => $x['email'], 'limit' => 1, 'return' => 'ids' ) );
			$target = $exists ? $x['email'] : '';
		} elseif ( '' !== $x['member'] && function_exists( 'tcc_member_by_no' ) ) {
			$u      = tcc_member_by_no( $x['member'] );
			$target = $u ? strtolower( $u->user_email ) : '';
		} elseif ( '' !== $x['order'] ) {
			$o      = wc_get_order( absint( $x['order'] ) );
			$target = $o ? strtolower( (string) ( $o->get_billing_email() ?: ( $o->get_user() ? $o->get_user()->user_email : '' ) ) ) : '';
		}
		$ticket = bin2hex( random_bytes( 8 ) );
		if ( $target && is_email( $target ) ) {
			$k = 'tcca_otpn_' . md5( $target );
			$n = (int) get_transient( $k );
			if ( $n < 3 ) {
				set_transient( $k, $n + 1, HOUR_IN_SECONDS );
				$code = (string) random_int( 100000, 999999 );
				set_transient( 'tcca_tk_' . $ticket, array( 'email' => $target, 'hash' => wp_hash( $code . $ticket ), 'tries' => 0 ), 10 * MINUTE_IN_SECONDS );
				TCCA_Mail::send( $target, sprintf( __( 'Your verification code: %s', 'tcc-assistant' ), $code ), array( sprintf( __( 'Your code is %s', 'tcc-assistant' ), $code ), __( 'Enter it in the chat to see your orders and membership. It expires in 10 minutes.', 'tcc-assistant' ) ), null );
			}
		}
		// Same reply whether or not anything matched. (An unmatched ticket simply never verifies.)
		$text = ( '' !== $x['email'] ) ? sprintf( __( 'If %s is a customer email, a 6-digit code is on its way. It is valid for 10 minutes. Enter it below.', 'tcc-assistant' ), TCCA_Mail::mask( $x['email'] ) ) : $generic;
		return TCCA_Engine::out( 'account', $text, array( 'form' => 'code', 'ticket' => $ticket ) );
	}

	/**
	 * Check the code. On success: a verification token (kept in the visitor's session only) and their summary.
	 */
	public static function check( $ticket, $code ) {
		$ticket = preg_replace( '/[^a-f0-9]/', '', (string) $ticket );
		$code   = preg_replace( '/\D/', '', (string) $code );
		$fail   = TCCA_Engine::out( 'account', __( 'That code did not work. Please check it, or ask for a new one.', 'tcc-assistant' ), array( 'form' => 'code', 'ticket' => $ticket, 'answered' => false ) );
		if ( ! TCCA_Core::rate_ok( 'verify_check', 20, HOUR_IN_SECONDS ) ) {
			return TCCA_Engine::out( 'account', __( 'Too many attempts. Please try again later.', 'tcc-assistant' ), array( 'answered' => false ) );
		}
		$t = get_transient( 'tcca_tk_' . $ticket );
		if ( ! is_array( $t ) || 6 !== strlen( $code ) ) {
			return $fail;
		}
		if ( (int) $t['tries'] >= 5 ) {
			delete_transient( 'tcca_tk_' . $ticket );
			return TCCA_Engine::out( 'account', __( 'Too many wrong codes. Please start again.', 'tcc-assistant' ), array( 'form' => 'verify', 'answered' => false ) );
		}
		if ( ! hash_equals( (string) $t['hash'], wp_hash( $code . $ticket ) ) ) {
			$t['tries']++;
			set_transient( 'tcca_tk_' . $ticket, $t, 10 * MINUTE_IN_SECONDS );
			return $fail;
		}
		delete_transient( 'tcca_tk_' . $ticket );
		$u     = get_user_by( 'email', $t['email'] );
		$vt    = bin2hex( random_bytes( 16 ) );
		set_transient( 'tcca_v_' . md5( $vt ), array( 'email' => $t['email'], 'uid' => $u ? (int) $u->ID : 0 ), 2 * HOUR_IN_SECONDS );
		$r          = self::summary( array( 'uid' => $u ? (int) $u->ID : 0, 'email' => $t['email'], 'via' => 'code' ) );
		$r['vtoken'] = $vt;
		return $r;
	}

	/* ------------------------------------------------------------------ staff */

	/**
	 * Staff-only lookup of a customer or order by email, member number, order number or name.
	 */
	private static function staff_lookup( $m, $x ) {
		$user  = null;
		$order = null;
		if ( '' !== $x['order'] ) {
			$order = wc_get_order( absint( $x['order'] ) );
			if ( $order && $order->get_user() ) {
				$user = $order->get_user();
			}
		} elseif ( '' !== $x['member'] && function_exists( 'tcc_member_by_no' ) ) {
			$user = tcc_member_by_no( $x['member'] );
		} elseif ( '' !== $x['email'] ) {
			$user = get_user_by( 'email', $x['email'] );
		} else {
			$name = trim( preg_replace( '/\b(find|look ?up|search|for|customer|who is|the|a|please|member)\b/i', ' ', $m ) );
			if ( strlen( $name ) >= 3 ) {
				$found = get_users( array( 'search' => '*' . esc_attr( $name ) . '*', 'search_columns' => array( 'user_login', 'user_email', 'display_name', 'user_nicename' ), 'number' => 5 ) );
				if ( count( $found ) > 1 ) {
					$chips = array();
					foreach ( $found as $f ) {
						$chips[] = array( $f->display_name . ' · ' . $f->user_email, 'find ' . $f->user_email );
					}
					return TCCA_Engine::out( 'staff', __( 'Staff view: several customers match. Pick one:', 'tcc-assistant' ), array( 'chips' => $chips ) );
				}
				$user = $found ? $found[0] : null;
			}
		}
		$orders = array();
		if ( $user ) {
			$orders = self::orders( array( 'uid' => (int) $user->ID, 'email' => strtolower( $user->user_email ), 'via' => 'staff' ), 8 );
		} elseif ( '' !== $x['email'] ) {
			$orders = self::orders( array( 'uid' => 0, 'email' => $x['email'], 'via' => 'staff' ), 8 );
		}
		if ( $order && ! $user ) {
			$orders = array( $order );
		}
		if ( ! $user && ! $orders ) {
			return TCCA_Engine::out( 'staff', __( 'Staff view: no customer or order matches that.', 'tcc-assistant' ), array( 'answered' => false ) );
		}
		$spend = 0.0;
		foreach ( $orders as $o ) {
			if ( in_array( $o->get_status(), array( 'completed', 'shipped', 'out-delivery', 'processing', 'verified', 'packed' ), true ) ) {
				$spend += (float) $o->get_total();
			}
		}
		$rows = array();
		foreach ( $orders as $o ) {
			$r = self::order_row( $o );
			if ( function_exists( 'mm_track_model' ) ) {
				$t = mm_track_model( $o );
				if ( ! empty( $t['tracking'] ) ) {
					$r['tracking'] = $t['tracking'];
				}
			}
			$r['edit'] = $o->get_edit_order_url();
			$rows[]    = $r;
		}
		$card = array(
			'type'   => 'customer',
			'name'   => $user ? trim( $user->display_name ) : ( $orders ? trim( $orders[0]->get_billing_first_name() . ' ' . $orders[0]->get_billing_last_name() ) : '' ),
			'email'  => $user ? $user->user_email : ( $orders ? $orders[0]->get_billing_email() : '' ),
			'phone'  => $orders ? $orders[0]->get_billing_phone() : '',
			'member' => $user ? self::member( (int) $user->ID ) : null,
			'orders' => $rows,
			'spend'  => TCCA_Core::money( $spend ),
			'edit'   => $user ? get_edit_user_link( $user->ID ) : '',
		);
		return TCCA_Engine::out( 'staff', __( 'Staff view (only you can see this):', 'tcc-assistant' ), array( 'cards' => array( $card ) ) );
	}
}
