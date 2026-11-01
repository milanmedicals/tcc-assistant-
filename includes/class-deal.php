<?php
/**
 * The salesperson: handles price objections, discount requests, doubts about authenticity and "let me think about it".
 *
 * Rules it follows (all enforced on the server, never trusted from the browser):
 * - Nobody gets a code just for asking. A direct request is answered with "may I ask what's behind it?".
 * - A code is offered only with a reason (price concern, cheaper elsewhere, first order, loyal customer) AND a real
 *   product or cart under discussion.
 * - The code itself is limited by TCCA_Coupons: one per customer per month, locked to their email, expiring, single use.
 * - Reasons to buy are the owner's own lines plus facts from the catalogue (flags, reviews, MRP). Nothing is invented,
 *   no fake urgency, no medical claims.
 *
 * @package TCCAssistant
 */

defined( 'ABSPATH' ) || exit;

class TCCA_Deal {

	/** How long a negotiation (and a signed offer) stays valid, in seconds. */
	const WINDOW = 1800;

	/* ============================================================ detectors */

	/** "Too expensive", "can't afford", "price is steep": a complaint, not a question about price. */
	public static function is_price_objection( $m ) {
		// "Is it expensive?" is a question; answer it normally.
		if ( preg_match( '/^\s*(?:is|are|does|do|will|would|was)\b[^.!]*\b(?:expensive|costly|pricey)\b[^.!]*\?\s*$/i', $m ) ) {
			return false;
		}
		return (bool) preg_match( '/\b(expensive|costly|pricey|pricy|overpriced|over-priced|unaffordable)\b|\b(?:can\'?t|cannot|can not|couldn\'?t|unable to) afford\b|\b(?:out of|over|beyond|above|tight on|tight)\s+(?:my\s+)?budget\b|\b(?:price|prices|cost|costs|rate)\s+(?:is |are |seems? |looks? |feels? )?(?:(?:a bit|a little|rather|quite|really|too|very|so|pretty) )*(?:high|steep|much|heavy)\b|\b(?:high|steep) (?:price|cost)\b|\b(?:it|this|that)(?:\'s| is) (?:a bit |a little |rather |quite |really )?too much\b|\bcosts? a lot\b|\blot of money\b/i', $m );
	}

	/** "Cheaper elsewhere", "other site has it for less", "price match". */
	public static function is_compare( $m ) {
		$cheap = '(?:cheaper|less expensive|lower price|better price|for less)';
		return (bool) preg_match( '/\b' . $cheap . '\b[^.?!]{0,30}\b(?:elsewhere|somewhere|other|another|there|online|local|nearby|market|competitor|store|pharmacy|site|website|seller|vendor)\b|\b(?:other|another) (?:site|store|pharmacy|seller|website|vendor|shop|chemist)s?\b[^.?!]{0,30}\b' . $cheap . '\b|\bfound (?:it|this|that)?[^.?!]{0,15}\b(?:cheaper|for less)\b|\bprice[- ]match\b|\bcompetitors?\b/i', $m );
	}

	/** Haggling words ("can you reduce the price", "last price"). "Best price" alone is an information question. */
	public static function is_bargain( $m ) {
		return (bool) preg_match( '/\b(?:bargain|negotiat\w*|(?:last|final) price|can you (?:reduce|lower|do better)|reduce (?:the )?price|lower (?:the )?price|any (?:reduction|concession)|come down|do (?:a )?better)\b/i', $m );
	}

	/** A request for a code. "Any offers?" alone is general information, not a request. */
	public static function is_direct_ask( $m ) {
		if ( preg_match( '/^\W*(?:any |some |a |the )?(?:discounts?|coupons?|promo(?: ?codes?)?|vouchers?)\W*$/i', $m ) ) {
			return true;
		}
		return TCCA_Coupons::offer_intent( $m ) && preg_match( '/\b(coupons?|promo|discounts?|vouchers?|codes?)\b/i', $m );
	}

