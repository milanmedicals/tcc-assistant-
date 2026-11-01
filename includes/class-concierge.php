<?php
/**
 * Concierge: the parts of a good shop assistant that are not search.
 *
 *  - Reads the page the visitor is on (product, category, cart, search) and opens with relevant help.
 *  - Handles delivery and payment worries from your real settings (shipping options, payment gateways,
 *    cold-chain flags, your own notes). Where a fact is not configured it says so and offers a pharmacist.
 *  - Rescues out-of-stock sales: in-stock options with the same ingredient, other strengths, a back-in-stock email.
 *  - Closes the sale: what is left to do before paying, then checkout.
 *  - Suggests what customers actually order together, from your order history (TCCA_Together).
 *
 * @package TCCAssistant
 */

defined( 'ABSPATH' ) || exit;

class TCCA_Concierge {

	/* ============================================================ page context */

	/**
	 * What the visitor is looking at, decided on the server. Contains no personal data, so cached pages are safe.
	 *
	 * @return array|null { type, id, name, hello, teaser, chips }
	 */
	public static function page() {
		if ( '1' !== TCCA_Core::get( 'page_on' ) || ! function_exists( 'is_product' ) ) {
			return null;
		}
		$out = array( 'type' => 'other', 'id' => 0, 'name' => '', 'hello' => '', 'teaser' => '', 'chips' => array() );
		if ( is_product() ) {
			$p = wc_get_product( get_queried_object_id() );
			if ( $p ) {
				$n   = wp_strip_all_tags( $p->get_name() );
				$out = array(
					'type'   => 'product',
					'id'     => $p->get_id(),
					'name'   => $n,
					/* translators: %s: product */
					'hello'  => sprintf( __( 'You are looking at **%s**. Ask me about it: price, strengths, delivery, alternatives, or I can add it to your cart.', 'tcc-assistant' ), $n ),
					'teaser' => sprintf( __( 'Questions about %s?', 'tcc-assistant' ), mb_strimwidth( $n, 0, 34, '…' ) ),
					'chips'  => array(
						array( __( 'About this medicine', 'tcc-assistant' ), 'Tell me about ' . $n ),
						array( __( 'In stock? Delivery?', 'tcc-assistant' ), 'Is ' . $n . ' in stock and how long does delivery take?' ),
						array( __( 'Same ingredient, other options', 'tcc-assistant' ), 'Alternatives to ' . $n ),
						array( __( 'Is it genuine?', 'tcc-assistant' ), 'Is ' . $n . ' genuine?' ),
						array( __( 'Add to cart', 'tcc-assistant' ), 'Add ' . $n . ' to my cart' ),
					),
				);
			}
		} elseif ( function_exists( 'is_product_category' ) && is_product_category() ) {
			$t = get_queried_object();
			if ( $t && ! empty( $t->term_id ) ) {
				$n   = html_entity_decode( $t->name );
				$out = array(
					'type'   => 'category',
					'id'     => (int) $t->term_id,
					'name'   => $n,
					'hello'  => sprintf( __( 'You are browsing **%s**. I can show the most popular here, the cheapest, or help you compare two.', 'tcc-assistant' ), $n ),
					'teaser' => sprintf( __( 'Need help choosing in %s?', 'tcc-assistant' ), mb_strimwidth( $n, 0, 28, '…' ) ),
					'chips'  => array(
						array( __( 'Popular here', 'tcc-assistant' ), 'Show popular products in ' . $n ),
						array( __( 'Cheapest here', 'tcc-assistant' ), 'Show cheapest products in ' . $n ),
						array( __( 'Talk to a pharmacist', 'tcc-assistant' ), 'I want to talk to a pharmacist' ),
					),
				);
			}
		} elseif ( function_exists( 'is_cart' ) && is_cart() ) {
			$out = array(
				'type'   => 'cart',
				'id'     => 0,
				'name'   => '',
				'hello'  => __( 'Ready to order? I can check what is left to do before you pay, find savings on your cart, or answer delivery and payment questions.', 'tcc-assistant' ),
				'teaser' => __( 'Questions before you pay?', 'tcc-assistant' ),
				'chips'  => array(
					array( __( 'Help me check out', 'tcc-assistant' ), 'I am ready to order' ),
					array( __( 'Ways to save', 'tcc-assistant' ), 'How can I save on my cart?' ),
					array( __( 'Delivery and payment', 'tcc-assistant' ), 'How long does delivery take and how can I pay?' ),
				),
			);
		} elseif ( function_exists( 'is_search' ) && is_search() && ! empty( $_GET['s'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$s   = mb_substr( sanitize_text_field( wp_unslash( $_GET['s'] ) ), 0, 60 ); // phpcs:ignore WordPress.Security.NonceVerification
			$out = array(
				'type'   => 'search',
				'id'     => 0,
				'name'   => $s,
				'hello'  => sprintf( __( 'Not finding what you need? Tell me what you are looking for (a name, a brand or what it is for) and I will look through the catalogue.', 'tcc-assistant' ) ),
				'teaser' => __( 'Cannot find it? Ask me.', 'tcc-assistant' ),
				'chips'  => array( array( sprintf( __( 'Look for “%s”', 'tcc-assistant' ), mb_strimwidth( $s, 0, 24, '…' ) ), 'I am looking for ' . $s ) ),
			);
		}
		return 'other' === $out['type'] ? null : $out;
	}

	/**
	 * The page the browser says it is on, accepted only if it checks out.
	 *
	 * @param mixed $raw { type, id }.
	 * @return array|null
	 */
	public static function validate_page( $raw ) {
		$raw  = (array) $raw;
		$type = isset( $raw['type'] ) ? sanitize_key( $raw['type'] ) : '';
		$id   = isset( $raw['id'] ) ? absint( $raw['id'] ) : 0;
		if ( 'product' === $type && $id && function_exists( 'wc_get_product' ) ) {
			$p = wc_get_product( $id );
			if ( $p && $p->is_visible() ) {
				return array( 'type' => 'product', 'id' => $id );
			}
		}
		if ( 'category' === $type && $id && term_exists( $id, 'product_cat' ) ) {
			return array( 'type' => 'category', 'id' => $id );
		}
		return in_array( $type, array( 'cart', 'search' ), true ) ? array( 'type' => $type, 'id' => 0 ) : null;
	}

	/* ================================================================== facts */

	/** Names of the payment methods switched on. */
	public static function gateways() {
		$out = array();
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			foreach ( WC()->payment_gateways()->get_available_payment_gateways() as $id => $gw ) {
				$out[ $id ] = wp_strip_all_tags( $gw->get_title() );
			}
			if ( ! $out ) {
				foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gw ) {
					if ( 'yes' === $gw->enabled ) {
						$out[ $id ] = wp_strip_all_tags( $gw->get_title() );
					}
				}
			}
		}
		return array_filter( $out );
	}

