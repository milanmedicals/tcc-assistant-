<?php
/**
 * Conversation engine. Deterministic first (safety, orders, products, knowledge base); the optional
 * language model is only a fallback for general questions.
 *
 * @package TCCAssistant
 */

defined( 'ABSPATH' ) || exit;

class TCCA_Engine {

	/**
	 * Build a reply payload.
	 *
	 * @param string $intent Intent label (logged).
	 * @param string $reply  Text.
	 * @param array  $extra  cards, actions, chips, form, ai, answered.
	 * @return array
	 */
	public static function out( $intent, $reply, $extra = array() ) {
		return array_merge(
			array(
				'intent'   => $intent,
				'reply'    => $reply,
				'cards'    => array(),
				'actions'  => array(),
				'chips'    => array(),
				'form'     => '',
				'ai'       => false,
				'fb'       => false,
				'autoadd'  => null,
				'trace'    => null,
				'answered' => true,
			),
			$extra
		);
	}

	/**
	 * Ways to reach a human.
	 *
	 * @param string $prefill WhatsApp pre-fill text.
	 * @return array
	 */
	public static function human_actions( $prefill = '' ) {
		$site = TCCA_Core::site();
		$a    = array( array( 'type' => 'pharmacist', 'label' => __( 'Talk to a pharmacist', 'tcc-assistant' ) ) );
		if ( $site['whatsapp'] ) {
			$url = $site['whatsapp'] . ( $prefill ? '?text=' . rawurlencode( $prefill ) : '' );
			$a[] = array( 'type' => 'link', 'label' => 'WhatsApp', 'url' => $url );
		}
		if ( $site['tel'] ) {
			$a[] = array( 'type' => 'link', 'label' => sprintf( __( 'Call %s', 'tcc-assistant' ), $site['phone'] ), 'url' => $site['tel'] );
		}
		return $a;
	}

	/**
	 * Main entry point.
	 *
	 * @param string $message Visitor text.
	 * @param array  $history Recent lines.
	 * @return array
	 */
	public static function reply( $message, $history = array(), $ctx = array() ) {
		$orig  = trim( preg_replace( '/\s+/', ' ', $message ) );
		$al    = TCCA_Lexicon::find( $orig );
		$r     = self::route( $orig, $history, (array) $ctx, $al );
		// A brand read as its ingredient: say so once on answers drawn from the ingredient's listing.
		if ( $al && in_array( $r['intent'], array( 'product_info', 'suggest', 'product_ai', 'compare', 'product', 'deal', 'savings' ), true ) ) {
			$r['reply'] = TCCA_Finder::alias_note( $al ) . "\n" . $r['reply'];
		}
		return $r;
	}