	/** Doubts about authenticity. */
	public static function is_trust( $m ) {
		return (bool) preg_match( '/\b(genuine|authentic|original(?!\s+(?:price|cost|mrp|rate|amount|order|prescription|packaging|bill|invoice))|fake|counterfeit|duplicate|legit|legitimate|scam|trustworthy)\b|\bcan i trust\b/i', $m );
	}

	/** "Let me think about it", "maybe later". */
	public static function is_hesitation( $m ) {
		return str_word_count( $m ) <= 9 && preg_match( '/^\W*(?:ok(?:ay)?[, ]+|hmm+[, ]+)?(?:let me (?:think|check|see|decide|consider)|i(?:\'ll| will| am going to) (?:think|see|decide|check|come back)|maybe (?:later|tomorrow|next time)|not now|later|need (?:some )?time|will decide|thinking about it|just looking|not sure(?: yet)?)\b/i', $m );
	}

	/** "Yes, send me the code" after an offer was made. */
	public static function is_accept( $m ) {
		return (bool) preg_match( '/\b(?:send|give|get|take|want)\b[^.?!]{0,25}\b(?:code|coupon)\b|^\W*(?:yes|yeah|yep|ok(?:ay)?|sure|please|go ahead|do it)\W*$/i', $m );
	}

	/** "Help me reach the bigger discount". */
	public static function is_upgrade( $m ) {
		return (bool) preg_match( '/\b(?:what to add|bigger|higher|reach|unlock|upgrade|more discount|add more)\b/i', $m );
	}

	/**
	 * A short answer to "what's behind the request?".
	 *
	 * @return string price|compare|bulk|first|loyal|none|'' (unknown)
	 */
	public static function reason_from_reply( $m ) {
		if ( self::is_compare( $m ) ) {
			return 'compare';
		}
		if ( preg_match( '/\b(?:bulk|wholesale|large quantit\w*|big order|large order|many (?:packs?|boxes|strips|units|months)|stock up|several (?:packs?|boxes|months)|for (?:\d+|several|a few|many) months)\b/i', $m ) ) {
			return 'bulk';
		}
		if ( preg_match( '/\bfirst[- ](?:time|order|purchase)\b|\bnew (?:customer|here|user)\b|\bnever (?:ordered|bought)\b/i', $m ) ) {
			return 'first';
		}
		if ( preg_match( '/\b(?:regular|returning|repeat|old|loyal|existing)\b[^.?!]{0,12}\bcustomer\b|\balways (?:buy|order)\b|\bordered (?:before|many times)\b/i', $m ) ) {
			return 'loyal';
		}
		if ( self::is_price_objection( $m ) || preg_match( '/\b(price|cost|budget|afford|money|expensive)\b/i', $m ) ) {
			return 'price';
		}
		if ( preg_match( '/^\W*(?:just (?:checking|asking|curious|looking)|no reason|nothing|not really|none)\W*$/i', $m ) ) {
			return 'none';
		}
		return '';
	}

	/* ============================================================ state */

