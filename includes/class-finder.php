<?php
/**
 * Finder: turns "do you have anything for pimples?" and "I want Accutane" into the right products.
 *
 * Where results come from (in this order of trust):
 *  - Concerns: the text of each product's OWN page (Uses, FAQs, description, composition line) through the
 *    knowledge index, then product categories and tags as a supporting signal. Nothing here maps a condition
 *    to a drug; if your pages do not say a product is used for it, it is not shown for it.
 *  - Brands: the brand name is looked up in the Lexicon, then products that contain the same active ingredient
 *    are found through your Composition list, product names and descriptions.
 *
 * Every result says why it matched, is labelled browsing (not a recommendation), and keeps a pharmacist one tap away.
 *
 * @package TCCAssistant
 */

defined( 'ABSPATH' ) || exit;

class TCCA_Finder {

	/** A customer who is trying to buy or find something. */
	const SHOP = '/\b(buy|purchase|order|get|want|need|looking for|look for|do you (?:have|sell|stock|carry)|have you got|got any|is there|are there|available|availability|in stock|price|cost|how much|add|cart|show me|find me|find|search|where can i|can i (?:get|buy))\b/i';

	/** Words that carry no product or concern meaning. */
	const FILLER = '/\b(do|you|have|sell|stock|carry|any|anything|something|some|a|an|the|for|to|of|and|or|with|my|me|i|im|ive|got|get|need|want|looking|look|show|find|buy|please|pls|medicine|medicines|medication|medications|product|products|treatment|treatments|cream|creams|gel|tablet|tablets|capsule|capsules|injection|injections|remedy|remedies|drops|supplement|supplements|option|options|solution|solutions|help|helps|treat|against|good|best|what|which|should|can|take|use|is|are|there|that|this|has|am|having|suffering|from|dealing|struggling|problem|problems|issue|issues|online|cheap|cheapest|price|without|prescription|otc|over|counter|non|in|stock|available|lotion|shampoo|serum|wash|how|rid|cure|recommend|suggest|safe|on|your|shop|store|pharmacy|hi|hello|hey|thanks|just|really|very|bad|some)\b/i';

	/* =================================================================== pure helpers */

	/**
	 * A strength written in the message ("20mg", "0.5 mg", "10 iu"), without spaces.
	 *
	 * @param string $m Message.
	 * @return string
	 */
	public static function strength( $m ) {
		return preg_match( '/(\d+(?:\.\d+)?)\s?(mg|mcg|µg|iu|ml|g|%)(?![a-z])/iu', $m, $x ) ? strtolower( $x[1] . $x[2] ) : '';
	}

	/**
	 * Refinements the customer asked for.
	 *
	 * @param string $m Message.
	 * @return array{cheap:bool,otc:bool}
	 */
	public static function refine( $m ) {
		return array(
			'cheap' => (bool) preg_match( '/\b(cheapest|lowest price|cheaper|low cost|budget|affordable|least expensive)\b/i', $m ),
			'otc'   => (bool) preg_match( '/\b(without (?:a )?prescription|no prescription|non[- ]?prescription|not prescription|otc|over[- ]the[- ]counter|non[- ]?rx)\b/i', $m ),
		);
	}

	/**
	 * Babies, children, pregnancy: browsing is not appropriate, a pharmacist is.
	 *
	 * @param string $m Message.
	 * @return bool
	 */
	public static function needs_care( $m ) {
		return (bool) preg_match( '/\b(baby|babies|infant|toddler|newborn|my (?:son|daughter|child|kid|boy|girl)|child|children|kids?|\d+\s*(?:month|year)s?[- ]old|pregnan\w*|breast ?feed\w*|nursing mother|expecting)\b/i', $m );
	}

