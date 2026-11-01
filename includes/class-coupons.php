<?php
/**
 * Email helper and one-time coupon generator.
 *
 * Coupons are created only inside limits the store owner sets (off by default): a fixed % (hard-capped),
 * single use, locked to the customer's email, expiring, one per email per 30 days, with an IP limit and
 * a daily cap for the whole shop.
 *
 * @package TCCAssistant
 */

defined( 'ABSPATH' ) || exit;

class TCCA_Mail {

	public static function mask( $email ) {
		$p = explode( '@', (string) $email );
		return count( $p ) === 2 ? substr( $p[0], 0, 1 ) . '***@' . $p[1] : '***';
	}

	/**
	 * Abuse brake: per IP per hour and per recipient per day.
	 */
	public static function allowed( $to ) {
		if ( ! TCCA_Core::rate_ok( 'mail_ip', 6, HOUR_IN_SECONDS ) ) {
			return false;
		}
		$k = 'tcca_mail_' . md5( strtolower( $to ) );
		$n = (int) get_transient( $k );
		if ( $n >= 3 ) {
			return false;
		}
		set_transient( $k, $n + 1, DAY_IN_SECONDS );
		return true;
	}

	/**
	 * Simple branded HTML email. Lines starting "- " become list items; $cta is [ label, url ].
	 */
	public static function send( $to, $subject, array $lines, $cta = null ) {
		$site = TCCA_Core::site();
		$html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;color:#222;line-height:1.5">';
		$html .= '<h2 style="color:#4f2d90;margin:0 0 12px">' . esc_html( $site['company'] ) . '</h2>';
		$list = false;
		foreach ( $lines as $l ) {
			$is = 0 === strpos( $l, '- ' );
			if ( $is && ! $list ) {
				$html .= '<ul style="padding-left:20px">';
			} elseif ( ! $is && $list ) {
				$html .= '</ul>';
			}
			$list  = $is;
			$html .= $is ? '<li>' . esc_html( substr( $l, 2 ) ) . '</li>' : '<p style="margin:0 0 10px">' . esc_html( $l ) . '</p>';
		}
		if ( $list ) {
			$html .= '</ul>';
		}
		if ( $cta && ! empty( $cta[1] ) ) {
			$html .= '<p><a href="' . esc_url( $cta[1] ) . '" style="display:inline-block;background:#4f2d90;color:#fff;text-decoration:none;padding:10px 18px;border-radius:99px;font-weight:bold">' . esc_html( $cta[0] ) . '</a></p>';
		}
		$html .= '<p style="color:#777;font-size:12px;margin-top:18px">' . esc_html__( 'You received this because someone asked for it in the chat on our website. If that was not you, you can ignore this email.', 'tcc-assistant' ) . '</p></div>';
		return (bool) wp_mail( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/**
	 * Email the visitor's cart (read from their own session).
	 *
	 * @return array{ok:bool,message:string}
	 */
	public static function send_cart( $to ) {
		if ( ! function_exists( 'WC' ) ) {
			return array( 'ok' => false, 'message' => '' );
		}
		if ( ! WC()->cart && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		$cart = WC()->cart;
		if ( ! $cart || $cart->is_empty() ) {
			return array( 'ok' => false, 'message' => __( 'Your cart is empty, so there is nothing to send.', 'tcc-assistant' ) );
		}
		if ( ! self::allowed( $to ) ) {
			return array( 'ok' => false, 'message' => __( 'Too many emails requested. Please try again later.', 'tcc-assistant' ) );
		}
		$lines = array( __( 'Here is the cart you were looking at:', 'tcc-assistant' ) );
		foreach ( $cart->get_cart() as $item ) {
			$lines[] = '- ' . wp_strip_all_tags( $item['data']->get_name() ) . ' × ' . (int) $item['quantity'] . ' (' . TCCA_Core::money( (float) $item['line_total'] ) . ')';
		}
		$lines[] = sprintf( __( 'Total: %s (shipping is calculated at checkout).', 'tcc-assistant' ), trim( html_entity_decode( wp_strip_all_tags( $cart->get_cart_total() ), ENT_QUOTES, 'UTF-8' ) ) );
		$ok = self::send( $to, sprintf( __( 'Your cart at %s', 'tcc-assistant' ), TCCA_Core::site()['company'] ), $lines, array( __( 'Go to my cart', 'tcc-assistant' ), wc_get_cart_url() ) );
		return array( 'ok' => $ok, 'message' => $ok ? sprintf( __( 'Sent to %s.', 'tcc-assistant' ), self::mask( $to ) ) : __( 'Could not send the email right now.', 'tcc-assistant' ) );
	}

	/**
	 * Email an order's status and tracking. Only ever to an address already proven to own the order.
	 */
	public static function send_order( $to, $order ) {
		if ( ! self::allowed( $to ) ) {
			return array( 'ok' => false, 'message' => __( 'Too many emails requested. Please try again later.', 'tcc-assistant' ) );
		}
		$lines = array( sprintf( __( 'Order #%1$s is: %2$s.', 'tcc-assistant' ), $order->get_order_number(), wc_get_order_status_name( $order->get_status() ) ) );
		if ( function_exists( 'mm_track_model' ) ) {
			$t = mm_track_model( $order );
			if ( ! empty( $t['heading'] ) ) {
				$lines[] = $t['heading'];
			}
			if ( ! empty( $t['tracking'] ) ) {
				$lines[] = sprintf( __( 'Tracking number: %1$s%2$s', 'tcc-assistant' ), $t['tracking'], ! empty( $t['carrier'] ) ? ' (' . $t['carrier'] . ')' : '' );
			}
			if ( ! $t['delivered'] && ! empty( $t['eta_ts'] ) ) {
				$lines[] = sprintf( __( 'Estimated arrival: %s', 'tcc-assistant' ), wp_date( 'j M Y', (int) $t['eta_ts'] ) );
			}
		}
		foreach ( $order->get_items() as $it ) {
			$lines[] = '- ' . wp_strip_all_tags( $it->get_name() ) . ' × ' . (int) $it->get_quantity();
		}
		$ok = self::send( $to, sprintf( __( 'Your order #%s update', 'tcc-assistant' ), $order->get_order_number() ), $lines, ! empty( $t['track_url'] ) && ! empty( $t['tracking'] ) ? array( __( 'Track parcel', 'tcc-assistant' ), str_replace( '{n}', rawurlencode( $t['tracking'] ), $t['track_url'] ) ) : null );
		return array( 'ok' => $ok, 'message' => $ok ? sprintf( __( 'Sent to %s.', 'tcc-assistant' ), self::mask( $to ) ) : __( 'Could not send the email right now.', 'tcc-assistant' ) );
	}
}

class TCCA_Coupons {

	public static function enabled() {
		return '1' === TCCA_Core::get( 'coupon_on' ) && class_exists( 'WC_Coupon' );
	}

	private static function pct() {
		return max( 1, min( 50, (int) TCCA_Core::get( 'coupon_pct' ) ) );
	}

	/**
	 * Does the visitor want a discount code (not "how do I apply a code")?
	 */
	public static function offer_intent( $m ) {
		if ( preg_match( '/\b(how (?:do|to|can) (?:i )?(?:use|apply|enter|redeem)|where (?:do|to) (?:i )?(?:enter|put|apply)|not working|invalid|expired)\b/i', $m ) ) {
			return false;
		}
		return (bool) preg_match( '/\b(any|get|give|got|have|want|need|send|generate|create|is there|do you have|can i (?:get|have))\b[^?]*\b(coupons?|promo(?: code)?s?|discounts?|vouchers?|offers?|deals?)\b|\b(coupon|discount|promo) (?:code )?(?:for me|please|pls)\b|\bcheaper\b.*\bany\b/i', $m );
	}

	public static function terms( $pct = 0, $min = null ) {
		$pct = $pct ? (int) $pct : self::pct();
		$t   = array( sprintf( __( '%d%% off', 'tcc-assistant' ), $pct ), sprintf( __( 'valid for %d days', 'tcc-assistant' ), max( 1, (int) TCCA_Core::get( 'coupon_days' ) ) ), __( 'one use', 'tcc-assistant' ) );
		$min = null === $min ? (float) TCCA_Core::get( 'coupon_min' ) : (float) $min;
		if ( $min > 0 ) {
			$t[] = sprintf( __( 'minimum spend %s', 'tcc-assistant' ), TCCA_Core::money( $min ) );
		}
		return $t;
	}

	public static function pitch() {
		return sprintf( __( 'I can create a one-time code for you: %s.', 'tcc-assistant' ), implode( ', ', self::terms() ) );
	}

	/* ------------------------------------------------------------------ identity */

	/**
	 * One key per real mailbox: "J.Doe+shop@gmail.com" and "jdoe@googlemail.com" are the same person.
	 */
	public static function ekey( $email ) {
		$email = strtolower( trim( (string) $email ) );
		$p     = explode( '@', $email, 2 );
		$u     = $p[0];
		$d     = isset( $p[1] ) ? $p[1] : '';
		$u     = preg_replace( '/\+.*$/', '', $u );
		if ( in_array( $d, array( 'gmail.com', 'googlemail.com' ), true ) ) {
			$u = str_replace( '.', '', $u );
			$d = 'gmail.com';
		}
		return md5( $u . '@' . $d );
	}

	private static function ip_hash() {
		return md5( TCCA_Core::ip() . '|' . wp_salt( 'auth' ) );
	}

	public static function lock_days() {
		return max( 1, (int) TCCA_Core::get( 'coupon_lock_days' ) );
	}

	/**
	 * Has this customer already had a chat code inside the lock window? Matches the mailbox (ignoring + and dot
	 * tricks), the signed-in account, and exact email. Returns the newest such coupon, or null.
	 *
	 * @return array|null { id, code, issued (ts), unused (bool), next (ts when they may ask again) }
	 */
	public static function lock_info( $email ) {
		$or = array( 'relation' => 'OR', array( 'key' => '_tcca_ekey', 'value' => self::ekey( $email ) ), array( 'key' => '_tcca_email', 'value' => strtolower( $email ) ) );
		if ( is_user_logged_in() ) {
			$or[] = array( 'key' => '_tcca_uid', 'value' => (string) get_current_user_id() );
		}
		$days = self::lock_days();
		$ids  = get_posts( array( 'post_type' => 'shop_coupon', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true, 'orderby' => 'date', 'order' => 'DESC', 'meta_query' => $or, 'date_query' => array( array( 'after' => $days . ' days ago' ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( ! $ids ) {
			return null;
		}
		$c      = new WC_Coupon( (int) $ids[0] );
		$issued = (int) get_post_time( 'U', true, (int) $ids[0] );
		$live   = 0 === (int) $c->get_usage_count() && ( ! $c->get_date_expires() || $c->get_date_expires()->getTimestamp() > time() );
		return array( 'id' => (int) $ids[0], 'code' => $c->get_code(), 'issued' => $issued, 'unused' => $live, 'next' => $issued + $days * DAY_IN_SECONDS );
	}

	public static function is_returning( $email ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return false;
		}
		return (bool) wc_get_orders( array( 'billing_email' => $email, 'limit' => 1, 'return' => 'ids', 'status' => array( 'wc-processing', 'wc-completed', 'wc-on-hold' ) ) );
	}

	/**
	 * Is a product in one of the categories the owner excluded from chat codes?
	 */
	public static function excluded( $p ) {
		if ( ! $p ) {
			return false;
		}
		$ids = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', (string) TCCA_Core::get( 'coupon_excl' ) ) ) ) as $x ) {
			if ( ctype_digit( $x ) ) {
				$ids[] = (int) $x;
			} elseif ( $term = get_term_by( 'slug', sanitize_title( $x ), 'product_cat' ) ) {
				$ids[] = (int) $term->term_id;
			}
		}
		return $ids && array_intersect( $ids, array_map( 'intval', (array) $p->get_category_ids() ) );
	}

	/**
	 * Create (or re-send) a one-time coupon for an email address.
	 *
	 * @param string $email Customer email.
	 * @param array  $deal  From the sales logic: pct, min, reason, pid. Without it the base settings apply.
	 * @return array|WP_Error { code, existing, pct }
	 */
	public static function issue( $email, $deal = array() ) {
		$email = strtolower( sanitize_email( $email ) );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'email', __( 'Please enter a valid email address.', 'tcc-assistant' ) );
		}
		if ( ! self::enabled() ) {
			return new WP_Error( 'off', __( 'Discount codes are not available right now.', 'tcc-assistant' ) );
		}
		// Shop staff testing the chat are exempt from the monthly and per-connection limits (customers never are).
		$staff = is_user_logged_in() && current_user_can( 'manage_woocommerce' );
		// One chat code per customer per month (default 30 days), whatever the email spelling.
		$lock = $staff ? null : self::lock_info( $email );
		if ( $lock ) {
			if ( $lock['unused'] ) {
				return array( 'code' => $lock['code'], 'existing' => true, 'pct' => (int) ( new WC_Coupon( $lock['id'] ) )->get_amount() );
			}
			return new WP_Error( 'used', sprintf( /* translators: %s: date */ __( 'Your chat discount for this month has already been used or has expired. You can have another from %s.', 'tcc-assistant' ), wp_date( 'j M Y', $lock['next'] ) ), array( 'next' => $lock['next'] ) );
		}
		// A household or office sharing one connection may take two; more than that is somebody collecting codes.
		$ih   = self::ip_hash();
		$same = get_posts( array( 'post_type' => 'shop_coupon', 'post_status' => 'publish', 'posts_per_page' => 3, 'fields' => 'ids', 'no_found_rows' => true, 'meta_key' => '_tcca_iph', 'meta_value' => $ih, 'date_query' => array( array( 'after' => self::lock_days() . ' days ago' ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( ! $staff && count( $same ) >= 2 ) {
			return new WP_Error( 'rate', __( 'Chat codes have already been given out from this connection this month.', 'tcc-assistant' ) );
		}
		if ( ! $staff && ! TCCA_Core::rate_ok( 'coupon_ip', 3, DAY_IN_SECONDS ) ) {
			return new WP_Error( 'rate', __( 'Too many requests from this device today. Please try again tomorrow.', 'tcc-assistant' ) );
		}
		$returning = self::is_returning( $email );
		if ( '1' === TCCA_Core::get( 'coupon_new_only' ) && $returning ) {
			return new WP_Error( 'new_only', __( 'This code is for first orders. Check each product for its bulk savings instead.', 'tcc-assistant' ) );
		}
		$pct = isset( $deal['pct'] ) ? (int) $deal['pct'] : self::pct();
		$min = isset( $deal['min'] ) ? (float) $deal['min'] : (float) TCCA_Core::get( 'coupon_min' );
		if ( $returning ) {
			$ret = trim( (string) TCCA_Core::get( 'coupon_pct_ret' ) );
			if ( '' !== $ret ) {
				if ( (int) $ret <= 0 ) {
					return new WP_Error( 'returning', __( 'The chat code is a welcome offer for new customers. As a returning customer, your best savings are the bulk prices on each product.', 'tcc-assistant' ) );
				}
				$pct = min( $pct, (int) $ret );
			}
		}
		$pct = max( 1, min( 50, $pct ) );
		$day = 'tcca_cp_' . gmdate( 'Ymd' );
		$cap = max( 1, (int) TCCA_Core::get( 'coupon_cap_day' ) );
		if ( (int) get_transient( $day ) >= $cap ) {
			return new WP_Error( 'cap', __( 'Today\'s codes have all been given out. Please try again tomorrow.', 'tcc-assistant' ) );
		}

		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		do {
			$code = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', TCCA_Core::get( 'coupon_prefix' ) ?: 'CHAT' ) ) . '-';
			for ( $i = 0; $i < 6; $i++ ) {
				$code .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
			}
		} while ( function_exists( 'wc_get_coupon_id_by_code' ) && wc_get_coupon_id_by_code( $code ) );

		$c = new WC_Coupon();
		$c->set_code( $code );
		$c->set_discount_type( 'percent' );
		$c->set_amount( $pct );
		$c->set_individual_use( true );
		$c->set_usage_limit( 1 );
		$c->set_usage_limit_per_user( 1 );
		$c->set_email_restrictions( array( $email ) );
		$c->set_date_expires( time() + max( 1, (int) TCCA_Core::get( 'coupon_days' ) ) * DAY_IN_SECONDS );
		if ( $min > 0 ) {
			$c->set_minimum_amount( (string) $min );
		}
		$excl = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', TCCA_Core::get( 'coupon_excl' ) ) ) ) as $x ) {
			if ( ctype_digit( $x ) ) {
				$excl[] = (int) $x;
			} elseif ( $term = get_term_by( 'slug', sanitize_title( $x ), 'product_cat' ) ) {
				$excl[] = (int) $term->term_id;
			}
		}
		if ( $excl ) {
			$c->set_excluded_product_categories( $excl );
		}
		$c->set_description( 'Created by the shop assistant chat' . ( ! empty( $deal['reason'] ) ? ' (reason: ' . sanitize_key( $deal['reason'] ) . ')' : '' ) );
		$c->update_meta_data( '_tcca_email', $email );
		$c->update_meta_data( '_tcca_ekey', self::ekey( $email ) );
		$c->update_meta_data( '_tcca_iph', $ih );
		$c->update_meta_data( '_tcca_reason', ! empty( $deal['reason'] ) ? sanitize_key( $deal['reason'] ) : '' );
		$c->update_meta_data( '_tcca_pid', ! empty( $deal['pid'] ) ? (int) $deal['pid'] : 0 );
		if ( is_user_logged_in() ) {
			$c->update_meta_data( '_tcca_uid', (string) get_current_user_id() );
		}
		$c->save();
		set_transient( $day, (int) get_transient( $day ) + 1, 2 * DAY_IN_SECONDS );
		return array( 'code' => $code, 'existing' => false, 'pct' => $pct );
	}

	/**
	 * Tell WooCommerce which email this visitor gave, so an email-locked code can be applied in the cart straight away
	 * (otherwise WooCommerce asks for the email at checkout first).
	 */
	private static function remember_email( $email ) {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}
		if ( ( ! WC()->session || ! WC()->customer ) && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		if ( WC()->customer && WC()->session ) {
			WC()->customer->set_billing_email( $email );
			WC()->customer->save();
			if ( ! WC()->session->has_session() ) {
				WC()->session->set_customer_session_cookie( true );
			}
		}
	}

	/**
	 * Issue, email, and build the chat reply.
	 *
	 * @param string $email Recipient.
	 * @param array  $deal  Offer decided by the sales logic (pct, min, reason, pid).
	 * @return array Engine payload.
	 */
	public static function reply( $email, $deal = array() ) {
		$p = ! empty( $deal['pid'] ) && function_exists( 'wc_get_product' ) ? wc_get_product( (int) $deal['pid'] ) : null;
		if ( $p && self::excluded( $p ) ) {
			return TCCA_Engine::out( 'coupon', __( 'That item is already at our best price, so a code cannot be applied to it. The bulk prices on its page are the best way to save on it.', 'tcc-assistant' ), array( 'chips' => TCCA_Deal::save_chips() ) );
		}
		$r = self::issue( $email, $deal );
		if ( is_wp_error( $r ) ) {
			$code  = $r->get_error_code();
			$extra = array( 'answered' => true, 'form' => 'email' === $code ? 'coupon' : '' );
			$text  = $r->get_error_message();
			if ( in_array( $code, array( 'used', 'returning', 'new_only', 'rate', 'cap' ), true ) ) {
				// Not a dead end: a good salesperson still helps the customer spend less.
				$up = TCCA_Deal::upsell( $p );
				if ( $up['lines'] ) {
					$text .= "\n" . __( 'Meanwhile, here is how to get more for your money:', 'tcc-assistant' ) . "\n" . implode( "\n", $up['lines'] );
				}
				$extra['cards'] = $up['cards'];
				$extra['chips'] = TCCA_Deal::save_chips();
			}
			return TCCA_Engine::out( 'coupon', $text, $extra );
		}
		self::remember_email( $email );
		$terms = self::terms( $r['pct'], isset( $deal['min'] ) ? $deal['min'] : null );
		$sent  = TCCA_Mail::send(
			$email,
			sprintf( __( 'Your %1$d%% code for %2$s', 'tcc-assistant' ), $r['pct'], TCCA_Core::site()['company'] ),
			array( sprintf( __( 'Your one-time code: %s', 'tcc-assistant' ), $r['code'] ), implode( ' · ', $terms ), __( 'Enter it in your cart before checkout. It only works with this email address.', 'tcc-assistant' ) ),
			array( __( 'Go to my cart', 'tcc-assistant' ), function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' ) )
		);
		$text  = $r['existing'] ? __( 'You already have a code, so here it is again.', 'tcc-assistant' ) : __( 'Here you go, your code is ready.', 'tcc-assistant' );
		$text .= $sent ? ' ' . sprintf( __( 'I also emailed it to %s.', 'tcc-assistant' ), TCCA_Mail::mask( $email ) ) : '';
		$cards = array();
		if ( '1' === TCCA_Core::get( 'coupon_show' ) || ! $sent ) {
			$cards[] = array( 'type' => 'coupon', 'code' => $r['code'], 'terms' => $terms, 'note' => __( 'It only works with the email address you gave.', 'tcc-assistant' ) );
		} else {
			$text .= ' ' . __( 'Check your inbox (and spam folder).', 'tcc-assistant' );
		}
		// Make the code count: show what else would raise the saving on this order.
		$up = TCCA_Deal::upsell( $p );
		if ( $up['lines'] ) {
			$text .= "\n" . __( 'To get the most from it:', 'tcc-assistant' ) . "\n" . implode( "\n", $up['lines'] );
		}
		$chips = array();
		if ( $p && $p->is_purchasable() && $p->is_in_stock() ) {
			$chips[] = array( sprintf( __( 'Add %s to my cart', 'tcc-assistant' ), wp_strip_all_tags( $p->get_name() ) ), 'Add ' . wp_strip_all_tags( $p->get_name() ) . ' to my cart' );
		}
		$chips[] = array( __( 'Show my cart', 'tcc-assistant' ), 'Show my cart' );
		return TCCA_Engine::out( 'coupon', $text, array( 'cards' => array_merge( $cards, $up['cards'] ), 'chips' => $chips ) );
	}
}