	/** The owner's own lines from a setting. */
	private static function lines( $key ) {
		$o = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) TCCA_Core::get( $key ) ) as $l ) {
			if ( '' !== trim( $l ) ) {
				$o[] = trim( $l );
			}
		}
		return $o;
	}

	/** Delivery times exactly as the theme has them, or ''. */
	private static function delivery_line() {
		$opt = static function ( $k ) {
			return function_exists( 'mm_opt' ) ? trim( (string) mm_opt( $k ) ) : '';
		};
		$a = array();
		if ( $opt( 'ship_std_eta' ) ) {
			$a[] = sprintf( __( 'Standard shipping: %s.', 'tcc-assistant' ), $opt( 'ship_std_eta' ) );
		}
		if ( $opt( 'ship_exp_eta' ) ) {
			$a[] = sprintf( __( 'Express shipping: %s.', 'tcc-assistant' ), $opt( 'ship_exp_eta' ) );
		}
		return implode( ' ', $a );
	}

	/** The product the visitor means: the page, the last one shown, or the one in a cart line. */
	private static function subject( $ctx ) {
		if ( ! empty( $ctx['pid'] ) ) {
			$p = wc_get_product( (int) $ctx['pid'] );
			if ( $p ) {
				return $p;
			}
		}
		$c = self::cart();
		if ( $c ) {
			foreach ( $c->get_cart() as $item ) {
				return wc_get_product( $item['product_id'] );
			}
		}
		return null;
	}

	private static function cart() {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		if ( ! WC()->cart && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		return WC()->cart && ! WC()->cart->is_empty() ? WC()->cart : null;
	}

	private static function any_flag( $flag, $ctx ) {
		if ( ! function_exists( 'mm_product_flag' ) ) {
			return false;
		}
		$c = self::cart();
		if ( $c ) {
			foreach ( $c->get_cart() as $item ) {
				if ( mm_product_flag( (int) $item['product_id'], $flag ) ) {
					return true;
				}
			}
		}
		$p = self::subject( $ctx );
		return $p && mm_product_flag( $p, $flag );
	}

	/* ================================================================ worries */

	/**
	 * Which delivery or payment worry is this? '' when it is not one.
	 *
	 * @param string $m Message.
	 * @return string pay_fail|pay_safe|pay_method|ship_safe|ship_time|''
	 */
	public static function worry_kind( $m ) {
		if ( preg_match( '/\b(my order|order #?\d|tracking number|track(?:ing)? (?:my|the|it)|where(?:\'s| is) my|has my order|i (?:placed|ordered|paid))\b/i', $m ) ) {
			return ''; // After-sale questions belong to order tracking.
		}
		$k = array(
			'pay_fail'   => '/\b(payment|card|transaction)\b[^.?!]{0,25}\b(fail\w*|declin\w*|not (?:work\w*|going through|accepted|processing)|error|rejected|denied)\b|\b(declin\w*|rejected)\b[^.?!]{0,15}\b(card|payment)\b|can\'?t (?:pay|complete (?:the )?payment)|unable to pay|couldn\'?t pay/i',
			'pay_safe'   => '/\b(secure|safe|safety|trust\w*|scam|fraud|risky|protect\w*|encrypt\w*)\b[^.?!]{0,30}\b(pay\w*|card|checkout|transaction|card details)\b|\b(pay\w*|card|checkout)\b[^.?!]{0,30}\b(secure|safe|trust\w*|scam|fraud|risky)\b/i',
			'pay_method' => '/\b(cash on delivery|cod|pay on delivery|pay later|pay after|emi|instal(?:l)?ments?|upi|paypal|apple pay|google pay|gpay|bank transfer|wire transfer|net ?banking|crypto\w*|bitcoin|western union|debit card|credit card|visa|master ?card|amex|how (?:can|do|should) i pay|payment (?:options|methods)|ways to pay|do you (?:accept|take)|can i pay)\b/i',
			'ship_safe'  => '/\b(arrive|reach|get|deliver\w*|shipp\w*)\b[^.?!]{0,30}\b(safe\w*|damag\w*|intact|broken|leak\w*|melt\w*|spoil\w*|warm|heat|hot|fresh|properly)\b|\b(damag\w*|lost|stolen|missing|seiz\w*|confiscat\w*|stuck|held)\b[^.?!]{0,25}\b(transit|shipment|parcel|package|delivery|customs|courier)\b|\bwhat if\b[^.?!]{0,30}\b(not (?:arrive|come|deliver\w*|reach)|doesn\'?t (?:arrive|come|reach)|lost|damaged|late|delayed)\b|\bcustoms?\b|\bimport (?:duty|duties|tax\w*|clearance)\b|\bcold[- ]chain\b|\b(?:will|does|is) it (?:stay|be|remain|keep) (?:cold|cool|chilled|refrigerated)\b/i',
			'ship_time'  => '/\b(how (?:long|many days|soon|fast|quickly)|when (?:will|can|would)|delivery (?:time|days|date|estimate|eta)|eta|time to (?:deliver|arrive|reach)|express|overnight|same[- ]day|next[- ]day|fastest)\b[^.?!]{0,40}\b(deliver\w*|ship\w*|arriv\w*|reach|get|dispatch\w*|come)\b|\b(deliver\w*|ship\w*|dispatch\w*)\b[^.?!]{0,20}\b(time|days|how long|eta|estimate|speed|charges?|cost|fee|free)\b/i',
		);
		foreach ( $k as $kind => $re ) {
			if ( preg_match( $re, $m ) ) {
				return $kind;
			}
		}
		return '';
	}

	/** "Ready to order", "how do I order". 'strong' = a commitment, 'help' = asking how. */
	public static function close_kind( $m ) {
		if ( preg_match( '/^\W*(?:ok(?:ay)?[, ]+|yes[, ]+)?(?:i(?:\'ll| will) take (?:it|that|this|one)|let\'?s (?:do it|go|order)|i(?:\'m| am) ready(?: to (?:order|buy|check ?out|pay))?|i want (?:it|this|that)|sold|go ahead(?: and (?:order|buy))?|proceed|(?:order|buy) (?:it|this|that))\W*$/i', $m ) ) {
			return 'strong';
		}
		if ( preg_match( '/\b(how (?:do|can|should) i (?:order|buy|purchase|place (?:an|my|the) order|check ?out)|place (?:an|my|the) order|ready to (?:order|buy|check ?out|pay)|what(?:\'s| is) next|next step|how does ordering work|how to (?:order|buy)|complete (?:my )?(?:order|purchase)|confirm (?:my )?order)\b/i', $m ) ) {
			return 'help';
		}
		return '';
	}

	public static function is_restock_ask( $m ) {
		return (bool) preg_match( '/\b(notify me|let me know|tell me|email me|alert me|inform me)\b[^.?!]{0,30}\b(back in stock|available again|in stock|restock\w*|available|when it(?:\'s| is)? back)\b|\b(back[- ]in[- ]stock|restock\w*|waitlist|wait list)\b|\bwhen (?:will|is) it (?:be )?(?:back|available|in stock|restock\w*)\b/i', $m );
	}

	public static function is_together_ask( $m ) {
		return (bool) preg_match( '/\b(goes|go|pairs?|works?) (?:well )?with\b|\b(?:often|frequently|usually) (?:bought|ordered|added|taken) (?:together|with)\b|\bwhat (?:do|did) (?:people|others|customers) (?:buy|order|add|get)\b|\bbought together\b|\bwhat else (?:should|do|can|might) (?:i|we) (?:get|add|need|buy|order)\b|\banything else (?:i|we) (?:need|should)\b|\bcomplete my (?:order|cart|basket)\b/i', $m );
	}

	/**
	 * Delivery, payment, closing, restock and "bought together" moments.
	 *
	 * @param string $m       Message.
	 * @param array  $history Recent lines.
	 * @param array  $ctx     Context.
	 * @return array|null
	 */
	public static function handle( $m, $history, $ctx, $orig = '' ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return null;
		}
		// Your own written answers always win.
		$kb = TCCA_KB::search( $m, 1 );
		if ( $kb && $kb[0]['entry']['own'] && $kb[0]['score'] >= 2.0 ) {
			return null;
		}
		if ( self::is_restock_ask( $m ) ) {
			return self::restock_ask( $m, $ctx );
		}
		$ck = self::close_kind( '' !== $orig ? $orig : $m ); // As typed: "it" must not have been swapped for a product name.
		if ( '' !== $ck ) {
			return self::close_sale( $ck, $ctx );
		}
		if ( self::is_together_ask( $m ) ) {
			return self::together_reply( $m, $ctx );
		}
		$w = self::worry_kind( $m );
		return '' === $w ? null : self::worry_reply( $w, $m, $ctx );
	}

	/** Chips that move a worried visitor forward. */
	private static function next_chips( $ctx ) {
		if ( self::cart() ) {
			return array( array( __( 'Help me check out', 'tcc-assistant' ), 'I am ready to order' ), array( __( 'Show my cart', 'tcc-assistant' ), 'Show my cart' ) );
		}
		$p = self::subject( $ctx );
		if ( $p ) {
			$c = TCCA_Engine::product_card( $p );
			if ( $c['canAdd'] ) {
				return array( array( sprintf( __( 'Add %s to my cart', 'tcc-assistant' ), $c['name'] ), 'Add ' . $c['name'] . ' to my cart' ) );
			}
		}
		return array();
	}

	private static function worry_reply( $kind, $m, $ctx ) {
		$lines   = array();
		$cards   = array();
		$actions = TCCA_Engine::human_actions( $m );
		$gws     = self::gateways();
		$pfacts  = self::lines( 'payment_facts' );
		$dfacts  = self::lines( 'delivery_facts' );

		if ( 'pay_method' === $kind ) {
			$want = array(
				'cod'    => array( '/cash on delivery|\bcod\b|pay on delivery/i', 'cod|cash' ),
				'paypal' => array( '/paypal/i', 'paypal' ),
				'upi'    => array( '/\bupi\b|gpay|google pay|phonepe|paytm/i', 'upi|razorpay|phonepe|paytm' ),
				'crypto' => array( '/crypto|bitcoin|usdt/i', 'crypto|bitcoin|btc|coinbase|usdt' ),
				'bank'   => array( '/bank transfer|wire transfer|net ?banking/i', 'bank|bacs|wire|transfer' ),
				'card'   => array( '/debit card|credit card|visa|master ?card|amex|\bcard\b/i', 'card|stripe|razorpay|square|authorize|checkout' ),
			);
			$asked = '';
			foreach ( $want as $key => $w ) {
				if ( preg_match( $w[0], $m ) ) {
					$asked = $key;
					$has   = false;
					foreach ( $gws as $id => $title ) {
						if ( preg_match( '/' . $w[1] . '/i', $id . ' ' . $title ) ) {
							$has = $title;
							break;
						}
					}
					$lines[] = $has ? sprintf( __( 'Yes, %s is available at checkout.', 'tcc-assistant' ), $has ) : ( $gws ? __( 'I do not see that among our payment options.', 'tcc-assistant' ) : __( 'I cannot confirm that method from here; the payment options are shown at checkout.', 'tcc-assistant' ) );
					break;
				}
			}
			if ( $gws && ( '' === $asked || false !== strpos( end( $lines ), 'not see' ) ) ) {
				$lines[] = sprintf( __( 'You can pay by: %s.', 'tcc-assistant' ), implode( ', ', $gws ) );
			}
			if ( ! $gws && ! $lines ) {
				$lines[] = __( 'The payment options are shown at checkout once you have an item in your cart.', 'tcc-assistant' );
			}
		} elseif ( 'pay_safe' === $kind ) {
			$lines[] = __( 'It is right to ask. Here is what I can tell you for certain:', 'tcc-assistant' );
			if ( is_ssl() ) {
				$lines[] = '- ' . __( 'This site is served over a secure (HTTPS) connection.', 'tcc-assistant' );
			}
			$lines[] = '- ' . __( 'You pay on the checkout page. I never see or store card details, so please do not type them in this chat.', 'tcc-assistant' );
			if ( $gws ) {
				$lines[] = '- ' . sprintf( __( 'Payment methods: %s.', 'tcc-assistant' ), implode( ', ', $gws ) );
			}
		} elseif ( 'pay_fail' === $kind ) {
			$lines[] = __( 'I am sorry about that. Your cart is saved, so nothing is lost. These usually fix it:', 'tcc-assistant' );
			$lines[] = '- ' . __( 'Try once more, or use a different card or payment method.', 'tcc-assistant' );
			if ( $gws ) {
				$lines[] = '- ' . sprintf( __( 'Other ways to pay here: %s.', 'tcc-assistant' ), implode( ', ', $gws ) );
			}
			$lines[] = '- ' . __( 'Banks often block a first online payment to an overseas shop; a quick call to your bank to approve it can help.', 'tcc-assistant' );
			$lines[] = __( 'If it still fails, a pharmacist can help you complete the order.', 'tcc-assistant' );
			if ( self::cart() ) {
				$c = TCCA_Engine::cart_reply();
				$cards = $c['cards'];
			}
		} elseif ( 'ship_time' === $kind ) {
			$country = TCCA_Engine::shipping_country( $m );
			if ( $country ) {
				$lines[] = $country['reply'];
			}
			$d = self::delivery_line();
			if ( $d ) {
				$lines[] = $d . ' ' . __( 'The exact options and prices for your address are shown at checkout.', 'tcc-assistant' );
			} elseif ( ! $country ) {
				$lines[] = __( 'Delivery options and times for your address are shown at checkout once you enter it.', 'tcc-assistant' );
			}
			if ( preg_match( '/\b(cost|charges?|fee|free|price)\b/i', $m ) ) {
				$free = (float) TCCA_Core::get( 'free_ship_over' );
				$lines[] = $free > 0 ? sprintf( __( 'Shipping is free on orders over %s.', 'tcc-assistant' ), TCCA_Core::money( $free ) ) : __( 'The shipping price for your address is shown at checkout before you pay.', 'tcc-assistant' );
			}
			if ( self::any_flag( 'cold_chain', $ctx ) ) {
				$lines[] = __( 'This item ships cold-chain, so make sure someone can receive the parcel on the day.', 'tcc-assistant' );
			}
		} else { // ship_safe.
			$lines[] = __( 'Fair question. Here is what I can tell you:', 'tcc-assistant' );
			if ( self::any_flag( 'cold_chain', $ctx ) ) {
				$note = function_exists( 'mm_opt' ) ? trim( (string) mm_opt( 'pharmacy_note' ) ) : '';
				$lines[] = '- ' . ( '' !== $note ? $note : __( 'This item ships cold-chain.', 'tcc-assistant' ) );
			}
			if ( function_exists( 'mm_track_model' ) ) {
				$lines[] = '- ' . __( 'When your order ships, the tracking number appears on your order and I can show it here any time.', 'tcc-assistant' );
			}
			$hits = TCCA_Index::enabled() ? TCCA_Index::search( $m, 6 ) : array();
			if ( TCCA_Index::specific( $hits ) ) {
				$ans = TCCA_Index::answer( $hits );
				if ( $ans ) {
					$lines[] = $ans['reply'];
					$cards   = $ans['cards'];
				}
			}
			if ( preg_match( '/\b(customs?|import|duty|duties|seiz\w*|confiscat\w*)\b/i', $m ) ) {
				$lines[] = __( 'Customs and import rules depend on your country and I cannot promise how a parcel will be treated. A pharmacist can tell you what to expect and what paperwork helps.', 'tcc-assistant' );
			} else {
				$lines[] = __( 'If a parcel is damaged or does not arrive, tell us and a pharmacist will sort it out with you.', 'tcc-assistant' );
			}
		}
		foreach ( 'ship_time' === $kind || 'ship_safe' === $kind ? $dfacts : $pfacts as $f ) {
			$lines[] = '- ' . $f;
		}
		return TCCA_Engine::out( 'worry_' . $kind, implode( "\n", $lines ), array( 'cards' => $cards, 'actions' => $actions, 'chips' => self::next_chips( $ctx ), 'fb' => false ) );
	}

	/* =============================================================== closing */

	/**
	 * What is left before paying, in the order a customer meets it.
	 *
	 * @return array{lines:string[],actions:array}
	 */
	public static function pre_checkout() {
		$lines   = array();
		$actions = array();
		$cart    = self::cart();
		if ( ! $cart ) {
			return array( 'lines' => $lines, 'actions' => $actions );
		}
		$rx   = false;
		$cold = false;
		foreach ( $cart->get_cart() as $item ) {
			if ( function_exists( 'mm_product_flag' ) ) {
				$rx   = $rx || mm_product_flag( (int) $item['product_id'], 'rx' );
				$cold = $cold || mm_product_flag( (int) $item['product_id'], 'cold_chain' );
			}
		}
		if ( $rx ) {
			$lines[] = __( 'Prescription: your cart has Rx items, so upload a valid prescription and a pharmacist checks it before packing.', 'tcc-assistant' );
			if ( $u = TCCA_Core::page_url( 'upload-prescription' ) ) {
				$actions[] = array( 'type' => 'link', 'label' => __( 'Upload prescription', 'tcc-assistant' ), 'url' => $u );
			}
		}
		if ( $cold ) {
			$lines[] = __( 'Delivery: one item ships cold-chain, so choose an address where someone can receive it.', 'tcc-assistant' );
		}
		$free = (float) TCCA_Core::get( 'free_ship_over' );
		if ( $free > 0 && ( $free - (float) $cart->get_subtotal() ) > 0.5 ) {
			$lines[] = sprintf( __( 'Shipping: add %s more for free shipping.', 'tcc-assistant' ), TCCA_Core::money( $free - (float) $cart->get_subtotal() ) );
		}
		$g = self::gateways();
		if ( $g ) {
			$lines[] = sprintf( __( 'Payment: %s.', 'tcc-assistant' ), implode( ', ', $g ) );
		}
		if ( ! is_user_logged_in() && function_exists( 'mm_opt' ) && '1' === (string) mm_opt( 'require_signin' ) ) {
			$lines[] = __( 'Sign in: you will get an email code at checkout (no password).', 'tcc-assistant' );
		}
		return array( 'lines' => $lines, 'actions' => $actions );
	}

	private static function close_sale( $kind, $ctx ) {
		if ( self::cart() ) {
			return TCCA_Engine::cart_reply( __( 'Great, you are nearly there. Here is your order and what is left to do before you pay:', 'tcc-assistant' ), true );
		}
		$p = self::subject( $ctx );
		if ( $p && 'strong' === $kind ) {
			$c = TCCA_Engine::product_card( $p );
			if ( $c['canAdd'] ) {
				return TCCA_Engine::out( 'add', sprintf( __( 'Adding %s to your cart…', 'tcc-assistant' ), $c['name'] ), array( 'autoadd' => array( 'id' => (int) $c['id'], 'qty' => 1, 'checkout' => false ) ) );
			}
			return self::rescue_reply( $p, '', $ctx );
		}
		$steps = array(
			__( 'Ordering takes a minute:', 'tcc-assistant' ),
			'- ' . __( 'Tell me the medicine and I will add it to your cart (or use Add to cart on any product).', 'tcc-assistant' ),
		);
		if ( $u = TCCA_Core::page_url( 'upload-prescription' ) ) {
			$steps[] = '- ' . __( 'If it is marked Rx, upload your prescription.', 'tcc-assistant' );
		}
		$steps[] = '- ' . __( 'Open checkout, enter your address and choose how to pay.', 'tcc-assistant' );
		return TCCA_Engine::out( 'close', implode( "\n", $steps ), array( 'chips' => TCCA_Engine::default_chips_public() ) );
	}

	/* ============================================================ out of stock */

	/**
	 * A product cannot be bought right now: show what can, and offer to tell the customer when it is back.
	 *
	 * @param WC_Product $p    Product.
	 * @param string     $m    Message.
	 * @param array      $ctx  Context.
	 * @param string     $lead Optional first line.
	 * @return array
	 */
	public static function rescue_reply( $p, $m, $ctx, $lead = '' ) {
		$name  = wp_strip_all_tags( $p->get_name() );
		$lines = array( '' !== $lead ? $lead : sprintf( /* translators: %s: product */ __( '%s is out of stock right now. Here is what I can do:', 'tcc-assistant' ), $name ) );
		$cards = array();
		$seen  = array( $p->get_id() => true );
		$pr    = TCCA_Product::profile( $p );
		// Other strengths of the same product that are in stock.
		foreach ( $pr['strengths'] as $s ) {
			if ( empty( $s['active'] ) && ! empty( $s['stock'] ) && ! empty( $s['url'] ) && count( $cards ) < 2 ) {
				$lines[] = '- ' . sprintf( __( 'Another strength is in stock: %s.', 'tcc-assistant' ), $s['label'] );
			}
		}
		// Same active ingredient, in stock, nearest price first.
		if ( function_exists( 'mm_composition_terms' ) && taxonomy_exists( 'composition' ) ) {
			$terms = array();
			foreach ( mm_composition_terms( $p->get_id() ) as $t ) {
				$terms[] = $t->term_id;
			}
			if ( $terms ) {
				$mine = function_exists( 'mm_price_data' ) ? (float) mm_price_data( $p )['price'] : (float) $p->get_price();
				$ids  = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 20, 'post__not_in' => array( $p->get_id() ), 'no_found_rows' => true, 'tax_query' => array( array( 'taxonomy' => 'composition', 'field' => 'term_id', 'terms' => $terms ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
				$alts = array();
				foreach ( $ids as $id ) {
					$o = wc_get_product( $id );
					if ( $o && $o->is_visible() && $o->is_in_stock() && ! isset( $seen[ $id ] ) ) {
						$pp            = function_exists( 'mm_price_data' ) ? (float) mm_price_data( $o )['price'] : (float) $o->get_price();
						$alts[ $id ] = array( abs( $pp - $mine ), $o );
					}
				}
				uasort( $alts, static function ( $a, $b ) {
					return $a[0] <=> $b[0];
				} );
				foreach ( array_slice( $alts, 0, 3, true ) as $id => $a ) {
					$cards[] = TCCA_Engine::product_card( $a[1] );
				}
			}
		}
		// No composition tagged on the product: fall back on the brand-to-ingredient list.
		if ( ! $cards ) {
			foreach ( TCCA_Lexicon::find( $name ) as $f ) {
				foreach ( TCCA_Finder::generic_products( $f['generic'], '', 6 ) as $o ) {
					if ( $o->get_id() !== $p->get_id() && $o->is_in_stock() && count( $cards ) < 3 ) {
						$cards[] = TCCA_Engine::product_card( $o );
					}
				}
				break;
			}
		}
		if ( $cards ) {
			$lines[] = __( 'In stock now with the same active ingredient (please confirm any switch with your pharmacist or prescriber):', 'tcc-assistant' );
		}
		$extra = array( 'cards' => $cards, 'actions' => TCCA_Engine::human_actions( $m ) );
		if ( '1' === TCCA_Core::get( 'restock_on' ) ) {
			$lines[]        = __( 'I can also email you once, as soon as it is back.', 'tcc-assistant' );
			$extra['form']  = 'restock';
			$extra['fpid']  = $p->get_id();
			$extra['fname'] = $name;
		}
		TCCA_Events::log( 'miss', isset( $ctx['session'] ) ? $ctx['session'] : '', array( 'pid' => $p->get_id(), 'ref' => 'out of stock: ' . $name ) );
		return TCCA_Engine::out( 'rescue', implode( "\n", $lines ), $extra );
	}

	private static function restock_ask( $m, $ctx ) {
		$term = trim( preg_replace( '/\b(notify|let|tell|email|alert|inform|me|know|when|is|it|back|in|stock|available|again|restock\w*|waitlist|wait|list|please|will|be|the|about|for|a|an|if|comes|come)\b/i', ' ', $m ) );
		$p    = null;
		if ( strlen( $term ) >= 3 ) {
			$f = TCCA_Engine::find_products( $term, 1 );
			$p = $f ? $f[0] : null;
		}
		if ( ! $p && ! empty( $ctx['pid'] ) ) {
			$p = wc_get_product( (int) $ctx['pid'] );
		}
		if ( ! $p ) {
			return TCCA_Engine::out( 'restock', __( 'Which medicine would you like me to watch for? Tell me its name.', 'tcc-assistant' ), array( 'chips' => TCCA_Engine::default_chips_public() ) );
		}
		if ( $p->is_in_stock() ) {
			$c = TCCA_Engine::product_card( $p );
			return TCCA_Engine::out( 'restock', sprintf( __( 'Good news: %s is in stock right now.', 'tcc-assistant' ), $c['name'] ), array( 'cards' => array( $c ) ) );
		}
		return self::rescue_reply( $p, $m, $ctx );
	}

	/**
	 * Unmet demand: nothing matched. Say so, record it, and offer to source it.
	 */
	public static function not_found( $term, $m, $ctx ) {
		TCCA_Events::log( 'miss', isset( $ctx['session'] ) ? $ctx['session'] : '', array( 'ref' => $term ) );
		return TCCA_Engine::out(
			'product_none',
			sprintf( /* translators: %s: search words */ __( 'I could not find “%s” in the catalogue. Check the spelling, or leave your email and a pharmacist will try to source it for you.', 'tcc-assistant' ), $term ),
			array( 'actions' => TCCA_Engine::human_actions( $m ), 'form' => 'request', 'fterm' => $term, 'answered' => false )
		);
	}

	/* ============================================================== together */

	private static function together_reply( $m, $ctx ) {
		$p     = null;
		$cart  = self::cart();
		$clean = trim( preg_replace( '/\b(what|do|did|people|others|customers|buy|order|add|get|with|goes|go|pairs?|works?|well|often|frequently|usually|bought|ordered|together|else|should|can|might|i|we|need|anything|complete|my|cart|basket|order|the|a|an|it|this|that)\b/i', ' ', $m ) );
		if ( strlen( $clean ) >= 3 ) {
			$f = TCCA_Engine::find_products( $clean, 1 );
			$p = $f ? $f[0] : null;
		}
		if ( ! $p ) {
			$p = self::subject( $ctx );
		}
		$cards = TCCA_Together::cards_for( $p ? array( $p->get_id() ) : array(), 3 );
		if ( ! $cards ) {
			return TCCA_Engine::out( 'together', __( 'I do not have enough order history to say what goes with that yet. Tell me what you are treating and a pharmacist can suggest what else you may need.', 'tcc-assistant' ), array( 'actions' => TCCA_Engine::human_actions( $m ), 'chips' => $cart ? array( array( __( 'Help me check out', 'tcc-assistant' ), 'I am ready to order' ) ) : array() ) );
		}
		$name = $p ? wp_strip_all_tags( $p->get_name() ) : __( 'your cart', 'tcc-assistant' );
		return TCCA_Engine::out( 'together', sprintf( /* translators: %s: product */ __( 'Customers who ordered %s also ordered these. It is what people actually bought, not medical advice:', 'tcc-assistant' ), $name ), array( 'cards' => $cards, 'actions' => TCCA_Engine::human_actions( $m ) ) );
	}
}

/**
 * What customers actually order together, counted from your own order history (product ids only).
 */
class TCCA_Together {

	public static function enabled() {
		return '1' === TCCA_Core::get( 'together_on' );
	}

	/**
	 * product id => array( other id => number of orders containing both ), pairs seen in at least 2 orders.
	 *
	 * @return array
	 */
	public static function matrix() {
		$m = get_transient( 'tcca_together' );
		if ( is_array( $m ) ) {
			return $m;
		}
		$m = array();
		if ( function_exists( 'wc_get_orders' ) ) {
			global $wpdb;
			$ids = (array) wc_get_orders( array( 'limit' => 2000, 'return' => 'ids', 'status' => array( 'completed', 'processing' ), 'orderby' => 'date', 'order' => 'DESC' ) );
			$by  = array();
			foreach ( array_chunk( array_map( 'intval', $ids ), 400 ) as $chunk ) {
				$in   = implode( ',', $chunk );
				$rows = (array) $wpdb->get_results( "SELECT oi.order_id AS o, im.meta_value AS p FROM {$wpdb->prefix}woocommerce_order_items oi JOIN {$wpdb->prefix}woocommerce_order_itemmeta im ON im.order_item_id = oi.order_item_id AND im.meta_key = '_product_id' WHERE oi.order_item_type = 'line_item' AND oi.order_id IN ({$in})", ARRAY_A ); // phpcs:ignore WordPress.DB
				foreach ( $rows as $r ) {
					$by[ (int) $r['o'] ][ (int) $r['p'] ] = true;
				}
			}
			$pairs = array();
			foreach ( $by as $items ) {
				$items = array_keys( $items );
				if ( count( $items ) < 2 || count( $items ) > 12 ) {
					continue;
				}
				foreach ( $items as $a ) {
					foreach ( $items as $b ) {
						if ( $a !== $b ) {
							$pairs[ $a ][ $b ] = ( isset( $pairs[ $a ][ $b ] ) ? $pairs[ $a ][ $b ] : 0 ) + 1;
						}
					}
				}
			}
			foreach ( $pairs as $a => $others ) {
				$others = array_filter( $others, static function ( $n ) {
					return $n >= 2;
				} );
				arsort( $others );
				if ( $others ) {
					$m[ $a ] = array_slice( $others, 0, 12, true );
				}
			}
		}
		set_transient( 'tcca_together', $m, 12 * HOUR_IN_SECONDS );
		return $m;
	}

	/**
	 * Cards for what is ordered with the given products (or with the cart when none are given).
	 *
	 * @param int[] $pids Anchor products.
	 * @param int   $n    Max cards.
	 * @return array
	 */
	public static function cards_for( $pids, $n = 2 ) {
		if ( ! self::enabled() ) {
			return array();
		}
		$in_cart = array();
		$rx_ok   = false;
		if ( function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty() ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				$in_cart[ (int) $item['product_id'] ] = true;
				$rx_ok = $rx_ok || ( function_exists( 'mm_product_flag' ) && mm_product_flag( (int) $item['product_id'], 'rx' ) );
			}
			if ( ! $pids ) {
				$pids = array_keys( $in_cart );
			}
		}
		$m     = self::matrix();
		$score = array();
		foreach ( $pids as $pid ) {
			$rx_ok = $rx_ok || ( function_exists( 'mm_product_flag' ) && mm_product_flag( (int) $pid, 'rx' ) );
			foreach ( isset( $m[ $pid ] ) ? $m[ $pid ] : array() as $o => $c ) {
				$score[ $o ] = ( isset( $score[ $o ] ) ? $score[ $o ] : 0 ) + $c;
			}
		}
		arsort( $score );
		$cards = array();
		foreach ( $score as $id => $count ) {
			if ( isset( $in_cart[ $id ] ) || in_array( $id, $pids, true ) ) {
				continue;
			}
			$o = wc_get_product( $id );
			if ( ! $o || ! $o->is_visible() || ! $o->is_in_stock() ) {
				continue;
			}
			// Never push a prescription item at someone who has none in mind.
			if ( ! $rx_ok && function_exists( 'mm_product_flag' ) && mm_product_flag( $o, 'rx' ) ) {
				continue;
			}
			$cards[] = TCCA_Engine::product_card( $o, sprintf( /* translators: %d: number of orders */ __( 'Ordered together in %d orders.', 'tcc-assistant' ), $count ) );
			if ( count( $cards ) >= $n ) {
				break;
			}
		}
		return $cards;
	}
}