	/**
	 * What kind of concern message is this? 'advice' (asking what to take), 'shop' (looking for products), or ''.
	 *
	 * @param string $m Message.
	 * @param array  $c Concern from the Lexicon.
	 * @return string
	 */
	public static function concern_mode( $m, $c ) {
		$noun = '(?:medicines?|medications?|drugs?|products?|treatments?|creams?|gels?|tablets?|capsules?|injections?|remed(?:y|ies)|drops|supplements?|options?|solutions?|lotions?|shampoos?|serums?|washes|wash)';
		// Asking what to take is a request for advice: still helped, but never ranked or "recommended".
		if ( preg_match( '/\b(what (?:should|can|do|would) (?:i|we) (?:take|use|try|buy|get)|which ' . $noun . ' (?:should|is (?:best|good|better|right|safe)|works?|can|do)|(?:best|good|safest|strongest) ' . $noun . '|recommend\w*|suggest\w*|what(?:\'s| is) good for|what works for|how (?:to|do i|can i|do we) (?:treat|cure|get rid of|fix|stop|reduce|manage|control)|get rid of|\bcure\b)\b/i', $m ) ) {
			return 'advice';
		}
		$n = ' ' . TCCA_Lexicon::norm( $m ) . ' ';
		$k = $c['matched'];
		if ( preg_match( '/\b' . $noun . '\b[^?.!]{0,40}\b(?:for|to treat|against|help(?:s|ing)? with|related to|good for|to help with|that (?:treat|help)s?)\b/i', $m ) ) {
			return 'shop';
		}
		if ( preg_match( '/\b(i have|i\'?ve got|i got|i am (?:having|suffering from|dealing with)|i\'?m (?:having|suffering from|dealing with)|suffering from|dealing with|struggling with|help with|need help with|problems? with|issues? with|got)\b/i', $m ) ) {
			return 'shop';
		}
		if ( preg_match( '/ ' . preg_quote( $k, '/' ) . '(?: [a-z]+){0,2} ' . $noun . ' /', $n ) ) {
			return 'shop';
		}
		if ( preg_match( '/\b(do you (?:have|sell|stock|carry)|have you got|got any|looking for|show me|find me|buy)\b/i', $m ) ) {
			return 'shop';
		}
		// Just the word itself ("acne", "hair loss").
		$rest = trim( preg_replace( '/\s+/', ' ', preg_replace( self::FILLER, ' ', str_replace( ' ' . $k . ' ', ' ', $n ) ) ) );
		return ( '' === $rest || str_word_count( $rest ) <= 0 ) ? 'shop' : '';
	}

	/**
	 * What is left of a message once the concern and filler words are removed (a product name, if there is one).
	 *
	 * @param string $m Message.
	 * @param array  $c Concern.
	 * @return string
	 */
	public static function remainder( $m, $c ) {
		$n = ' ' . TCCA_Lexicon::norm( $m ) . ' ';
		$n = str_replace( ' ' . $c['matched'] . ' ', ' ', $n );
		return trim( preg_replace( '/\s+/', ' ', preg_replace( self::FILLER, ' ', $n ) ) );
	}

	/* =================================================================== brands */