	private static function route( $orig, $history, $ctx, $al ) {
		$m = TCCA_Lexicon::expand( $orig, $al ); // "accutane" becomes "accutane isotretinoin" for every later step.
		$m = self::resolve( $m, (array) $ctx );
		TCCA_Account::set_context( (array) $ctx );
		$n = strtolower( $m );

		// 1. Emergencies: never anything but a pointer to emergency care.
		if ( preg_match( '/\b(overdos\w*|can\'?t breathe|cannot breathe|difficulty breathing|chest pain|suicid\w*|kill myself|end my life|anaphyla\w*|severe allergic|unconscious|poison(?:ed|ing)?|seizure|bleeding (?:heavily|a lot|badly)|medical emergency)\b/i', $m ) ) {
			return self::out(
				'emergency',
				__( 'This sounds like it could be urgent. Please call your local emergency number or go to the nearest emergency department right now. I am not able to help with medical emergencies, and an online chat cannot replace urgent care. If you are thinking about harming yourself, please contact local emergency services or a crisis line in your country.', 'tcc-assistant' ),
				array( 'answered' => true )
			);
		}

		// 2. Greetings and small talk.
		if ( preg_match( '/^(hi+|hello+|hey+|hola|namaste|good (morning|afternoon|evening)|yo|hi there|hello there)\W*$/i', $m ) ) {
			return self::out( 'greeting', TCCA_Core::get( 'welcome' ), array( 'chips' => self::default_chips() ) );
		}
		if ( preg_match( '/^(thanks|thank you|thx|ty|ok|okay|great|cool|perfect|got it|nice)\W*$/i', $m ) ) {
			return self::out( 'thanks', __( 'You are welcome! Anything else I can help with?', 'tcc-assistant' ), array( 'chips' => self::default_chips() ) );
		}
		if ( preg_match( '/^(bye|goodbye|see you|that\'?s all|no thanks)\W*$/i', $m ) ) {
			return self::out( 'bye', __( 'Take care! I am here whenever you need me.', 'tcc-assistant' ) );
		}

		if ( preg_match( '/\b(who|what) are you\b|\bare you (?:a |an )?(?:bot|robot|human|real|ai|person|pharmacist)\b/i', $m ) ) {
			return self::out( 'identity', __( 'I am the shop\'s automated assistant. I am not a person and not a pharmacist. I answer from this website\'s own pages, products and articles, and I pass anything medical to a pharmacist.', 'tcc-assistant' ), array( 'chips' => self::default_chips(), 'actions' => self::human_actions() ) );
		}
		if ( preg_match( '/\bwhat can you (?:do|help)|how can you help|what do you do\b/i', $m ) ) {
			return self::out( 'capabilities', __( 'I can find medicines by name, brand or what they are used for, add them to your cart and take you to checkout, track an order, read a product\'s page (uses, safety information, FAQs, strengths), suggest in-stock alternatives, and answer delivery, payment and returns questions.', 'tcc-assistant' ), array( 'chips' => self::default_chips() ) );
		}

		// 3. Wants a person.
		if ( preg_match( '/\b(human|real person|agent|pharmacist|speak to (?:someone|a person)|talk to (?:someone|a person)|call me|customer (?:care|service)|complaint)\b/i', $m ) ) {
			return self::out( 'human', __( 'Of course. A pharmacist can help you directly:', 'tcc-assistant' ), array( 'actions' => self::human_actions( $m ), 'form' => 'handoff' ) );
		}

		// 3a. The salesperson: price objections, discount requests, doubts about authenticity, "let me think".
		// Runs before account lookup so "I find it expensive" is never mistaken for a customer search.
		$deal = TCCA_Deal::handle( $m, $history, (array) $ctx );
		if ( $deal ) {
			return $deal;
		}

		// 3a2. Delivery and payment worries, closing the sale, back-in-stock, "what goes with it".
		$con = TCCA_Concierge::handle( $m, $history, (array) $ctx, $orig );
		if ( $con ) {
			return $con;
		}

		// 3a2b. "My usuals", "do I need a refill?", "what should I order?": answered from the customer's own history (TCC Insights, if active).
		$mine = TCCA_Insights::for_you( $m, (array) $ctx );
		if ( $mine ) {
			return $mine;
		}

		// 3a3. A brand name the shop does not list: show the same active ingredient (typo tolerant).
		if ( $al ) {
			$br = TCCA_Finder::brand_flow( $orig, $al, (array) $ctx );
			if ( $br ) {
				return $br;
			}
		}

		// 3b. "Add 2 mounjaro to my cart", "add it", "buy now": do it, don't just describe it.
		$add = self::parse_add( $m, (array) $ctx, $orig );
		if ( $add ) {
			return $add;
		}

		// 3c. "Compare X and Y".
		$cmp = TCCA_Sales::compare( $m );
		if ( $cmp ) {
			return $cmp;
		}

		// 4. Cart and checkout.
		if ( self::is_cart_intent( $m ) ) {
			return self::cart_reply();
		}

		// 4b. "Do you have anything for pimples?": products whose own pages say what they are used for.
		$cn = TCCA_Finder::concern_flow( $m, (array) $ctx );
		if ( $cn ) {
			return $cn;
		}

		// 5. Personal medical questions: out of scope by design.
		if ( self::is_personal( $m ) ) {
			return self::clinical_reply( $m );
		}

		// 5b. Find a customer: by email, club number or order number (verified by emailed code; staff directly).
		$acct = TCCA_Account::handle( $m );
		if ( $acct ) {
			return $acct;
		}

		// 6. Orders and tracking (and "order again").
		$again = (bool) preg_match( '/\b(re-?order|order (?:it )?again|buy (?:it )?again|repeat (?:my )?(?:last )?order|same order)\b/i', $m );
		if ( $again || self::is_order_intent( $m ) ) {
			return self::order_flow( $m, $again );
		}

		// 6b. "Do you ship to ...?"
		$ship = self::shipping_country( $m );
		if ( $ship ) {
			return $ship;
		}

		// 6b2. "How can I save?", "cheapest", "bulk": from the catalogue's own prices and tiers.
		if ( preg_match( '/\b(save|saving|savings|cheapest|cheaper|lowest price|best price|bulk|wholesale|buy more|discount|deal|deals|offer|offers)\b/i', $m ) ) {
			return TCCA_Sales::savings( $m );
		}

		// 6c. Questions about a product: read its published description, Uses, Safety, FAQs, composition, packs.
		$info = TCCA_Product::reply( $m, $history );
		if ( $info ) {
			return $info;
		}

		// 6d. "Medicines for diabetes": browse a category.
		$cat = self::category_reply( $m );
		if ( $cat ) {
			return $cat;
		}

		// The site's own knowledge index, searched once for the rest of the flow.
		$hits = TCCA_Index::enabled() ? TCCA_Index::search( $m, 8 ) : array();

		// 6e. Other medical-sounding questions with no product named: answer only from the site's articles/pages
		// (or a product the visitor actually named), otherwise a pharmacist.
		if ( self::is_clinical( $m ) ) {
			$qt   = TCCA_KB::tokens( $m );
			$safe = array_values( array_filter( $hits, static function ( $h ) use ( $qt ) {
				return in_array( $h['type'], array( 'post', 'page', 'cat' ), true ) || array_intersect( explode( ' ', $h['title_terms'] ), $qt );
			} ) );
			$ans = TCCA_Index::answer( $safe, true );
			return $ans ? $ans : self::clinical_reply( $m );
		}

		// 7. Knowledge base, strong match.
		$kb = TCCA_KB::search( $m, 3 );
		if ( $kb && $kb[0]['score'] >= 2.0 && TCCA_Index::demoted( $m, 'kb', 0, TCCA_KB::entry_id( $kb[0]['entry'] ) ) ) {
			array_shift( $kb ); // Customers said this built-in answer did not fit this kind of question.
		}
		// A built-in answer must cover most of what was asked (your own entries are trusted more).
		if ( $kb && $kb[0]['score'] >= 2.0 && ! $kb[0]['entry']['own'] && $kb[0]['cov'] < 0.5 ) {
			$kb[0]['score'] = 1.5;
		}
		if ( $kb && $kb[0]['score'] >= 2.0 ) {
			// A built-in answer is generic: prefer an article that is specifically about what was asked (e.g. a country).
			if ( ! $kb[0]['entry']['own'] && TCCA_Index::specific( $hits ) ) {
				return TCCA_Index::answer( $hits );
			}
			return self::kb_reply( $kb[0]['entry'] );
		}

		$none = null;
		// 8. Products.
		if ( class_exists( 'WooCommerce' ) && ! self::is_question( $n ) ) {
			$prod = self::product_reply( $m, (array) $ctx, $orig );
			if ( $prod && 'product_none' !== $prod['intent'] ) {
				return $prod;
			}
			if ( $prod ) {
				$none = $prod; // "Not in the catalogue": only if nothing else answers.
			}
		} elseif ( class_exists( 'WooCommerce' ) && self::has_commerce_word( $n ) ) {
			$prod = self::product_reply( $m, (array) $ctx, $orig );
			if ( $prod && 'product_none' !== $prod['intent'] ) {
				return $prod;
			}
			if ( $prod ) {
				$none = $prod; // "Not in the catalogue": only if nothing else answers.
			}
		}

		// 8b. The site's own knowledge index: products, categories, articles, pages, FAQs.
		$ans = TCCA_Index::answer( $hits );
		if ( $ans ) {
			return $ans;
		}

		// 9. Optional language model, given the best passages as its only source.
		if ( TCCA_LLM::enabled() ) {
			$ctx = array();
			foreach ( $kb as $hit ) {
				$ctx[] = $hit['entry'];
			}
			$ctx = array_merge( $ctx, TCCA_Index::context( $hits, 3 ) );
			$ai  = TCCA_LLM::ask( $m, $history, $ctx );
			if ( ! is_wp_error( $ai ) ) {
				return self::out( 'ai', $ai, array( 'ai' => true, 'actions' => self::human_actions( $m ) ) );
			}
		}

		// 9b. Medium confidence: the closest passage, honestly labelled (unless a built-in answer matches the title).
		$mid = TCCA_Index::closest( $hits );
		if ( $mid && ! ( $kb && $kb[0]['score'] >= 1.0 && $kb[0]['cov'] >= 0.5 ) ) {
			return $mid;
		}

		// 10. Weak knowledge-base match is better than nothing.
		if ( $kb && $kb[0]['score'] >= 1.0 ) {
			return self::kb_reply( $kb[0]['entry'] );
		}
		if ( $none ) {
			return $none;
		}

		// 11. Honest fallback: ask what they meant, offer the nearest topics, and log it so the owner (and the learning loop) can fix it.
		$chips = TCCA_Index::suggestions( $hits, $m );
		return self::out(
			'fallback',
			$chips ? __( 'I am not sure I understood. Did you mean one of these? You can also rephrase, or I can connect you with a pharmacist.', 'tcc-assistant' ) : __( 'I am not sure I understood that. You can rephrase it, ask about a medicine, an order or delivery, or I can connect you with a pharmacist.', 'tcc-assistant' ),
			array( 'actions' => self::human_actions( $m ), 'chips' => $chips ? $chips : self::default_chips(), 'form' => 'handoff', 'answered' => false )
		);
	}

