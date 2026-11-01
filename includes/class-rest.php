<?php
/**
 * REST API: /wp-json/tcca/v1/{chat,order,handoff}
 *
 * @package TCCAssistant
 */

defined( 'ABSPATH' ) || exit;

class TCCA_REST {

	public static function boot() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		$open = array( 'permission_callback' => '__return_true', 'methods' => 'POST' );
		register_rest_route( 'tcca/v1', '/chat', $open + array( 'callback' => array( __CLASS__, 'chat' ) ) );
		register_rest_route( 'tcca/v1', '/verify/start', $open + array( 'callback' => array( __CLASS__, 'verify_start' ) ) );
		register_rest_route( 'tcca/v1', '/verify/check', $open + array( 'callback' => array( __CLASS__, 'verify_check' ) ) );
		register_rest_route( 'tcca/v1', '/coupon', $open + array( 'callback' => array( __CLASS__, 'coupon' ) ) );
		register_rest_route( 'tcca/v1', '/email', $open + array( 'callback' => array( __CLASS__, 'email' ) ) );
		register_rest_route( 'tcca/v1', '/feedback', $open + array( 'callback' => array( __CLASS__, 'feedback' ) ) );
		register_rest_route( 'tcca/v1', '/cart', $open + array( 'callback' => array( __CLASS__, 'cart' ) ) );
		register_rest_route( 'tcca/v1', '/order', $open + array( 'callback' => array( __CLASS__, 'order' ) ) );
		register_rest_route( 'tcca/v1', '/handoff', $open + array( 'callback' => array( __CLASS__, 'handoff' ) ) );
		register_rest_route( 'tcca/v1', '/track', $open + array( 'callback' => array( __CLASS__, 'track' ) ) );
		register_rest_route( 'tcca/v1', '/lead', $open + array( 'callback' => array( __CLASS__, 'lead' ) ) );
	}

	/**
	 * Never let a proxy or page cache keep an assistant answer.
	 *
	 * @param array $data Payload.
	 * @return WP_REST_Response
	 */
	private static function respond( $data, $status = 200 ) {
		$res = new WP_REST_Response( $data, $status );
		$res->header( 'Cache-Control', 'no-store, private' );
		return $res;
	}

	/**
	 * Strip the internal fields before sending to the browser.
	 *
	 * @param array $r Engine result.
	 * @return array
	 */
	private static function shape( $r ) {
		return array(
			'reply'   => $r['reply'],
			'cards'   => $r['cards'],
			'actions' => $r['actions'],
			'chips'   => $r['chips'],
			'form'    => $r['form'],
			'prefill' => isset( $r['prefill'] ) ? $r['prefill'] : '',
			'ai'      => $r['ai'],
			'fb'      => ! empty( $r['fb'] ),
			'autoadd' => $r['autoadd'],
			'trace'   => $r['trace'],
			'ticket'  => isset( $r['ticket'] ) ? $r['ticket'] : '',
			'vtoken'  => isset( $r['vtoken'] ) ? $r['vtoken'] : '',
			'dtoken'  => isset( $r['dtoken'] ) ? $r['dtoken'] : '',
			'fpid'    => isset( $r['fpid'] ) ? (int) $r['fpid'] : 0,
			'fname'   => isset( $r['fname'] ) ? $r['fname'] : '',
			'fterm'   => isset( $r['fterm'] ) ? $r['fterm'] : '',
			'known'   => ( ! empty( $r['form'] ) && in_array( $r['form'], array( 'restock', 'request' ), true ) && is_user_logged_in() ) ? TCCA_Mail::mask( wp_get_current_user()->user_email ) : '',
		);
	}

	public static function chat( WP_REST_Request $req ) {
		if ( ! TCCA_Core::rate_ok( 'chat_min', 25, MINUTE_IN_SECONDS ) || ! TCCA_Core::rate_ok( 'chat_day', 400, DAY_IN_SECONDS ) ) {
			return self::respond( array( 'reply' => __( 'You are sending messages very quickly. Please wait a moment and try again.', 'tcc-assistant' ), 'cards' => array(), 'actions' => array(), 'chips' => array(), 'form' => '', 'ai' => false ), 429 );
		}
		$msg = mb_substr( sanitize_textarea_field( (string) $req->get_param( 'message' ) ), 0, 500 );
		if ( '' === trim( $msg ) ) {
			return self::respond( array( 'reply' => '' ), 400 );
		}
		$hist = array();
		foreach ( (array) $req->get_param( 'history' ) as $h ) {
			if ( is_array( $h ) && isset( $h['text'] ) ) {
				$hist[] = array( 'role' => ( isset( $h['role'] ) && 'assistant' === $h['role'] ) ? 'assistant' : 'user', 'text' => (string) $h['text'] );
			}
		}
		$ctx = array( 'pid' => 0, 'pids' => array(), 'vtoken' => '', 'session' => preg_replace( '/[^a-zA-Z0-9]/', '', (string) $req->get_param( 'session' ) ) );
		$in  = (array) $req->get_param( 'ctx' );
		$ctx['vtoken'] = preg_replace( '/[^a-f0-9]/', '', (string) ( $in['vtoken'] ?? '' ) );
		if ( ! empty( $in['pid'] ) ) {
			$ctx['pid']  = absint( $in['pid'] );
			$ctx['pids'] = array_slice( array_map( 'absint', (array) ( $in['pids'] ?? array() ) ), 0, 6 );
		}
		// The page the visitor is on (checked on the server). With nothing shown yet, "it" and "this" mean the page's product.
		$page = TCCA_Concierge::validate_page( $in['page'] ?? array() );
		if ( $page ) {
			$ctx['page'] = $page;
			if ( 'product' === $page['type'] && empty( $ctx['pid'] ) ) {
				$ctx['pid']     = $page['id'];
				$ctx['pid_src'] = 'page';
			}
		}
		$r = TCCA_Engine::reply( $msg, array_slice( $hist, -6 ), $ctx );
		TCCA_Core::log( (string) $req->get_param( 'session' ), $r['intent'], $msg, $r['answered'] );
		foreach ( array_slice( (array) $r['cards'], 0, 4 ) as $c ) {
			if ( isset( $c['type'], $c['id'] ) && 'product' === $c['type'] ) {
				TCCA_Events::log( 'show', (string) $req->get_param( 'session' ), array( 'pid' => (int) $c['id'] ) );
			}
		}
		// A suggestion the visitor tapped after a miss teaches the assistant: their original wording means this answer.
		$alias = mb_substr( sanitize_text_field( (string) $req->get_param( 'alias_of' ) ), 0, 300 );
		if ( '' !== $alias && ! empty( $r['trace'] ) ) {
			TCCA_Index::learn( $alias, $r['trace'], 1 );
		}
		return self::respond( self::shape( $r ) );
	}

	/**
	 * Read-only view of the visitor's cart (changes go through the theme's own nonce-protected cart AJAX).
	 */
	public static function cart( WP_REST_Request $req ) {
		if ( ! class_exists( 'WooCommerce' ) || ! TCCA_Core::rate_ok( 'cart_view', 60, MINUTE_IN_SECONDS ) ) {
			return self::respond( array( 'reply' => '' ), 429 );
		}
		$lead = (string) $req->get_param( 'lead' );
		$lead = in_array( $lead, array( 'added' ), true ) ? __( 'Added to your cart.', 'tcc-assistant' ) : '';
		return self::respond( self::shape( TCCA_Engine::cart_reply( $lead ) ) );
	}

	/**
	 * Send a verification code for an email, club number or order number.
	 */
	public static function verify_start( WP_REST_Request $req ) {
		if ( '' !== (string) $req->get_param( 'website' ) ) {
			return self::respond( array( 'reply' => '', 'cards' => array(), 'actions' => array(), 'chips' => array(), 'form' => '', 'ai' => false ) );
		}
		$r = TCCA_Account::start( TCCA_Account::extract_identifier( (string) $req->get_param( 'identifier' ) ) );
		return self::respond( self::shape( $r ) );
	}

	/**
	 * Check the emailed code. Returns the session token and the customer's summary.
	 */
	public static function verify_check( WP_REST_Request $req ) {
		$r = TCCA_Account::check( (string) $req->get_param( 'ticket' ), (string) $req->get_param( 'code' ) );
		TCCA_Core::log( (string) $req->get_param( 'session' ), 'verify', 'verification', $r['answered'] );
		return self::respond( self::shape( $r ) );
	}

	/**
	 * A one-time coupon for an email address (guests); the code is also emailed there.
	 */
	public static function coupon( WP_REST_Request $req ) {
		if ( '' !== (string) $req->get_param( 'website' ) ) {
			return self::respond( array( 'reply' => '', 'cards' => array(), 'actions' => array(), 'chips' => array(), 'form' => '', 'ai' => false ) );
		}
		// A code is only ever created against a signed offer made by the sales logic after a real reason and product.
		$deal = TCCA_Deal::verify( preg_replace( '/[^A-Za-z0-9._\-]/', '', (string) $req->get_param( 'dtoken' ) ) );
		$r    = $deal ? TCCA_Coupons::reply( (string) $req->get_param( 'email' ), $deal ) : TCCA_Deal::ask_payload();
		TCCA_Core::log( (string) $req->get_param( 'session' ), $deal ? 'coupon' : 'coupon_refused', 'coupon request', $r['answered'] );
		return self::respond( self::shape( $r ) );
	}

	/**
	 * Email the visitor their cart or an order update. Order emails go only to an address proven to own the order.
	 */
	public static function email( WP_REST_Request $req ) {
		if ( '' !== (string) $req->get_param( 'website' ) || ! class_exists( 'WooCommerce' ) ) {
			return self::respond( array( 'ok' => false, 'message' => '' ) );
		}
		TCCA_Account::set_context( array( 'vtoken' => (string) $req->get_param( 'vtoken' ) ) );
		$ident = TCCA_Account::identity();
		$kind  = sanitize_key( (string) $req->get_param( 'kind' ) );
		$user  = wp_get_current_user();
		$email = $ident ? $ident['email'] : sanitize_email( (string) $req->get_param( 'email' ) );
		if ( ! is_email( $email ) ) {
			return self::respond( array( 'ok' => false, 'message' => __( 'Please enter a valid email address.', 'tcc-assistant' ) ), 422 );
		}
		if ( 'cart' === $kind ) {
			return self::respond( TCCA_Mail::send_cart( $email ) );
		}
		if ( 'order' === $kind ) {
			$order = wc_get_order( absint( preg_replace( '/\D+/', '', (string) $req->get_param( 'order' ) ) ) );
			$owner = $order && ( ( $ident && 'login' === $ident['via'] && (int) $order->get_customer_id() === (int) $ident['uid'] ) || hash_equals( strtolower( (string) $order->get_billing_email() ), strtolower( $email ) ) );
			if ( ! $owner ) {
				return self::respond( array( 'ok' => false, 'message' => __( 'I could not match that order to this email.', 'tcc-assistant' ) ), 403 );
			}
			// Always to the address on the order, never an arbitrary one.
			return self::respond( TCCA_Mail::send_order( $order->get_billing_email() ? $order->get_billing_email() : $email, $order ) );
		}
		return self::respond( array( 'ok' => false, 'message' => '' ), 400 );
	}

	/**
	 * Thumbs up/down on a knowledge answer. Thumbs-down questions appear in Insights so the owner can fix them.
	 */
	public static function feedback( WP_REST_Request $req ) {
		if ( TCCA_Core::rate_ok( 'feedback', 30, MINUTE_IN_SECONDS ) ) {
			$up    = (bool) $req->get_param( 'helpful' );
			$trace = (array) $req->get_param( 'trace' );
			if ( ! empty( $trace['t'] ) ) {
				TCCA_Index::learn( (string) $req->get_param( 'message' ), array( 't' => sanitize_key( (string) $trace['t'] ), 'o' => absint( $trace['o'] ?? 0 ), 'p' => sanitize_key( (string) ( $trace['p'] ?? '' ) ) ), $up ? 1 : -1 );
			}
			TCCA_Core::log( (string) $req->get_param( 'session' ), $up ? 'thumb_up' : 'thumb_down', mb_substr( sanitize_text_field( (string) $req->get_param( 'message' ) ), 0, 300 ), $up );
		}
		return self::respond( array( 'ok' => true ) );
	}

	public static function order( WP_REST_Request $req ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return self::respond( array( 'reply' => __( 'Order tracking is not available right now.', 'tcc-assistant' ) ), 503 );
		}
		$num   = preg_replace( '/\D+/', '', (string) $req->get_param( 'order' ) );
		$email = sanitize_email( (string) $req->get_param( 'email' ) );
		$r     = TCCA_Engine::guest_lookup( $num, $email );
		TCCA_Core::log( (string) $req->get_param( 'session' ), 'order', 'order lookup', $r['answered'] );
		return self::respond( self::shape( $r ) );
	}

	/**
	 * Sales moments reported by the widget (cart add, checkout tap). Only known products are accepted.
	 */
	public static function track( WP_REST_Request $req ) {
		if ( ! class_exists( 'WooCommerce' ) || ! TCCA_Core::rate_ok( 'track', 60, MINUTE_IN_SECONDS ) ) {
			return self::respond( array( 'ok' => false ), 429 );
		}
		$type = sanitize_key( (string) $req->get_param( 'type' ) );
		$pid  = absint( $req->get_param( 'pid' ) );
		if ( ! in_array( $type, array( 'add', 'checkout' ), true ) ) {
			return self::respond( array( 'ok' => false ), 400 );
		}
		if ( 'add' === $type ) {
			$p = $pid ? wc_get_product( $pid ) : false;
			if ( ! $p ) {
				return self::respond( array( 'ok' => false ), 400 );
			}
			TCCA_Events::log( 'add', (string) $req->get_param( 'session' ), array( 'pid' => $pid, 'qty' => max( 1, min( 20, absint( $req->get_param( 'qty' ) ) ) ) ) );
		} else {
			TCCA_Events::log( 'checkout', (string) $req->get_param( 'session' ) );
		}
		return self::respond( array( 'ok' => true ) );
	}

	/**
	 * "Tell me when it is back" and "please source this for me".
	 */
	public static function lead( WP_REST_Request $req ) {
		if ( '' !== (string) $req->get_param( 'website' ) ) {
			return self::respond( self::shape( TCCA_Engine::out( 'lead', '' ) ) );
		}
		if ( ! TCCA_Core::rate_ok( 'lead', 6, HOUR_IN_SECONDS ) ) {
			return self::respond( self::shape( TCCA_Engine::out( 'lead', __( 'Too many requests from this device. Please try again later, or use the pharmacist button.', 'tcc-assistant' ), array( 'answered' => false ) ) ), 429 );
		}
		$kind  = 'request' === (string) $req->get_param( 'kind' ) ? 'request' : 'restock';
		$user  = wp_get_current_user();
		$email = $user->exists() ? $user->user_email : sanitize_email( (string) $req->get_param( 'email' ) );
		$sess  = (string) $req->get_param( 'session' );
		$pid   = absint( $req->get_param( 'pid' ) );
		$name  = sanitize_text_field( (string) $req->get_param( 'name' ) );
		$term  = sanitize_text_field( (string) $req->get_param( 'term' ) );
		if ( ! is_email( $email ) ) {
			return self::respond( self::shape( TCCA_Engine::out( 'lead', __( 'Please enter a valid email address.', 'tcc-assistant' ), array( 'answered' => false ) ) ), 422 );
		}
		if ( 'restock' === $kind ) {
			$p = $pid && function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : false;
			if ( ! $p ) {
				return self::respond( self::shape( TCCA_Engine::out( 'lead', __( 'I could not find that product.', 'tcc-assistant' ), array( 'answered' => false ) ) ), 400 );
			}
			$term = wp_strip_all_tags( $p->get_name() );
		} elseif ( strlen( $term ) < 3 ) {
			return self::respond( self::shape( TCCA_Engine::out( 'lead', __( 'Please tell me what you are looking for.', 'tcc-assistant' ), array( 'answered' => false ) ) ), 422 );
		}
		$res = TCCA_Leads::add( $kind, $email, $pid, $term, $name, $sess );
		if ( 'error' === $res ) {
			return self::respond( self::shape( TCCA_Engine::out( 'lead', __( 'Sorry, I could not save that. Please try the pharmacist button.', 'tcc-assistant' ), array( 'answered' => false ) ) ), 500 );
		}
		if ( 'ok' === $res && 'request' === $kind ) {
			// A real person sees a sourcing request: pharmacy inbox when the theme has one, otherwise email.
			$fields = array( 'Name' => $name ? $name : 'Chat assistant', 'Contact' => $email, 'Message' => 'Please source: ' . $term, 'Source' => 'Shop assistant (not in catalogue)' );
			if ( function_exists( 'mm_save_enquiry' ) ) {
				mm_save_enquiry( 'contact', $fields, $email );
			} else {
				wp_mail( TCCA_Core::get( 'handoff_email' ) ?: get_option( 'admin_email' ), '[' . get_bloginfo( 'name' ) . '] Product request: ' . $term, "Contact: {$email}\nWanted: {$term}", array( 'Reply-To: ' . $email ) );
			}
		}
		$text = 'restock' === $kind
			? sprintf( /* translators: %s: product */ __( 'Done. I will email you once, as soon as %s is back in stock.', 'tcc-assistant' ), $term )
			: __( 'Thank you. A pharmacist will look into sourcing it and email you.', 'tcc-assistant' );
		return self::respond( self::shape( TCCA_Engine::out( 'lead', $text ) ) );
	}

	public static function handoff( WP_REST_Request $req ) {
		// Honeypot: real visitors never fill this.
		if ( '' !== (string) $req->get_param( 'website' ) ) {
			return self::respond( array( 'ok' => true ) );
		}
		if ( ! TCCA_Core::rate_ok( 'handoff', 5, HOUR_IN_SECONDS ) ) {
			return self::respond( array( 'ok' => false, 'message' => __( 'Too many messages. Please try again later.', 'tcc-assistant' ) ), 429 );
		}
		$user    = wp_get_current_user();
		$name    = sanitize_text_field( (string) $req->get_param( 'name' ) );
		$contact = $user->exists() ? $user->user_email : sanitize_text_field( (string) $req->get_param( 'contact' ) );
		$message = mb_substr( sanitize_textarea_field( (string) $req->get_param( 'message' ) ), 0, 1000 );
		if ( strlen( $message ) < 3 || '' === $contact || ( ! is_email( $contact ) && strlen( preg_replace( '/\D+/', '', $contact ) ) < 7 ) ) {
			return self::respond( array( 'ok' => false, 'message' => __( 'Please write a message and add a valid email or phone number.', 'tcc-assistant' ) ), 422 );
		}
		$transcript = '';
		foreach ( array_slice( (array) $req->get_param( 'history' ), -8 ) as $h ) {
			if ( is_array( $h ) && isset( $h['text'] ) ) {
				$who         = ( isset( $h['role'] ) && 'assistant' === $h['role'] ) ? 'Assistant' : 'Visitor';
				$transcript .= $who . ': ' . mb_substr( sanitize_textarea_field( (string) $h['text'] ), 0, 300 ) . "\n";
			}
		}
		$fields = array(
			'Name'       => $user->exists() ? $user->display_name : ( $name ? $name : 'Chat assistant' ),
			'Contact'    => $contact,
			'Message'    => $message,
			'Source'     => 'Shop assistant chat',
			'Transcript' => "\n" . $transcript,
		);
		if ( function_exists( 'mm_save_enquiry' ) ) {
			mm_save_enquiry( 'contact', $fields, is_email( $contact ) ? $contact : '' );
		} else {
			$to = TCCA_Core::get( 'handoff_email' ) ?: get_option( 'admin_email' );
			$body = '';
			foreach ( $fields as $k => $v ) {
				$body .= $k . ': ' . $v . "\n";
			}
			$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
			if ( is_email( $contact ) ) {
				$headers[] = 'Reply-To: ' . $contact;
			}
			wp_mail( $to, '[' . get_bloginfo( 'name' ) . '] Chat assistant message', $body, $headers );
		}
		// An extra copy to a dedicated inbox, if the owner set one.
		$extra = TCCA_Core::get( 'handoff_email' );
		if ( $extra && is_email( $extra ) && function_exists( 'mm_save_enquiry' ) ) {
			wp_mail( $extra, '[' . get_bloginfo( 'name' ) . '] Chat assistant message', "Contact: {$contact}\nMessage: {$message}\n\n{$transcript}" );
		}
		return self::respond( array( 'ok' => true, 'message' => __( 'Thank you. A pharmacist will get back to you using the details you gave.', 'tcc-assistant' ) ) );
	}
}