	private static function key( $ctx ) {
		$sid = isset( $ctx['session'] ) ? preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $ctx['session'] ) ) : '';
		return 'tcca_deal_' . md5( $sid . '|' . TCCA_Core::ip() );
	}

	private static function load( $ctx ) {
		$s = get_transient( self::key( $ctx ) );
		return is_array( $s ) ? $s : array();
	}

	private static function save( $ctx, $s ) {
		set_transient( self::key( $ctx ), $s, self::WINDOW );
	}

	/* ============================================================ signed offers */

	/** A signed, expiring offer. The coupon endpoint refuses to issue anything without one. */
	public static function token( array $d ) {
		$d['exp'] = time() + self::WINDOW;
		$b        = rtrim( strtr( base64_encode( wp_json_encode( $d ) ), '+/', '-_' ), '=' );
		return $b . '.' . hash_hmac( 'sha256', $b, wp_salt( 'auth' ) );
	}

	/** @return array|null Verified offer data, or null. */
	public static function verify( $tok ) {
		$p = explode( '.', (string) $tok );
		if ( 2 !== count( $p ) || ! hash_equals( hash_hmac( 'sha256', $p[0], wp_salt( 'auth' ) ), $p[1] ) ) {
			return null;
		}
		$d = json_decode( (string) base64_decode( strtr( $p[0], '-_', '+/' ) ), true );
		if ( ! is_array( $d ) || empty( $d['exp'] ) || (int) $d['exp'] < time() ) {
			return null;
		}
		return array(
			'pct'    => isset( $d['pct'] ) ? (int) $d['pct'] : 0,
			'min'    => isset( $d['min'] ) ? (float) $d['min'] : 0.0,
			'pid'    => isset( $d['pid'] ) ? (int) $d['pid'] : 0,
			'reason' => isset( $d['reason'] ) ? sanitize_key( $d['reason'] ) : '',
		);
	}

	/* ============================================================ catalogue facts */

	private static function cart() {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		if ( ! WC()->cart && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		return WC()->cart && ! WC()->cart->is_empty() ? WC()->cart : null;
	}

	/** The email we already know for this visitor: signed in, or verified in this chat. */
	private static function known_email() {
		$u = wp_get_current_user();
		if ( is_user_logged_in() && $u && is_email( $u->user_email ) ) {
			return $u->user_email;
		}
		$id = TCCA_Account::identity();
		return ( $id && ! empty( $id['email'] ) && is_email( $id['email'] ) ) ? $id['email'] : '';
	}

	/** What is the visitor talking about? Product named now, then the last one shown, then the priciest in the cart. */
	private static function subject( $m, $ctx ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return null;
		}
		$q = strtolower( $m );
		$q = preg_replace( '/\b(?:i|im|i\'m|find|found|think|feel|it|its|it\'s|is|are|was|were|this|that|these|those|too|so|very|really|quite|bit|little|a|an|the|expensive|costly|pricey|pricy|overpriced|price|prices|cost|costs|much|high|steep|why|how|can|could|you|reduce|lower|please|pls|me|my|for|to|of|and|but|get|give|any|some|discount|coupon|promo|code|offer|deal|cheaper|less|elsewhere|other|another|site|store|seller|than|here|there|just|afford|cannot|cant|can\'t|budget|out|over|beyond|lot|money|bargain|negotiate|best|last|final|do|does|did|not|no|yes|ok|okay|hi|hello|sir|madam|want|need|looking|buy|however|at|in|on|with|about|medicine|medicines|product|item|one|first|time|customer|regular|returning|large|quantity|ordering|order)\b/', ' ', $q );
		$q = trim( preg_replace( '/[^a-z0-9.\s\-\/]+|\s+/', ' ', $q ) );
		if ( strlen( $q ) >= 3 ) {
			$f = TCCA_Engine::find_products( $q, 1 );
			if ( $f ) {
				return $f[0];
			}
		}
		if ( ! empty( $ctx['pid'] ) ) {
			$p = wc_get_product( (int) $ctx['pid'] );
			if ( $p ) {
				return $p;
			}
		}
		$cart = self::cart();
		if ( $cart ) {
			$best = null;
			$top  = -1;
			foreach ( $cart->get_cart() as $item ) {
				if ( (float) $item['line_total'] > $top ) {
					$top  = (float) $item['line_total'];
					$best = wc_get_product( $item['product_id'] );
				}
			}
			return $best ? $best : null;
		}
		return null;
	}

	/** Order value the visitor is working with: the cart, else one pack of the product. */
	private static function basis( $p ) {
		$cart = self::cart();
		if ( $cart ) {
			return (float) $cart->get_subtotal();
		}
		if ( $p ) {
			return function_exists( 'mm_price_data' ) ? (float) mm_price_data( $p )['price'] : (float) $p->get_price();
		}
		return 0.0;
	}

	/**
	 * Why this costs what it costs: the owner's own lines plus facts that are true of this product in the catalogue.
	 *
	 * @return string[]
	 */
	public static function value_points( $p ) {
		$own = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) TCCA_Core::get( 'value_points' ) ) as $l ) {
			if ( '' !== trim( $l ) ) {
				$own[] = trim( $l );
			}
		}
		$facts = array();
		if ( $p ) {
			if ( function_exists( 'mm_product_flag' ) ) {
				if ( mm_product_flag( $p, 'cold_chain' ) ) {
					$facts[] = __( 'It is temperature-sensitive, so it has to be stored and shipped with care, and that is part of the price.', 'tcc-assistant' );
				}
				if ( mm_product_flag( $p, 'imported' ) ) {
					$facts[] = __( 'It is an imported medicine, so sourcing and import handling are built into the price.', 'tcc-assistant' );
				}
				if ( mm_product_flag( $p, 'rx' ) ) {
					$facts[] = __( 'It is a prescription medicine, supplied only against a valid prescription.', 'tcc-assistant' );
				}
			}
			$n   = (int) $p->get_review_count();
			$avg = (float) $p->get_average_rating();
			if ( $n >= 3 && $avg >= 4 ) {
				$facts[] = sprintf( /* translators: 1: rating 2: count */ __( 'Customers rate it %1$s out of 5 across %2$d reviews.', 'tcc-assistant' ), number_format_i18n( $avg, 1 ), $n );
			}
		}
		$out = array();
		if ( $own ) {
			$out[] = $own[0];
		}
		foreach ( $facts as $f ) {
			if ( count( $out ) < 3 ) {
				$out[] = $f;
			}
		}
		if ( count( $out ) < 3 && isset( $own[1] ) ) {
			$out[] = $own[1];
		}
		return $out;
	}

	/** Chips that help the customer save without a code. */
	public static function save_chips() {
		return array(
			array( __( 'Show bulk savings', 'tcc-assistant' ), 'How can I save with bigger packs?' ),
			array( __( 'Talk to a pharmacist', 'tcc-assistant' ), 'I want to talk to a pharmacist' ),
		);
	}

	/**
	 * What would make this order bigger, in the customer's interest: the next bulk step, a shipping threshold you set,
	 * and the cross-sells you linked in WooCommerce.
	 *
	 * @return array{lines:string[],cards:array}
	 */
	public static function upsell( $p ) {
		$lines = array();
		foreach ( TCCA_Sales::cart_nudges() as $n ) {
			$lines[] = '- ' . $n;
		}
		if ( ! $lines && $p && ! self::cart() ) {
			$t = TCCA_Sales::best_tier( $p );
			if ( $t ) {
				$lines[] = '- ' . sprintf( __( '%1$s: %2$s for %3$s is %4$d%% less per unit than a single pack.', 'tcc-assistant' ), wp_strip_all_tags( $p->get_name() ), $t['label'], TCCA_Core::money( $t['total'] ), (int) $t['save'] );
			}
		}
		$cards = TCCA_Sales::cross_sell_cards();
		if ( ! $cards && $p ) {
			foreach ( array_unique( array_map( 'intval', array_merge( (array) $p->get_cross_sell_ids(), (array) $p->get_upsell_ids() ) ) ) as $id ) {
				$o = $id !== (int) $p->get_id() ? wc_get_product( $id ) : null;
				if ( $o && $o->is_visible() && $o->is_in_stock() ) {
					$cards[] = TCCA_Engine::product_card( $o );
				}
				if ( count( $cards ) >= 2 ) {
					break;
				}
			}
		}
		return array( 'lines' => array_slice( $lines, 0, 3 ), 'cards' => $cards );
	}

	/* ============================================================ offer maths */

	/**
	 * What can be offered right now. With a bigger "spend more" tier configured, a visitor below the threshold is
	 * shown both options before anything is issued, because the one-code-a-month rule means they only get one.
	 */
	private static function plan( $p ) {
		$base    = max( 1, min( 50, (int) TCCA_Core::get( 'coupon_pct' ) ) );
		$min     = (float) TCCA_Core::get( 'coupon_min' );
		$big_pct = (int) TCCA_Core::get( 'coupon_big_pct' );
		$big_min = (float) TCCA_Core::get( 'coupon_big_min' );
		$basis   = self::basis( $p );
		if ( $big_pct > $base && $big_min > 0 ) {
			if ( $basis >= $big_min ) {
				return array( 'pct' => min( 50, $big_pct ), 'min' => $big_min, 'gap' => 0.0 );
			}
			return array( 'pct' => $base, 'min' => $min, 'gap' => $big_min - $basis, 'big_pct' => min( 50, $big_pct ), 'big_min' => $big_min );
		}
		return array( 'pct' => $base, 'min' => $min, 'gap' => 0.0 );
	}

	/* ============================================================ wording */

	private static function empathy( $reason, $name, $m ) {
		$i = abs( crc32( $m ) ) % 3;
		if ( 'compare' === $reason ) {
			return __( 'Thank you for telling me, I appreciate you being upfront about it.', 'tcc-assistant' );
		}
		if ( 'first' === $reason ) {
			return __( 'Welcome, and thank you for choosing us for your first order!', 'tcc-assistant' );
		}
		if ( 'loyal' === $reason ) {
			return __( 'Thank you for coming back to us, it genuinely means a lot.', 'tcc-assistant' );
		}
		if ( '' === $name ) {
			return __( 'I understand, price matters.', 'tcc-assistant' );
		}
		$v = array(
			sprintf( __( 'I understand. %s is not a small spend, and it is fair to want good value.', 'tcc-assistant' ), $name ),
			sprintf( __( 'That is a fair point, and I hear you. %s is a significant purchase.', 'tcc-assistant' ), $name ),
			sprintf( __( 'I completely understand, price matters. Let me be straight with you about %s.', 'tcc-assistant' ), $name ),
		);
		return $v[ $i ];
	}

	/* ============================================================ main entry */

	/**
	 * @param string $m       Message (follow-ups already resolved to a product name).
	 * @param array  $history Recent lines.
	 * @param array  $ctx     pid, pids, vtoken, session.
	 * @return array|null Engine payload, or null when this is not a sales moment.
	 */
	public static function handle( $m, $history, $ctx ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return null;
		}
		$st    = self::load( $ctx );
		$now   = time();
		$fresh = static function ( $k ) use ( $st, $now ) {
			return ! empty( $st[ $k ] ) && ( $now - (int) $st[ $k ] ) < self::WINDOW;
		};

		// An offer is waiting for the customer's choice (the "spend more, save more" step).
		if ( ! empty( $st['pending'] ) && $fresh( 't_pending' ) ) {
			if ( self::is_accept( $m ) && ! self::is_upgrade( $m ) ) {
				return self::deliver( $st['pending'], $ctx );
			}
			if ( self::is_upgrade( $m ) ) {
				return self::upgrade( $st['pending'] );
			}
		}

		// Doubts about authenticity: answer honestly, no discount.
		if ( self::is_trust( $m ) ) {
			return self::trust( $m, $ctx );
		}
		// "Let me think about it": no pressure, keep the cart safe.
		if ( self::is_hesitation( $m ) ) {
			return self::hesitation();
		}

		$asked  = $fresh( 'asked' );
		$reason = '';
		if ( $asked && str_word_count( $m ) <= 14 ) {
			$reason = self::reason_from_reply( $m );
		}
		if ( '' === $reason && ! empty( $st['await'] ) && $fresh( 't_await' ) && self::subject( $m, array() ) ) {
			$reason = (string) $st['await']; // They were asked which medicine; now they have said.
		}
		if ( '' === $reason ) {
			if ( self::is_compare( $m ) ) {
				$reason = 'compare';
			} elseif ( self::is_price_objection( $m ) ) {
				$reason = 'price';
			}
		}

		if ( '' === $reason ) {
			if ( self::is_bargain( $m ) || self::is_direct_ask( $m ) ) {
				return self::ask_why( $m, $ctx, $st, $asked );
			}
			return null;
		}

		$st['asked'] = 0;

		if ( 'none' === $reason ) {
			self::save( $ctx, $st );
			$s          = TCCA_Sales::savings( $m );
			$s['reply'] = __( 'No problem at all. I keep discount codes for specific situations such as a price concern, a better price elsewhere or a bigger order. Here is how you can save without one:', 'tcc-assistant' ) . "\n" . $s['reply'];
			return $s;
		}
		if ( 'bulk' === $reason ) {
			self::save( $ctx, $st );
			$s          = TCCA_Sales::savings( $m );
			$s['reply'] = __( 'Thank you, that helps. For larger quantities the pack prices are the best route, and a pharmacist can quote you directly for very large orders.', 'tcc-assistant' ) . "\n" . $s['reply'];
			$s['actions'] = TCCA_Engine::human_actions( $m );
			return $s;
		}

		$p = self::subject( $m, $ctx );
		if ( ! $p ) {
			// Cannot discount thin air: find out what they want to buy.
			$st['await']   = $reason;
			$st['t_await'] = $now;
			self::save( $ctx, $st );
			return TCCA_Engine::out( 'deal_product', __( 'I understand. Which medicine are you looking at? Tell me and I will see what I can do for you.', 'tcc-assistant' ), array( 'chips' => TCCA_Engine::default_chips_public() ) );
		}
		$st['await'] = '';
		return self::offer( $reason, $p, $m, $ctx, $st );
	}

	/* ============================================================ responses */

	/** A direct "can I get a discount?": ask why, do not just give. */
	private static function ask_why( $m, $ctx, $st, $asked ) {
		if ( $asked ) {
			// Asked already and they still gave no reason: be kind, be clear, still be useful.
			$st['asked'] = 0;
			self::save( $ctx, $st );
			$s          = TCCA_Sales::savings( $m );
			$s['reply'] = __( 'I would love to, but I keep discount codes for real situations: a price concern, a better price elsewhere, or a first or larger order. Until then, here is how you can save:', 'tcc-assistant' ) . "\n" . $s['reply'];
			return $s;
		}
		$st['asked'] = time();
		self::save( $ctx, $st );
		return self::ask_payload();
	}

	/** The question itself, also used when the coupon endpoint is called without a valid offer. */
	public static function ask_payload() {
		return TCCA_Engine::out(
			'deal_ask',
			__( 'I would be happy to see what I can do. So I can help properly, may I ask what is behind it? Is the price a concern, have you seen it cheaper somewhere else, or are you planning a larger order?', 'tcc-assistant' ),
			array(
				'chips' => array(
					array( __( 'It is too expensive for me', 'tcc-assistant' ), 'It is too expensive for me' ),
					array( __( 'Found it cheaper elsewhere', 'tcc-assistant' ), 'I found it cheaper elsewhere' ),
					array( __( 'Ordering a large quantity', 'tcc-assistant' ), 'I am ordering a large quantity' ),
					array( __( 'First-time customer', 'tcc-assistant' ), 'I am a first-time customer' ),
					array( __( 'Regular customer', 'tcc-assistant' ), 'I am a regular customer' ),
				),
			)
		);
	}

	/** Empathise, justify the price, then offer a small code. */
	private static function offer( $reason, $p, $m, $ctx, $st ) {
		$name  = wp_strip_all_tags( $p->get_name() );
		$card  = TCCA_Engine::product_card( $p );
		$lines = array( self::empathy( $reason, $name, $m ) );

		if ( in_array( $reason, array( 'price', 'compare' ), true ) ) {
			if ( 'compare' === $reason ) {
				$lines[] = __( 'Cheaper is not always the same product, so it is worth checking that the other seller is a licensed pharmacy and that the strength, pack size and expiry match.', 'tcc-assistant' );
			}
			$pts = self::value_points( $p );
			if ( $pts ) {
				$lines[] = 'compare' === $reason ? __( 'Here is what you get with us:', 'tcc-assistant' ) : __( 'Here is why it is worth it:', 'tcc-assistant' );
				foreach ( $pts as $pt ) {
					$lines[] = '- ' . $pt;
				}
			}
			if ( (int) $card['off'] > 0 && '' !== $card['mrp'] ) {
				$lines[] = sprintf( __( 'It is already %1$d%% below the MRP of %2$s.', 'tcc-assistant' ), (int) $card['off'], $card['mrp'] );
			}
			$nudges = self::cart() ? TCCA_Sales::cart_nudges() : array();
			if ( $nudges ) {
				$lines[] = __( 'And on your cart:', 'tcc-assistant' );
				foreach ( array_slice( $nudges, 0, 2 ) as $n ) {
					$lines[] = '- ' . $n;
				}
			} else {
				$t = TCCA_Sales::best_tier( $p );
				if ( $t ) {
					$lines[] = sprintf( __( 'And if you will need more than one, %1$s for %2$s is %3$d%% less per unit.', 'tcc-assistant' ), $t['label'], TCCA_Core::money( $t['total'] ), (int) $t['save'] );
				}
			}
		}

		$cards = array( $card );
		$base  = array( 'pid' => $p->get_id(), 'reason' => $reason );

		// Codes switched off or this item excluded: still sell, just without a code.
		if ( ! TCCA_Coupons::enabled() || TCCA_Coupons::excluded( $p ) ) {
			$up = self::upsell( $p );
			if ( $up['lines'] ) {
				$lines[] = __( 'To bring the cost down:', 'tcc-assistant' );
				$lines   = array_merge( $lines, $up['lines'] );
			}
			$lines[] = __( 'If you would like, a pharmacist can also look at your options with you.', 'tcc-assistant' );
			self::save( $ctx, $st );
			return TCCA_Engine::out( 'deal', implode( "\n", $lines ), array( 'cards' => array_merge( $cards, $up['cards'] ), 'actions' => TCCA_Engine::human_actions( $m ), 'chips' => self::cta_chips( $card ) ) );
		}

		$plan = self::plan( $p );

		// "Spend a little more, save more": show both options before using the customer's one code of the month.
		if ( $plan['gap'] > 0 ) {
			$lines[] = sprintf(
				/* translators: 1: base % 2: big % 3: amount to add 4: threshold */
				__( 'Here is what I can do. I can give you %1$d%% off right now, or %2$d%% if your order reaches %4$s (just %3$s more). I can only give one chat code a month, so choose what suits you.', 'tcc-assistant' ),
				$plan['pct'],
				$plan['big_pct'],
				TCCA_Core::money( $plan['gap'] ),
				TCCA_Core::money( $plan['big_min'] )
			);
			$st['pending']   = $base + array( 'pct' => $plan['pct'], 'min' => $plan['min'], 'big_pct' => $plan['big_pct'], 'big_min' => $plan['big_min'] );
			$st['t_pending'] = time();
			self::save( $ctx, $st );
			return TCCA_Engine::out(
				'deal',
				implode( "\n", $lines ),
				array(
					'cards' => $cards,
					'chips' => array(
						array( sprintf( __( 'Send me the %d%% code now', 'tcc-assistant' ), $plan['pct'] ), 'Send me the code now' ),
						array( sprintf( __( 'Help me reach %d%%', 'tcc-assistant' ), $plan['big_pct'] ), 'Show me what to add to reach the bigger discount' ),
					),
				)
			);
		}

		$deal = $base + array( 'pct' => $plan['pct'], 'min' => $plan['min'] );
		self::save( $ctx, $st );
		return self::close( $lines, $cards, $deal, $m );
	}

	/** The closing step: issue to a known email, or ask for one. */
	private static function close( array $lines, array $cards, array $deal, $m ) {
		$email = self::known_email();
		if ( $email ) {
			$lock = TCCA_Coupons::lock_info( $email );
			if ( ! $lock || $lock['unused'] ) {
				$lines[] = sprintf( __( 'So let me help with the price: I am giving you a small %d%% one-time discount for this order.', 'tcc-assistant' ), $deal['pct'] );
			}
			$cp = TCCA_Coupons::reply( $email, $deal );
			return TCCA_Engine::out( 'deal', implode( "\n", $lines ) . "\n\n" . $cp['reply'], array( 'cards' => array_merge( $cards, $cp['cards'] ), 'chips' => $cp['chips'] ) );
		}
		$lines[] = sprintf( __( 'So let me help with the price: I would like to give you a small %d%% one-time discount for this order. Where should I send it? Enter your email and I will create it right away.', 'tcc-assistant' ), $deal['pct'] );
		return TCCA_Engine::out( 'deal', implode( "\n", $lines ), array( 'cards' => $cards, 'form' => 'coupon', 'dtoken' => self::token( $deal ) ) );
	}

	/** The customer chose the code now. */
	private static function deliver( $pending, $ctx ) {
		$deal = array( 'pid' => (int) $pending['pid'], 'reason' => (string) $pending['reason'], 'pct' => (int) $pending['pct'], 'min' => (float) $pending['min'] );
		$st   = self::load( $ctx );
		unset( $st['pending'], $st['t_pending'] );
		self::save( $ctx, $st );
		return self::close( array( __( 'Good choice.', 'tcc-assistant' ) ), array(), $deal, '' );
	}

	/** The customer wants the bigger tier: show what would get them there. */
	private static function upgrade( $pending ) {
		$p   = ! empty( $pending['pid'] ) ? wc_get_product( (int) $pending['pid'] ) : null;
		$up  = self::upsell( $p );
		$gap = isset( $pending['big_min'] ) ? (float) $pending['big_min'] - self::basis( $p ) : 0.0;
		$tx  = $gap > 0
			? sprintf( __( 'You are %1$s away from %2$d%% off. Here are the easiest ways to get there:', 'tcc-assistant' ), TCCA_Core::money( $gap ), (int) $pending['big_pct'] )
			: sprintf( __( 'Your order already qualifies for %d%% off. Send me your email and I will create the code.', 'tcc-assistant' ), (int) $pending['big_pct'] );
		if ( $up['lines'] ) {
			$tx .= "\n" . implode( "\n", $up['lines'] );
		}
		return TCCA_Engine::out( 'deal', $tx, array( 'cards' => $up['cards'], 'chips' => array( array( __( 'Send me the code now', 'tcc-assistant' ), 'Send me the code now' ), array( __( 'Show my cart', 'tcc-assistant' ), 'Show my cart' ) ) ) );
	}

	private static function trust( $m, $ctx ) {
		$p     = self::subject( $m, $ctx );
		$lines = array( __( 'That is a very fair question, especially with medicines.', 'tcc-assistant' ) );
		foreach ( self::value_points( $p ) as $pt ) {
			$lines[] = '- ' . $pt;
		}
		$lines[] = __( 'You can check the batch number and expiry on the pack when it arrives, and a pharmacist can answer any question about sourcing directly.', 'tcc-assistant' );
		return TCCA_Engine::out( 'deal_trust', implode( "\n", $lines ), array( 'cards' => $p ? array( TCCA_Engine::product_card( $p ) ) : array(), 'actions' => TCCA_Engine::human_actions( $m ) ) );
	}

	private static function hesitation() {
		if ( self::cart() ) {
			return TCCA_Engine::cart_reply( __( 'Of course, no pressure at all. Your cart is saved, and you can email it to yourself to come back to it whenever you like.', 'tcc-assistant' ) );
		}
		return TCCA_Engine::out( 'deal_think', __( 'Of course, take your time. I am here whenever you want to look again, and a pharmacist can answer any question that would help you decide.', 'tcc-assistant' ), array( 'actions' => TCCA_Engine::human_actions(), 'chips' => TCCA_Engine::default_chips_public() ) );
	}

	/** Next steps after a pitch with no code. */
	private static function cta_chips( $card ) {
		$c = array();
		if ( ! empty( $card['canAdd'] ) ) {
			$c[] = array( sprintf( __( 'Add %s to my cart', 'tcc-assistant' ), $card['name'] ), 'Add ' . $card['name'] . ' to my cart' );
		}
		return array_merge( $c, self::save_chips() );
	}
}