	/**
	 * Quick-reply chips from settings.
	 *
	 * @return array
	 */
	public static function default_chips_public() {
		return self::default_chips();
	}

	private static function default_chips() {
		$out = array();
		foreach ( array_slice( preg_split( '/\r\n|\r|\n/', TCCA_Core::get( 'quick' ) ), 0, 4 ) as $line ) {
			$p = array_map( 'trim', explode( '|', $line, 2 ) );
			if ( '' !== $p[0] ) {
				$out[] = array( $p[0], isset( $p[1] ) && '' !== $p[1] ? $p[1] : $p[0] );
			}
		}
		return $out;
	}

	/**
	 * Knowledge-base answer.
	 *
	 * @param array $entry Entry.
	 * @return array
	 */
	private static function kb_reply( $entry ) {
		$trace = array( 't' => 'kb', 'o' => 0, 'p' => TCCA_KB::entry_id( $entry ) );
		$actions = array();
		if ( ! empty( $entry['link'][1] ) ) {
			$actions[] = array( 'type' => 'link', 'label' => $entry['link'][0], 'url' => $entry['link'][1] );
		}
		return self::out( 'faq', $entry['a'], array( 'actions' => $actions, 'fb' => true, 'trace' => $trace ) );
	}

	/* --------------------------------------------------------- conversation */

	/**
	 * Follow-ups: "what is it used for", "the second one", "price of that" refer to what was just shown.
	 */
	private static function resolve( $m, $ctx ) {
		$pid  = isset( $ctx['pid'] ) ? (int) $ctx['pid'] : 0;
		$pids = isset( $ctx['pids'] ) ? array_map( 'intval', (array) $ctx['pids'] ) : array();
		if ( ! $pid || ! class_exists( 'WooCommerce' ) || str_word_count( $m ) > 10 ) {
			return $m;
		}
		// The product on the page is only assumed when the message is plainly about a product.
		if ( ! empty( $ctx['pid_src'] ) && 'page' === $ctx['pid_src'] && ! preg_match( '/\b(this|that) (?:medicine|medication|product|item|one|pen|drug|tablet|injection)\b|\b(price|cost|add|buy|genuine|authentic|uses?|side effects?|alternatives?|cheaper|similar|stock|available|strengths?|packs?|reviews?|compare|safe)\b/i', $m ) ) {
			return $m;
		}
		$target = 0;
		if ( preg_match( '/\b(?:the )?(first|1st|second|2nd|third|3rd|last)(?: one| option| product| medicine| pen)\b/i', $m, $x ) && $pids ) {
			$w      = strtolower( $x[1] );
			$map    = array( 'first' => 0, '1st' => 0, 'second' => 1, '2nd' => 1, 'third' => 2, '3rd' => 2 );
			$target = 'last' === $w ? (int) end( $pids ) : ( isset( $map[ $w ] ) && isset( $pids[ $map[ $w ] ] ) ? $pids[ $map[ $w ] ] : 0 );
		} elseif ( preg_match( '/\b(it|this|that|this one|that one|the same|them)\b/i', $m ) ) {
			$target = $pid;
		}
		$p = $target ? wc_get_product( $target ) : false;
		return $p ? $m . ' ' . wp_strip_all_tags( $p->get_name() ) : $m;
	}

	/**
	 * An explicit request to add something to the cart.
	 *
	 * @return array|null Payload with 'autoadd', or product cards when it is ambiguous.
	 */
	private static function parse_add( $m, $ctx, $orig = '' ) {
		if ( ! class_exists( 'WooCommerce' ) || ! preg_match( '/^\s*(?:please\s+|yes[,.! ]+|ok(?:ay)?[,.! ]+)?(?:add|put|buy|get|order)\b|\b(?:add|put)\b[^?]*\b(?:cart|basket)\b/i', $m ) ) {
			return null;
		}
		if ( preg_match( '/\b(track|where|status|my orders?|re-?order|again|last order|invoice)\b/i', $m ) ) {
			return null;
		}
		$checkout = (bool) preg_match( '/\b(buy now|check ?out|and pay|pay now)\b/i', $m );
		$qty      = 1;
		if ( preg_match( '/\b(?:x|×)\s*(\d{1,2})\b|\b(\d{1,2})\s*(?:x|×)(?=\s|$)|\b(\d{1,2})\s+(?:packs?|boxes?|pens?|units?|bottles?|strips?|of)\b|\b(?:add|buy|get|order)\s+(\d{1,2})\b(?!\s*(?:mg|mcg|ml|g|iu|%))/i', $m, $x ) ) {
			$qty = (int) ( $x[1] ?: ( $x[2] ?: ( $x[3] ?: $x[4] ) ) );
		}
		$qty  = max( 1, min( 20, $qty ) );
		$term = trim( preg_replace( '/\b(please|yes|ok|okay|add|put|buy|get|order|to|my|the|cart|basket|now|check ?out|and|pay|it|this|that|one|of|x|packs?|boxes?|units?|bottles?|strips?)\b|(?<![\w.])\d{1,2}(?![\w.])\s*(?:x|×)?/i', ' ', $m ) );
		$term = trim( preg_replace( '/\s+/', ' ', $term ) );
		$prods = array();
		if ( strlen( $term ) >= 3 ) {
			$prods = self::find_products( $term, 3 );
		}
		if ( ! $prods && ! empty( $ctx['pid'] ) && strlen( $term ) < 3 ) {
			$p = wc_get_product( (int) $ctx['pid'] );
			$prods = $p ? array( $p ) : array();
		}
		if ( ! $prods ) {
			return null;
		}
		// The customer may have named a brand ("mounjaro 5mg") and the ingredient was added for the search: judge "did they name it" on their own words.
		$term_o = '' === $orig ? $term : trim( preg_replace( '/\s+/', ' ', preg_replace( '/\b(please|yes|ok|okay|add|put|buy|get|order|to|my|the|cart|basket|now|check ?out|and|pay|it|this|that|one|of|x|packs?|boxes?|units?|bottles?|strips?)\b|(?<![\w.])\d{1,2}(?![\w.])\s*(?:x|×)?/i', ' ', $orig ) ) );
		if ( count( $prods ) > 1 && ! self::exact_name( $term, $prods[0] ) && ! self::exact_name( $term_o, $prods[0] ) ) {
			$cards = array();
			foreach ( $prods as $p ) {
				$cards[] = self::product_card( $p );
			}
			return self::out( 'add', __( 'Which one would you like to add?', 'tcc-assistant' ), array( 'cards' => $cards ) );
		}
		$p    = $prods[0];
		$card = self::product_card( $p );
		if ( ! $card['canAdd'] ) {
			if ( ! $p->is_in_stock() ) {
				return TCCA_Concierge::rescue_reply( $p, $m, $ctx );
			}
			return self::out( 'add', sprintf( __( 'I cannot add %s directly (it needs an option chosen). Here is its page:', 'tcc-assistant' ), $card['name'] ), array( 'cards' => array( $card ) ) );
		}
		$text = sprintf( $qty > 1 ? __( 'Adding %1$s × %2$d to your cart…', 'tcc-assistant' ) : __( 'Adding %1$s to your cart…', 'tcc-assistant' ), $card['name'], $qty );
		if ( $card['rx'] ) {
			$text .= ' ' . __( 'This one needs a prescription; you can upload it any time on the upload page.', 'tcc-assistant' );
		}
		return self::out( 'add', $text, array( 'autoadd' => array( 'id' => (int) $card['id'], 'qty' => $qty, 'checkout' => $checkout ) ) );
	}