	/**
	 * A catalogue product that carries the brand name itself.
	 *
	 * @param string $brand Brand.
	 * @return bool
	 */
	private static function direct_hit( $brand ) {
		foreach ( TCCA_Engine::find_products( $brand, 4 ) as $p ) {
			if ( false !== stripos( TCCA_Lexicon::norm( $p->get_name() ), TCCA_Lexicon::norm( $brand ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Lower-case name + composition + generic-name text for a product.
	 *
	 * @param WC_Product $p Product.
	 * @return string
	 */
	private static function hay( $p ) {
		$hay = strtolower( $p->get_name() );
		if ( function_exists( 'mm_composition_terms' ) ) {
			foreach ( mm_composition_terms( $p->get_id() ) as $t ) {
				$hay .= ' ' . strtolower( $t->name ) . ' ' . strtolower( (string) get_term_meta( $t->term_id, 'dg_generic_name', true ) );
			}
		}
		return $hay;
	}

	/**
	 * Product ids that contain one ingredient: your Composition list first, then names and descriptions.
	 *
	 * @param string $ing Ingredient.
	 * @return int[]
	 */
	private static function ids_for_ingredient( $ing ) {
		$key = 'tcca_gi_' . md5( $ing . '|' . (int) get_option( 'tcca_idxv', 0 ) );
		$hit = get_transient( $key );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		$ids = array();
		if ( taxonomy_exists( 'composition' ) ) {
			$terms = array();
			foreach ( TCCA_Lexicon::needles( $ing ) as $nd ) {
				$a = get_terms( array( 'taxonomy' => 'composition', 'hide_empty' => true, 'search' => $nd, 'fields' => 'ids', 'number' => 40 ) );
				$b = get_terms( array( 'taxonomy' => 'composition', 'hide_empty' => true, 'fields' => 'ids', 'number' => 40, 'meta_query' => array( array( 'key' => 'dg_generic_name', 'value' => $nd, 'compare' => 'LIKE' ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
				$terms = array_merge( $terms, is_wp_error( $a ) ? array() : (array) $a, is_wp_error( $b ) ? array() : (array) $b );
			}
			$terms = array_values( array_unique( array_map( 'intval', $terms ) ) );
			if ( $terms ) {
				$ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 40, 'no_found_rows' => true, 'tax_query' => array( array( 'taxonomy' => 'composition', 'field' => 'term_id', 'terms' => $terms ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			}
		}
		// Names and descriptions, kept only if the ingredient really is in the name or composition.
		foreach ( (array) wc_get_products( array( 's' => $ing, 'status' => 'publish', 'limit' => 40, 'return' => 'ids' ) ) as $id ) {
			if ( in_array( (int) $id, $ids, true ) ) {
				continue;
			}
			$p = wc_get_product( $id );
			if ( $p && TCCA_Lexicon::contains_generic( self::hay( $p ), $ing ) ) {
				$ids[] = (int) $id;
			}
		}
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		set_transient( $key, $ids, 30 * MINUTE_IN_SECONDS );
		return $ids;
	}

	/**
	 * Products that contain a generic (every ingredient of a combination), best first: the strength asked for,
	 * in stock, then lowest price.
	 *
	 * @param string $generic  Generic ("a+b").
	 * @param string $strength Strength asked for ("20mg") or ''.
	 * @param int    $limit    Max.
	 * @return WC_Product[]
	 */
	public static function generic_products( $generic, $strength = '', $limit = 5 ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array();
		}
		$ids = null;
		foreach ( TCCA_Lexicon::ingredients( $generic ) as $ing ) {
			$one = self::ids_for_ingredient( $ing );
			$ids = null === $ids ? $one : array_values( array_intersect( $ids, $one ) );
		}
		$rows = array();
		foreach ( (array) $ids as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p || ! $p->is_visible() ) {
				continue;
			}
			$name  = strtolower( preg_replace( '/\s+/', '', $p->get_name() . ( function_exists( 'mm_card_attrs' ) ? mm_card_attrs( $p )['strength'] : '' ) ) );
			$price = function_exists( 'mm_price_data' ) ? (float) mm_price_data( $p )['price'] : (float) $p->get_price();
			$rows[] = array(
				'p'   => $p,
				'str' => ( '' !== $strength && false !== strpos( $name, $strength ) ) ? 0 : 1,
				'oos' => $p->is_in_stock() ? 0 : 1,
				'pr'  => $price > 0 ? $price : 1e9,
			);
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				return array( $a['oos'], $a['str'], $a['pr'] ) <=> array( $b['oos'], $b['str'], $b['pr'] );
			}
		);
		return array_slice( array_column( $rows, 'p' ), 0, $limit );
	}

	/**
	 * Answer for a brand name the shop does not list under that name.
	 *
	 * @param string $orig Message as typed.
	 * @param array  $al   Result of TCCA_Lexicon::find().
	 * @param array  $ctx  Context (session).
	 * @return array|null Null when the normal flow should answer (the brand itself is listed, or it is a plain information question).
	 */
	public static function brand_flow( $orig, array $al, $ctx ) {
		if ( ! class_exists( 'WooCommerce' ) || ! $al ) {
			return null;
		}
		// Doses, pregnancy, interactions and "can I take": always a pharmacist, never a shopping list.
		if ( TCCA_Engine::is_personal( $orig ) ) {
			return null;
		}
		$f = $al[0];
		if ( self::direct_hit( $f['brand'] ) ) {
			return null; // The shop lists the brand itself: the normal product answers apply.
		}
		$section  = TCCA_Product::section_for( $orig );
		$shopping = (bool) preg_match( self::SHOP, $orig );
		$rest     = trim( preg_replace( '/\s+/', ' ', preg_replace( self::FILLER, ' ', ' ' . TCCA_Lexicon::norm( $orig ) . ' ' ) ) );
		$bare     = str_word_count( preg_replace( '/\b' . preg_quote( $f['typed'], '/' ) . '\b|\d+\s?(?:mg|mcg|iu|ml|g)\b|\b\d+\b/i', '', $rest ) ) <= 1;
		// Questions answered from a product page (Uses, Safety, FAQs, composition) continue with the ingredient added to the query.
		if ( in_array( $section, array( 'uses', 'safety', 'faq', 'composition', 'storage', 'reviews', 'rx' ), true ) && ! $shopping ) {
			return self::generic_products( $f['generic'], '', 1 ) ? null : self::not_listed( $f, $orig, $ctx );
		}
		if ( ! $shopping && ! $bare && ! in_array( $section, array( 'about', 'suggest', 'strengths' ), true ) ) {
			return null; // Compare, discount and the like are handled by their own steps.
		}
		$strength = self::strength( $orig );
		$prods    = self::generic_products( $f['generic'], $strength, 5 );
		if ( ! $prods ) {
			return self::not_listed( $f, $orig, $ctx );
		}
		$cards = array();
		$match = false;
		foreach ( $prods as $p ) {
			$c = TCCA_Engine::product_card( $p );
			if ( '' !== $strength && false !== strpos( strtolower( preg_replace( '/\s+/', '', $c['name'] . $c['meta'] ) ), $strength ) ) {
				$match = true;
			}
			$cards[] = $c;
		}
		$gen  = str_replace( '+', ' + ', $f['generic'] );
		$text = array();
		if ( $f['fuzzy'] ) {
			$text[] = sprintf( /* translators: 1: what was typed 2: brand */ __( 'I read “%1$s” as %2$s.', 'tcc-assistant' ), $f['typed'], $f['brand'] );
		}
		$text[] = sprintf( /* translators: 1: brand 2: ingredient */ __( '%1$s is a brand name for %2$s. We do not list %1$s itself, but we do list %2$s, the same active ingredient, from other makers:', 'tcc-assistant' ), $f['brand'], $gen );
		if ( '' !== $strength ) {
			$text[] = $match ? sprintf( __( 'The %s strength is shown first.', 'tcc-assistant' ), $strength ) : sprintf( __( 'I could not see a %s listing, so these are the strengths we have.', 'tcc-assistant' ), $strength );
		}
		$actions = TCCA_Engine::human_actions( $orig );
		foreach ( $cards as $c ) {
			if ( $c['rx'] ) {
				$text[] = __( 'Items marked Rx need a prescription; you can upload it any time.', 'tcc-assistant' );
				if ( $u = TCCA_Core::page_url( 'upload-prescription' ) ) {
					array_unshift( $actions, array( 'type' => 'link', 'label' => __( 'Upload prescription', 'tcc-assistant' ), 'url' => $u ) );
				}
				break;
			}
		}
		$text[] = __( 'The same active ingredient is not always the same product (maker, strength, pack), so please confirm any switch from a brand with your pharmacist or prescriber.', 'tcc-assistant' );
		$chips  = array();
		if ( count( $cards ) >= 2 ) {
			$chips[] = array( __( 'Compare these', 'tcc-assistant' ), 'Compare ' . $cards[0]['name'] . ' and ' . $cards[1]['name'] );
		}
		TCCA_Events::log( 'brand', isset( $ctx['session'] ) ? $ctx['session'] : '', array( 'ref' => $f['brand'] . ' > ' . $gen ) );
		return TCCA_Engine::out( 'brand', implode( "\n", $text ), array( 'cards' => $cards, 'actions' => $actions, 'chips' => $chips ) );
	}

	/**
	 * Neither the brand nor its ingredient is listed: say so, record the demand, and offer to source it.
	 */
	private static function not_listed( $f, $orig, $ctx ) {
		$gen = str_replace( '+', ' + ', $f['generic'] );
		TCCA_Events::log( 'miss', isset( $ctx['session'] ) ? $ctx['session'] : '', array( 'ref' => $f['brand'] . ' (' . $gen . ')' ) );
		return TCCA_Engine::out(
			'brand_none',
			sprintf( /* translators: 1: brand 2: ingredient */ __( '%1$s is a brand name for %2$s. I could not find %1$s or %2$s in our catalogue right now. A pharmacist can often source medicines we do not list: leave your email and they will look into it.', 'tcc-assistant' ), $f['brand'], $gen ),
			array( 'actions' => TCCA_Engine::human_actions( $orig ), 'form' => 'request', 'fterm' => $f['brand'] . ' (' . $gen . ')', 'answered' => false )
		);
	}

	/**
	 * A short line for answers about a brand that was read as its ingredient ("Accutane is isotretinoin here").
	 *
	 * @param array $al Result of find().
	 * @return string
	 */
	public static function alias_note( array $al ) {
		if ( ! $al ) {
			return '';
		}
		$f = $al[0];
		return sprintf( /* translators: 1: brand 2: ingredient */ __( '%1$s is a brand name for %2$s; this is the %2$s listing.', 'tcc-assistant' ), $f['brand'], str_replace( '+', ' + ', $f['generic'] ) );
	}

	/* =================================================================== concerns */

	/**
	 * "Do you have anything for pimples?" -> products whose own pages say they are used for it.
	 *
	 * @param string $m   Message.
	 * @param array  $ctx Context.
	 * @return array|null
	 */
	public static function concern_flow( $m, $ctx ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return null;
		}
		$c = TCCA_Lexicon::find_concern( $m );
		if ( ! $c ) {
			return null;
		}
		$mode = self::concern_mode( $m, $c );
		if ( '' === $mode ) {
			return null;
		}
		// Naming an actual product ("is Differin good for acne") is answered by the product steps.
		$rest = self::remainder( $m, $c );
		if ( strlen( $rest ) >= 4 ) {
			$f = TCCA_Engine::find_products( $rest, 1 );
			if ( $f && TCCA_Engine::mentions_product( $rest, $f[0] ) ) {
				return null;
			}
		}
		$sess = isset( $ctx['session'] ) ? $ctx['session'] : '';
		if ( self::needs_care( $m ) ) {
			return TCCA_Engine::out( 'concern_care', __( 'For babies, children, pregnancy and breastfeeding, please ask a pharmacist or doctor before choosing any medicine. They can also tell me what to look for, and I will find it for you.', 'tcc-assistant' ), array( 'actions' => TCCA_Engine::human_actions( $m ), 'form' => 'handoff' ) );
		}
		if ( $c['sensitive'] ) {
			return TCCA_Engine::out( 'concern_care', sprintf( /* translators: %s: concern */ __( 'That is something to talk through with a doctor or pharmacist rather than pick from a list. If you have already been prescribed a specific medicine for %s, tell me its name and I will check price and stock.', 'tcc-assistant' ), $c['label'] ), array( 'actions' => TCCA_Engine::human_actions( $m ), 'form' => 'handoff' ) );
		}
		$o    = self::refine( $m );
		$rows = self::concern_products( $c, array( 'limit' => 'advice' === $mode ? 3 : 5, 'cheap' => $o['cheap'], 'otc' => $o['otc'] ) );
		TCCA_Events::log( 'concern', $sess, array( 'ref' => $c['label'] ) );
		$art = self::articles( $c );
		if ( ! $rows ) {
			TCCA_Events::log( 'miss', $sess, array( 'ref' => $c['label'] ) );
			return TCCA_Engine::out(
				'concern_none',
				sprintf( /* translators: %s: concern */ __( 'None of our product pages mention %s right now. A pharmacist may know something suitable we can source: leave your email and they will look into it.', 'tcc-assistant' ), $c['label'] ),
				array( 'cards' => $art, 'actions' => TCCA_Engine::human_actions( $m ), 'form' => 'request', 'fterm' => $c['label'], 'answered' => false )
			);
		}
		$cards = array();
		$rx    = false;
		foreach ( $rows as $r ) {
			$card = TCCA_Engine::product_card( $r['p'], $r['why'] );
			$rx   = $rx || $card['rx'];
			$cards[] = $card;
		}
		if ( 'advice' === $mode ) {
			$text = sprintf( /* translators: %s: concern */ __( 'I cannot tell you what to take, because that depends on you and a pharmacist or your prescriber is the right person to decide. What I can do is show what our catalogue lists for %s, based on what each product page says:', 'tcc-assistant' ), $c['label'] );
		} else {
			$text = sprintf( /* translators: %s: concern */ __( 'Here is what our catalogue lists for %s, matched on what each product page says it is used for. This is browsing, not a recommendation: which one suits you is a question for a pharmacist or your prescriber.', 'tcc-assistant' ), $c['label'] );
		}
		if ( $o['otc'] ) {
			$text .= ' ' . __( 'Showing only items not marked prescription-only on our site.', 'tcc-assistant' );
		}
		$actions = TCCA_Engine::human_actions( $m );
		if ( $rx ) {
			$text .= ' ' . __( 'Items marked Rx need a prescription.', 'tcc-assistant' );
			if ( $u = TCCA_Core::page_url( 'upload-prescription' ) ) {
				array_unshift( $actions, array( 'type' => 'link', 'label' => __( 'Upload prescription', 'tcc-assistant' ), 'url' => $u ) );
			}
		}
		$chips = array();
		if ( ! $o['cheap'] ) {
			$chips[] = array( __( 'Cheapest first', 'tcc-assistant' ), 'Cheapest options for ' . $c['label'] );
		}
		if ( ! $o['otc'] ) {
			$chips[] = array( __( 'No prescription needed', 'tcc-assistant' ), 'Non-prescription options for ' . $c['label'] );
		}
		return TCCA_Engine::out( 'concern', $text, array( 'cards' => array_merge( $cards, array_slice( $art, 0, 1 ) ), 'actions' => $actions, 'chips' => $chips ) );
	}

	/**
	 * An article of yours about the concern, as a link card (never a quote).
	 *
	 * @param array $c Concern.
	 * @return array
	 */
	private static function articles( $c ) {
		if ( ! TCCA_Index::enabled() ) {
			return array();
		}
		$out = array();
		foreach ( TCCA_Index::search( $c['label'], 6 ) as $h ) {
			if ( in_array( $h['type'], array( 'post', 'page' ), true ) && $h['cov'] >= TCCA_Index::WEAK && '' !== $h['url'] ) {
				$out[] = array( 'type' => 'article', 'title' => $h['title'], 'excerpt' => TCCA_Index::snippet( $h['body'], $h['qt'], 150 ), 'url' => $h['url'], 'kind' => __( 'Article', 'tcc-assistant' ) );
				if ( count( $out ) >= 1 ) {
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * Index passages (Uses, FAQs, description) that mention a concern, as product id => score and a verbatim reason.
	 *
	 * @param string[] $phrases Concern phrases.
	 * @param array    $score   Scores (by reference).
	 * @param array    $why     Reasons (by reference).
	 */
	private static function index_scores( $phrases, &$score, &$why ) {
		global $wpdb;
		$t      = TCCA_Index::table();
		$weight = array( 'uses' => 3.0, 'faq' => 2.0, 'about' => 1.5 );
		$label  = array( 'uses' => __( 'Listed use:', 'tcc-assistant' ), 'faq' => __( 'From its FAQ:', 'tcc-assistant' ), 'about' => __( 'From its page:', 'tcc-assistant' ) );
		$best   = array();
		$words  = array(); // product id => distinct phrases that matched it.
		foreach ( $phrases as $ph ) {
			$tok = array_values( array_filter( TCCA_KB::tokens( $ph ), static function ( $w ) {
				return strlen( $w ) >= 3;
			} ) );
			if ( ! $tok ) {
				continue;
			}
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT obj_id, part, body FROM {$t} WHERE type = 'product' AND part IN ('uses','faq','about') AND MATCH(terms) AGAINST (%s IN BOOLEAN MODE) LIMIT 80", '+' . implode( ' +', $tok ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
			foreach ( $rows as $r ) {
				$id = (int) $r['obj_id'];
				$w  = ( isset( $weight[ $r['part'] ] ) ? $weight[ $r['part'] ] : 1.0 ) + ( count( $tok ) > 1 ? 0.4 : 0.0 );
				$words[ $id ][ $ph ] = true;
				if ( ! isset( $best[ $id ] ) || $w > $best[ $id ]['w'] ) {
					$best[ $id ] = array( 'w' => $w, 'part' => $r['part'], 'body' => $r['body'], 'tok' => $tok );
				}
			}
		}
		foreach ( $best as $id => $b ) {
			// The product's own best evidence, plus a little for being matched by several different words for the same concern.
			$score[ $id ] = $b['w'] + min( 0.75, 0.25 * ( count( $words[ $id ] ) - 1 ) );
			$why[ $id ]   = array( 'w' => $b['w'], 'text' => $label[ $b['part'] ] . ' ' . TCCA_Index::snippet( $b['body'], $b['tok'], 130 ) );
		}
	}

	/**
	 * Products in categories or tags named for the concern (a weaker signal than the product's own text).
	 *
	 * @param string[] $phrases Concern phrases.
	 * @param array    $score   Scores (by reference).
	 * @param array    $why     Reasons (by reference).
	 */
	private static function term_scores( $phrases, &$score, &$why ) {
		$key = 'tcca_ct_' . md5( implode( '|', $phrases ) );
		$hit = get_transient( $key );
		if ( ! is_array( $hit ) ) {
			$hit = array();
			foreach ( array_slice( $phrases, 0, 12 ) as $ph ) {
				$terms = get_terms( array( 'taxonomy' => array( 'product_cat', 'product_tag' ), 'name__like' => $ph, 'hide_empty' => true, 'number' => 4 ) );
				if ( is_wp_error( $terms ) ) {
					continue;
				}
				foreach ( $terms as $t ) {
					$args = array( 'status' => 'publish', 'limit' => 12, 'return' => 'ids' );
					$args[ 'product_cat' === $t->taxonomy ? 'category' : 'tag' ] = array( $t->slug );
					foreach ( (array) wc_get_products( $args ) as $id ) {
						$hit[ (int) $id ] = html_entity_decode( $t->name );
					}
				}
			}
			set_transient( $key, $hit, 30 * MINUTE_IN_SECONDS );
		}
		foreach ( $hit as $id => $name ) {
			if ( ! isset( $score[ $id ] ) ) {
				$score[ $id ] = 1.2;
				$why[ $id ]   = array( 'w' => 1.2, 'text' => sprintf( /* translators: %s: category */ __( 'Listed under %s.', 'tcc-assistant' ), $name ) );
			}
		}
	}

	/**
	 * Rank products for a concern.
	 *
	 * @param array $c Concern.
	 * @param array $o limit, cheap, otc.
	 * @return array<int,array{p:WC_Product,why:string,score:float}>
	 */
	public static function concern_products( $c, $o = array() ) {
		$o      = array_merge( array( 'limit' => 5, 'cheap' => false, 'otc' => false ), $o );
		$score  = array();
		$why    = array();
		$phrase = array_slice( $c['phrases'], 0, 14 );
		if ( TCCA_Index::enabled() ) {
			self::index_scores( $phrase, $score, $why );
		}
		self::term_scores( $phrase, $score, $why );
		if ( ! $score ) { // Index off or empty: WordPress product search on the concern words.
			foreach ( array_slice( $phrase, 0, 6 ) as $ph ) {
				foreach ( (array) wc_get_products( array( 's' => $ph, 'status' => 'publish', 'limit' => 12, 'return' => 'ids' ) ) as $id ) {
					$score[ (int) $id ] = 1.0;
					$why[ (int) $id ]   = array( 'w' => 1.0, 'text' => '' );
				}
			}
		}
		$rows = array();
		foreach ( $score as $id => $s ) {
			$p = wc_get_product( $id );
			if ( ! $p || ! $p->is_visible() || $p->is_type( 'variation' ) ) {
				continue;
			}
			$rx = function_exists( 'mm_product_flag' ) && mm_product_flag( $p, 'rx' );
			if ( $o['otc'] && $rx ) {
				continue;
			}
			$price  = function_exists( 'mm_price_data' ) ? (float) mm_price_data( $p )['price'] : (float) $p->get_price();
			$sales  = (int) $p->get_total_sales();
			$rows[] = array(
				'p'     => $p,
				'why'   => isset( $why[ $id ] ) ? $why[ $id ]['text'] : '',
				'score' => (float) $s,
				'rank'  => $s + ( $p->is_in_stock() ? 0.6 : 0.0 ) + min( 0.4, log10( 1 + $sales ) / 10 ),
				'price' => $price > 0 ? $price : 1e9,
				'oos'   => $p->is_in_stock() ? 0 : 1,
			);
		}
		usort(
			$rows,
			static function ( $a, $b ) use ( $o ) {
				if ( $o['cheap'] ) {
					return array( $a['oos'], $a['price'] ) <=> array( $b['oos'], $b['price'] );
				}
				return $b['rank'] <=> $a['rank'];
			}
		);
		return array_slice( $rows, 0, max( 1, (int) $o['limit'] ) );
	}
}