	/**
	 * Did the visitor name this product closely enough to skip "which one?".
	 */
	private static function exact_name( $term, $p ) {
		$name  = strtolower( $p->get_name() );
		$words = array_filter( preg_split( '/[^a-z0-9]+/', strtolower( $term ) ), static function ( $w ) {
			return strlen( $w ) >= 3;
		} );
		if ( ! $words ) {
			return false;
		}
		foreach ( $words as $w ) {
			if ( false === strpos( $name, $w ) ) {
				return false;
			}
		}
		return count( $words ) >= 2 || strlen( reset( $words ) ) >= 6;
	}

	/* ----------------------------------------------------------------- cart */

	/**
	 * Wants to see the cart or check out.
	 *
	 * @param string $m Message.
	 * @return bool
	 */
	private static function is_cart_intent( $m ) {
		return (bool) preg_match( '/\b(my (?:cart|basket)|(?:view|show|see|open|check) (?:my |the )?(?:cart|basket)|what(?:\'s| is) in (?:my |the )?(?:cart|basket)|check ?out|place (?:my |the )?order|proceed to (?:pay|checkout)|pay now|buy now|complete (?:my )?(?:order|purchase))\b/i', $m );
	}

	/**
	 * The visitor's live cart. Reads the same WooCommerce session the theme uses.
	 *
	 * @param string $lead Optional first line.
	 * @return array
	 */
	public static function cart_reply( $lead = '', $closing = false ) {
		if ( ! function_exists( 'WC' ) ) {
			return self::out( 'cart', __( 'The cart is not available right now.', 'tcc-assistant' ) );
		}
		if ( ! WC()->cart && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		$cart = WC()->cart;
		if ( ! $cart || $cart->is_empty() ) {
			return self::out( 'cart', __( 'Your cart is empty. Tell me what you are looking for and I will find it.', 'tcc-assistant' ), array( 'chips' => self::default_chips() ) );
		}
		$lines = array();
		$rx    = false;
		foreach ( $cart->get_cart() as $item ) {
			$prod    = $item['data'];
			$lines[] = array(
				'id'    => (int) $item['product_id'],
				'vid'   => (int) $item['variation_id'],
				'bulk'  => isset( $item['mm_bulk_qty'] ) ? (int) $item['mm_bulk_qty'] : 0,
				'name'  => wp_strip_all_tags( $prod->get_name() ),
				'qty'   => (int) $item['quantity'],
				'total' => TCCA_Core::money( (float) $item['line_total'] ),
			);
			if ( function_exists( 'mm_product_flag' ) && mm_product_flag( (int) $item['product_id'], 'rx' ) ) {
				$rx = true;
			}
		}
		$nudges      = TCCA_Sales::cart_nudges();
		$need_signin = ! is_user_logged_in() && function_exists( 'mm_opt' ) && '1' === (string) mm_opt( 'require_signin' );
		$card        = array(
			'type'     => 'cart',
			'lines'    => $lines,
			'count'    => (int) $cart->get_cart_contents_count(),
			'total'    => trim( html_entity_decode( wp_strip_all_tags( $cart->get_cart_total() ), ENT_QUOTES, 'UTF-8' ) ),
			'checkout' => wc_get_checkout_url(),
			'cartUrl'  => wc_get_cart_url(),
			'signin'   => $need_signin,
		);
		$text        = $lead ? $lead : __( 'Here is your cart:', 'tcc-assistant' );
		$actions     = array();
		if ( $rx ) {
			$text .= ' ' . __( 'It includes prescription (Rx) items.', 'tcc-assistant' );
			if ( $u = TCCA_Core::page_url( 'upload-prescription' ) ) {
				$actions[] = array( 'type' => 'link', 'label' => __( 'Upload prescription', 'tcc-assistant' ), 'url' => $u );
			}
		}
		if ( $need_signin ) {
			$text .= ' ' . __( 'You will sign in with an email code at checkout.', 'tcc-assistant' );
		}
		if ( $nudges ) {
			$text .= "\n" . __( 'Ways to save on this cart:', 'tcc-assistant' ) . "\n- " . implode( "\n- ", $nudges );
		}
		$cards = array( $card );
		if ( $closing ) {
			$pc = TCCA_Concierge::pre_checkout();
			if ( $pc['lines'] ) {
				$text = $lead . "\n- " . implode( "\n- ", $pc['lines'] ) . ( $nudges ? "\n" . __( 'Ways to save on this cart:', 'tcc-assistant' ) . "\n- " . implode( "\n- ", $nudges ) : '' );
			}
			$actions = array_merge( $actions, $pc['actions'] );
		}
		if ( $lead ) {
			$cross = TCCA_Sales::cross_sell_cards();
			if ( ! $cross ) {
				$cross = TCCA_Together::cards_for( array(), 2 ); // What customers actually ordered with this cart.
			}
			if ( $cross ) {
				$text .= "\n" . __( 'Often added together:', 'tcc-assistant' );
				$cards = array_merge( $cards, $cross );
			}
		}
		$chips = TCCA_Coupons::enabled() ? array( array( __( 'Get a discount code', 'tcc-assistant' ), 'Give me a discount code' ) ) : array();
		return self::out( 'cart', $text, array( 'cards' => $cards, 'actions' => $actions, 'chips' => $chips ) );
	}

	/* ------------------------------------------------------------- categories */

	/**
	 * "Medicines for diabetes": list products from the matching category (browsing, not advice).
	 *
	 * @param string $m Message.
	 * @return array|null
	 */
	private static function category_reply( $m ) {
		if ( ! class_exists( 'WooCommerce' ) || ! preg_match( '/\b(medicines?|medications?|drugs?|products?|range|treatments?|tablets?|injections?|items|supplements?)\b[^?]*\b(for|in|under|related to|against)\b|\b(browse|show|list|see)\b[^?]*\b(category|categories|range|medicines|products)\b/i', $m ) ) {
			return null;
		}
		$terms = get_transient( 'tcca_cats' );
		if ( ! is_array( $terms ) ) {
			$terms = array();
			$list  = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => 300 ) );
			if ( ! is_wp_error( $list ) ) {
				foreach ( $list as $t ) {
					$terms[] = array( 'slug' => $t->slug, 'name' => html_entity_decode( $t->name ), 'id' => $t->term_id );
				}
			}
			set_transient( 'tcca_cats', $terms, 6 * HOUR_IN_SECONDS );
		}
		$words = array_filter( preg_split( '/[^a-z0-9]+/', strtolower( $m ) ), static function ( $w ) {
			return strlen( $w ) >= 5 && ! in_array( $w, array( 'medicine', 'medicines', 'medication', 'medications', 'products', 'treatment', 'treatments', 'tablets', 'injections', 'supplements', 'related', 'against', 'category', 'categories' ), true );
		} );
		$best  = null;
		$score = 0;
		foreach ( $terms as $t ) {
			$hay = strtolower( $t['name'] . ' ' . $t['slug'] );
			$s   = 0;
			foreach ( $words as $w ) {
				if ( false !== strpos( $hay, $w ) || false !== strpos( $hay, rtrim( $w, 's' ) ) ) {
					$s++;
				}
			}
			if ( $s > $score ) {
				$score = $s;
				$best  = $t;
			}
		}
		if ( ! $best ) {
			return null;
		}
		$prods = wc_get_products( array( 'status' => 'publish', 'limit' => 4, 'category' => array( $best['slug'] ), 'orderby' => 'popularity', 'order' => 'DESC' ) );
		if ( ! $prods ) {
			return null;
		}
		$cards = array();
		foreach ( $prods as $p ) {
			$cards[] = self::product_card( $p );
		}
		$link = get_term_link( (int) $best['id'], 'product_cat' );
		$act  = is_wp_error( $link ) ? array() : array( array( 'type' => 'link', 'label' => sprintf( __( 'See all in %s', 'tcc-assistant' ), $best['name'] ), 'url' => $link ) );
		return self::out( 'category', sprintf( __( 'Popular in %s. This is browsing only; a pharmacist can tell you what suits you.', 'tcc-assistant' ), $best['name'] ), array( 'cards' => $cards, 'actions' => array_merge( $act, self::human_actions( $m ) ) ) );
	}

	/* ------------------------------------------------------------------ safety */

	/**
	 * Questions about the visitor's own use of a medicine: always a pharmacist.
	 *
	 * @param string $m Message.
	 * @return bool
	 */
	public static function is_personal( $m ) {
		$m = preg_replace( '/\b(?:how (?:do i |to |can i |should i )?|can i |may i )?(?:use|store|apply|enter) (?:the |a |my |this )?(?:coupon|code|promo\w*|voucher|website|site|app|account|cart|search|card|upi|paypal)\b/i', '', $m );
		return (bool) preg_match( '/\b(?:(?:doses?|dosage|dosing)\b(?=[^?.]*\b(?:my|me|i|take|taking|should|recommended|right|correct|titrat\w*|increase|reduce|skip|forgot|change|start|starting|per day|daily)\b)|(?:what|which) (?:is )?(?:the )?(?:right |correct |recommended |usual |normal |starting )?(?:doses?|dosage)\b|(?:doses?|dosage|dosing) (?:of|for)\b|missed (?:a )?dose|how (?:much|many)\b[^?.]*\b(?:take|inject|use|give)|how (?:do|to|should) (?:i |we )?(?:take|inject|use)\b|(?:can|may|should|could) (?:i|we) (?:take|use|inject|stop|combine|mix|give|drink)|interact\w*|pregnan\w*|breast ?feed\w*|nursing|safe (?:for|to|during|with|if)|is it (?:ok|okay|safe)|stop taking|allergic reaction|withdrawal|symptoms?|diagnos\w*|which (?:medicine|medication|drug|tablet)s? should|what (?:should|can) i take|alternatives? for my)\b/i', $m );
	}

	/**
	 * Any other medical-sounding question (used only when no product was named).
	 *
	 * @param string $m Message.
	 * @return bool
	 */
	public static function is_clinical( $m ) {
		$m = preg_replace( '/\bhow (?:do i |to |can i |should i )?(?:use|store) (?:the |a |my |this )?(?:coupon|code|promo\w*|voucher|website|site|app|account|cart|search|card)\b/i', '', $m );
		return (bool) preg_match(
			'/\b(dose|doses|dosage|dosing|overdue|how (?:much|many)\b.*\b(take|inject|use|give)|how (?:do|to|should) (?:i|we)?\s*(?:take|inject|use|store|give)|how to (?:take|inject|use)|side[- ]?effects?|adverse|interact\w*|contraindicat\w*|pregnan\w*|breast ?feed\w*|nursing|alternatives? (?:to|for)|substitute|safe (?:for|to|during|with)|missed (?:a )?dose|withdrawal|symptoms?|diagnos\w*|which (?:medicine|medication|drug|tablet) should|what should i take|(?:can|may|should) i (?:take|use|stop|combine|mix)|is it (?:ok|okay|safe)|stop taking|allergic reaction|works? for|treat(?:s|ment)? (?:for|of)|cure for)\b/i',
			$m
		);
	}

	/**
	 * Hand clinical questions to a pharmacist, pointing at the product page's own information.
	 *
	 * @param string $m Message.
	 * @return array
	 */
	private static function clinical_reply( $m ) {
		$cards = array();
		if ( class_exists( 'WooCommerce' ) ) {
			$found = self::find_products( $m, 1 );
			if ( $found && self::mentions_product( $m, $found[0] ) ) {
				$cards[] = self::product_card( $found[0] );
			}
		}
		$text = __( 'Questions about how to take a medicine, doses, side effects, interactions or whether something is right for you need a pharmacist or your prescriber, so I will not guess.', 'tcc-assistant' );
		if ( $cards ) {
			$text .= ' ' . __( 'The product page lists the manufacturer information (Uses and Safety tabs).', 'tcc-assistant' );
		}
		return self::out( 'clinical', $text, array( 'cards' => $cards, 'actions' => self::human_actions( $m ), 'form' => 'handoff' ) );
	}

	/* ---------------------------------------------------------------- products */

	/**
	 * Words that make a question about the shop rather than a bare medicine name.
	 *
	 * @param string $n Lowercase message.
	 * @return bool
	 */
	private static function has_commerce_word( $n ) {
		return (bool) preg_match( '/\b(price|cost|buy|order|stock|available|availability|have|sell|looking for|need|find|get|want|cheapest|discount|how much)\b/', $n );
	}

	/**
	 * Long how/what/why questions are not product lookups.
	 *
	 * @param string $n Lowercase message.
	 * @return bool
	 */
	private static function is_question( $n ) {
		return str_word_count( $n ) > 6 || (bool) preg_match( '/^(how|why|what|when|which|who|is|are|can|could|should|does|do|will)\b/', $n ) && ! self::has_commerce_word( $n );
	}

	/**
	 * Turn "do you have mounjaro 5mg in stock" into "mounjaro 5mg".
	 *
	 * @param string $m Message.
	 * @return string
	 */
	private static function extract_query( $m ) {
		$q = strtolower( $m );
		$q = preg_replace( '/\b(do you (have|sell|stock)|is|are|price of|prices? for|cost of|how much (is|does|are)|looking for|i (am|m)? ?looking for|i need|i want|can i (buy|get|order)|where can i (buy|get)|buy|order|get me|find me|find|show me|search( for)?|available|availability|in stock|stock|please|the|a|an|any|medicine|medication|for me|online|cheapest|price|cost|\?)\b/', ' ', $q );
		return trim( preg_replace( '/[^a-z0-9.\s\-\/]+|\s+/', ' ', $q ) );
	}

	/**
	 * Products matching a message, filtered so unrelated fuzzy hits are dropped.
	 *
	 * @param string $m     Message.
	 * @param int    $limit Max.
	 * @return WC_Product[]
	 */
	public static function find_products( $m, $limit = 4 ) {
		$q = self::extract_query( $m );
		if ( strlen( $q ) < 3 ) {
			return array();
		}
		if ( function_exists( 'mm_smart_search' ) ) {
			$list = mm_smart_search( $q, $limit + 4 );
		} else {
			$list = wc_get_products( array( 's' => $q, 'status' => 'publish', 'limit' => $limit + 4 ) );
		}
		$out = array();
		foreach ( $list as $p ) {
			if ( self::mentions_product( $q, $p ) ) {
				$out[] = $p;
			}
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Does the text share a real word (or a near-typo of one) with the product's name or composition?
	 *
	 * @param string     $text Text.
	 * @param WC_Product $p    Product.
	 * @return bool
	 */
	public static function mentions_product( $text, $p ) {
		$hay = strtolower( $p->get_name() );
		if ( function_exists( 'mm_composition_terms' ) ) {
			foreach ( mm_composition_terms( $p->get_id() ) as $t ) {
				$hay .= ' ' . strtolower( $t->name );
			}
		}
		$words = preg_split( '/[^a-z0-9]+/', $hay, -1, PREG_SPLIT_NO_EMPTY );
		foreach ( preg_split( '/[^a-z0-9]+/', strtolower( $text ), -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
			if ( strlen( $w ) < 4 || ctype_digit( $w ) ) {
				continue;
			}
			foreach ( $words as $h ) {
				if ( strlen( $h ) < 4 ) {
					continue;
				}
				if ( false !== strpos( $h, $w ) || false !== strpos( $w, $h ) || levenshtein( $w, $h ) <= ( strlen( $w ) >= 7 ? 2 : 1 ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Compact product card for the chat.
	 *
	 * @param WC_Product $p Product.
	 * @return array
	 */
	public static function product_card( $p, $why = '' ) {
		$price = $p->get_price();
		$mrp   = (float) $p->get_regular_price();
		$off   = 0;
		$from  = '';
		if ( function_exists( 'mm_price_data' ) ) {
			$d     = mm_price_data( $p );
			$price = $d['price'];
			$mrp   = $d['mrp'];
			$off   = $d['off'];
		} elseif ( $mrp > 0 && (float) $price > 0 && $mrp > (float) $price ) {
			$off = (int) round( ( ( $mrp - (float) $price ) / $mrp ) * 100 );
		}
		if ( $p->is_type( 'variable' ) ) {
			$from = __( 'From', 'tcc-assistant' );
		}
		$attrs = function_exists( 'mm_card_attrs' ) ? mm_card_attrs( $p ) : array( 'brand' => '', 'strength' => '', 'form' => '', 'pack' => '' );
		$meta  = array_filter( array( $attrs['brand'], $attrs['strength'], $attrs['form'], $attrs['pack'] ) );
		$img   = wp_get_attachment_image_url( $p->get_image_id(), 'woocommerce_thumbnail' );
		$can   = $p->is_purchasable() && $p->is_in_stock() && ( $p->is_type( 'simple' ) || function_exists( 'mm_default_variation_id' ) );
		return array(
			'type'    => 'product',
			'id'      => $p->get_id(),
			'name'    => wp_strip_all_tags( $p->get_name() ),
			'url'     => get_permalink( $p->get_id() ),
			'img'     => $img ? $img : '',
			'meta'    => implode( ' · ', $meta ),
			'price'   => ( '' !== (string) $price && (float) $price > 0 ) ? TCCA_Core::money( (float) $price ) : '',
			'from'    => $from,
			'mrp'     => ( $off > 0 ) ? TCCA_Core::money( $mrp ) : '',
			'off'     => $off,
			'stock'   => $p->is_in_stock(),
			'rx'      => function_exists( 'mm_product_flag' ) ? (bool) mm_product_flag( $p, 'rx' ) : false,
			'canAdd'  => $can,
			'why'     => (string) $why,
		);
	}

	/**
	 * Product search reply, or null when nothing relevant.
	 *
	 * @param string $m Message.
	 * @return array|null
	 */
	private static function product_reply( $m, $ctx = array(), $orig = '' ) {
		$found = self::find_products( $m, 4 );
		if ( ! $found ) {
			// A short, medicine-like message with no hit: offer sourcing rather than a dead end.
			$q       = self::extract_query( $m );
			$generic = (bool) preg_match( '/\b(help|question|questions|info|information|something|anything|issue|problem|assist|assistance|support|advice|details|more|one|some|order|orders|my|refund|cancel|return|change|delivery|shipping|payment|account|invoice)\b/', $q );
			if ( ! $generic && self::has_commerce_word( strtolower( $m ) ) && strlen( $q ) >= 3 && str_word_count( $q ) <= 5 ) {
				return TCCA_Concierge::not_found( self::extract_query( $m ), $m, $ctx );
			}
			return null;
		}
		// The only match cannot be bought right now: rescue the sale instead of showing a dead end.
		if ( ! $found[0]->is_in_stock() && ( 1 === count( $found ) || self::exact_name( self::extract_query( '' !== $orig ? $orig : $m ), $found[0] ) ) ) {
			return TCCA_Concierge::rescue_reply( $found[0], $m, $ctx );
		}
		$cards = array();
		foreach ( $found as $p ) {
			$cards[] = self::product_card( $p );
		}
		$q       = self::extract_query( $m );
		$actions = array( array( 'type' => 'link', 'label' => __( 'See all results', 'tcc-assistant' ), 'url' => add_query_arg( array( 's' => $q, 'post_type' => 'product' ), home_url( '/' ) ) ) );
		$text    = count( $found ) > 1 ? __( 'Here is what I found:', 'tcc-assistant' ) : __( 'I found this:', 'tcc-assistant' );
		$tips    = TCCA_Sales::quick_tips( $found[0] );
		if ( $tips ) {
			$text .= "\n- " . implode( "\n- ", $tips );
		}
		foreach ( $cards as $c ) {
			if ( $c['rx'] ) {
				$text .= ' ' . __( 'Items marked Rx need a prescription.', 'tcc-assistant' );
				$rx    = TCCA_Core::page_url( 'upload-prescription' );
				if ( $rx ) {
					$actions[] = array( 'type' => 'link', 'label' => __( 'Upload prescription', 'tcc-assistant' ), 'url' => $rx );
				}
				break;
			}
		}
		$chips = array();
		if ( 1 === count( $found ) && TCCA_Together::enabled() && isset( TCCA_Together::matrix()[ $found[0]->get_id() ] ) ) {
			$chips[] = array( __( 'Often ordered together', 'tcc-assistant' ), 'What do people buy with ' . $cards[0]['name'] );
		}
		return self::out( 'product', $text, array( 'cards' => $cards, 'actions' => $actions, 'chips' => $chips ) );
	}

	/* ------------------------------------------------------------------ orders */

	/**
	 * Is the visitor asking about an order?
	 *
	 * @param string $m Message.
	 * @return bool
	 */
	private static function is_order_intent( $m ) {
		$tracking = (bool) preg_match( '/\b(track\w*|where|status|shipped|arrive[sd]?|arrived|received|dispatched|delayed|late|awb|parcel|shipment|when will)\b|#\d{3,}/i', $m );
		if ( ! $tracking && preg_match( '/\b(cancel\w*|return\w*|refund\w*|exchange|change|edit|modify|amend|pay|payment|paying|invoice|bill|receipt)\b/i', $m ) ) {
			return false; // A policy question, answered from the knowledge base.
		}
		return (bool) preg_match( '/\b(track\w*|where(?:\'?s| is| are) my (?:\w+ ){0,2}(?:orders?|parcels?|packages?|shipments?|medicines?|medications?|deliver(?:y|ies))|(?:my |the )?(?:last|latest|recent|previous|most recent) (?:order|purchase|parcel|shipment)|order status|status of (?:my )?order|my orders?|delivery status|awb|shipment|parcel|dispatched|has (?:it|my order) (?:shipped|arrived)|when will (?:it|my order|my package) (?:arrive|be delivered|come)|not (?:yet )?received|late delivery|delayed|order #?\d+|#\d{3,})\b/i', $m )
			|| (bool) preg_match( '/#\d{3,}/', $m );
	}

	/**
	 * Handle an order question: signed-in customers see their own orders; guests verify with number + email.
	 *
	 * @param string $m Message.
	 * @return array
	 */
	private static function order_flow( $m, $reorder = false ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return self::out( 'order', __( 'Order tracking is not available right now.', 'tcc-assistant' ), array( 'actions' => self::human_actions(), 'answered' => false ) );
		}
		$num   = '';
		$email = '';
		if ( preg_match( '/#?\b(\d{3,10})\b/', $m, $x ) ) {
			$num = $x[1];
		}
		if ( preg_match( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $m, $x ) ) {
			$email = $x[0];
		}

		$ident = TCCA_Account::identity();
		if ( $ident ) {
			$member = 'login' === $ident['via'];
			$orders = TCCA_Account::orders( $ident, 5 );
			if ( ! $orders ) {
				return self::out( 'order', __( 'There are no orders on your account yet. Once you place one, I can track it for you.', 'tcc-assistant' ), array( 'actions' => array( array( 'type' => 'link', 'label' => __( 'Browse medicines', 'tcc-assistant' ), 'url' => function_exists( 'mm_shop_url' ) ? mm_shop_url() : home_url( '/' ) ) ) ) );
			}
			$pick = $orders[0];
			if ( $num ) {
				$pick = null;
				foreach ( $orders as $o ) {
					if ( (string) $o->get_id() === $num || (string) $o->get_order_number() === $num ) {
						$pick = $o;
						break;
					}
				}
				if ( ! $pick ) {
					$chips = array();
					foreach ( array_slice( $orders, 0, 3 ) as $o ) {
						$chips[] = array( '#' . $o->get_order_number(), 'Track order #' . $o->get_order_number() );
					}
					return self::out( 'order', sprintf( __( 'I cannot find order #%s on your account. Here are your latest orders:', 'tcc-assistant' ), $num ), array( 'chips' => $chips ) );
				}
			}
			$chips = array();
			foreach ( array_slice( $orders, 0, 4 ) as $o ) {
				if ( $o->get_id() !== $pick->get_id() ) {
					$chips[] = array( '#' . $o->get_order_number(), 'Track order #' . $o->get_order_number() );
				}
			}
			return self::out( 'order', $reorder ? __( 'Here is your last order. Tap “Reorder these items” to add everything that is in stock back to your cart.', 'tcc-assistant' ) : ( $num ? __( 'Here is that order:', 'tcc-assistant' ) : __( 'Here is your latest order:', 'tcc-assistant' ) ), array( 'cards' => array( self::order_card( $pick, $member ) ), 'chips' => $chips ) );
		}

		// Guest.
		if ( $reorder ) {
			return self::out( 'order', __( 'Sign in (no password, just an email code) and I can add your last order back to the cart in one tap.', 'tcc-assistant' ), array( 'actions' => array( array( 'type' => 'link', 'label' => __( 'Sign in', 'tcc-assistant' ), 'url' => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' ) ) ) ) );
		}
		if ( $num && $email ) {
			return self::guest_lookup( $num, $email );
		}
		return self::out( 'order', __( 'I can look that up. For your privacy I need the order number and the email address used at checkout. If you do not have them, I can email you a code instead.', 'tcc-assistant' ), array( 'form' => 'order', 'prefill' => $num, 'chips' => array( array( __( 'Find my orders by email code', 'tcc-assistant' ), 'Find my orders' ) ) ) );
	}

	/**
	 * Verify a guest's order number + billing email, then return the card. Used by chat text and the form.
	 *
	 * @param string $num   Order number.
	 * @param string $email Billing email.
	 * @return array
	 */
	public static function guest_lookup( $num, $email ) {
		$fail = self::out( 'order', __( 'I could not match that order number and email. Please check both and try again, or ask a pharmacist.', 'tcc-assistant' ), array( 'form' => 'order', 'actions' => self::human_actions(), 'answered' => false ) );
		if ( ! TCCA_Core::rate_ok( 'order_lookup', 10, HOUR_IN_SECONDS ) ) {
			return self::out( 'order', __( 'Too many lookups from this device. Please try again in an hour, or contact a pharmacist.', 'tcc-assistant' ), array( 'actions' => self::human_actions(), 'answered' => false ) );
		}
		$id    = absint( $num );
		$order = $id ? wc_get_order( $id ) : false;
		if ( ! $order || ! is_email( $email ) || ! hash_equals( strtolower( (string) $order->get_billing_email() ), strtolower( trim( $email ) ) ) ) {
			return $fail;
		}
		return self::out( 'order', __( 'Thanks, found it:', 'tcc-assistant' ), array( 'cards' => array( self::order_card( $order, false ) ) ) );
	}

	/**
	 * Order summary card. No address, phone or email is ever included.
	 *
	 * @param WC_Order $order  Order.
	 * @param bool     $member True when the order belongs to the signed-in customer.
	 * @return array
	 */
	private static function order_card( $order, $member ) {
		$status = $order->get_status();
		$card   = array(
			'type'    => 'order',
			'number'  => $order->get_order_number(),
			'status'  => wc_get_order_status_name( $status ),
			'tone'    => function_exists( 'tcc_status_tone' ) ? tcc_status_tone( $status ) : 'prog',
			'placed'  => $order->get_date_created() ? wp_date( 'j M Y', $order->get_date_created()->getTimestamp() ) : '',
			'total'   => trim( html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ), ENT_QUOTES, 'UTF-8' ) ),
			'steps'   => array(),
			'heading' => '',
			'eta'     => '',
			'track'   => null,
			'items'   => array(),
			'url'     => $member ? $order->get_view_order_url() : '',
			'reorder' => $member ? TCCA_Sales::reorder_items( $order ) : array(),
		);
		if ( function_exists( 'mm_track_model' ) ) {
			$t = mm_track_model( $order );
			if ( $t['rank'] >= 0 ) {
				foreach ( $t['steps'] as $i => $label ) {
					$card['steps'][] = array( 'label' => $label, 'done' => $i <= $t['rank'], 'now' => $i === $t['rank'] );
				}
				$card['heading'] = $t['heading'];
				if ( ! $t['delivered'] && ! empty( $t['eta_ts'] ) ) {
					$card['eta'] = wp_date( 'j M Y', (int) $t['eta_ts'] );
				}
			}
			if ( ! empty( $t['tracking'] ) ) {
				$url            = ! empty( $t['track_url'] ) ? esc_url_raw( str_replace( '{n}', rawurlencode( $t['tracking'] ), $t['track_url'] ), array( 'http', 'https' ) ) : '';
				$card['track']  = array( 'number' => $t['tracking'], 'carrier' => $t['carrier'], 'url' => $url );
			}
		}
		$n = 0;
		foreach ( $order->get_items() as $it ) {
			if ( $n++ >= 4 ) {
				$card['items'][] = sprintf( __( '+ %d more', 'tcc-assistant' ), count( $order->get_items() ) - 4 );
				break;
			}
			$card['items'][] = wp_strip_all_tags( $it->get_name() ) . ' × ' . (int) $it->get_quantity();
		}
		return $card;
	}

	/* ---------------------------------------------------------------- shipping */

	/**
	 * "Do you ship to Germany?" answered from WooCommerce's real shipping-country list.
	 *
	 * @param string $m Message.
	 * @return array|null
	 */
	public static function shipping_country( $m ) {
		if ( ! function_exists( 'WC' ) || ! WC()->countries || ! preg_match( '/\b(ship|deliver|send|courier|available)\b.*\b(to|in|into)\b/i', $m ) ) {
			return null;
		}
		$alias = array( 'usa' => 'US', 'america' => 'US', 'uk' => 'GB', 'england' => 'GB', 'britain' => 'GB', 'uae' => 'AE', 'dubai' => 'AE' );
		$all   = WC()->countries->get_countries();
		$ship  = WC()->countries->get_shipping_countries();
		$low   = ' ' . strtolower( preg_replace( '/[^a-z ]+/i', ' ', $m ) ) . ' ';
		$code  = '';
		$best  = 0;
		foreach ( $all as $c => $name ) {
			$nm = strtolower( trim( preg_replace( '/\s*\(.*\)/', '', $name ) ) );
			if ( strlen( $nm ) >= 4 && false !== strpos( $low, ' ' . $nm . ' ' ) && strlen( $nm ) > $best ) {
				$code = $c;
				$best = strlen( $nm );
			}
		}
		if ( ! $code ) {
			foreach ( $alias as $w => $c ) {
				if ( false !== strpos( $low, ' ' . $w . ' ' ) ) {
					$code = $c;
					break;
				}
			}
		}
		if ( ! $code || ! isset( $all[ $code ] ) ) {
			return null;
		}
		$name = preg_replace( '/\s*\(.*\)/', '', $all[ $code ] );
		if ( isset( $ship[ $code ] ) ) {
			$yes  = self::out( 'shipping', sprintf( __( 'Yes, we ship to %s. Delivery options and prices appear at checkout once you enter your address.', 'tcc-assistant' ), $name ) );
			// If the site has an article specifically about this destination (customs, delivery times), add it.
			$hits = TCCA_Index::enabled() ? TCCA_Index::search( $m, 6 ) : array();
			$more = TCCA_Index::specific( $hits ) ? TCCA_Index::answer( $hits ) : null;
			if ( $more ) {
				$yes['reply']   .= "\n\n" . $more['reply'];
				$yes['cards']    = $more['cards'];
				$yes['actions']  = $more['actions'];
				$yes['fb']       = true;
				$yes['trace']    = $more['trace'];
			}
			return $yes;
		}
		return self::out( 'shipping', sprintf( __( 'We do not currently list %s as a delivery country. A pharmacist can tell you whether an exception is possible.', 'tcc-assistant' ), $name ), array( 'actions' => self::human_actions( $m ), 'answered' => false ) );
	}
}
