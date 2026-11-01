<?php
/**
 * Product knowledge: reads a product's published content (description, Uses, Safety, FAQs, composition,
 * strengths, pack tiers, reviews, attributes) and answers from it. Medical text is only ever shown
 * verbatim from the product page, never paraphrased or invented.
 *
 * @package TCCAssistant
 */

defined( 'ABSPATH' ) || exit;

class TCCA_Product {

	/**
	 * Which part of a product is the visitor asking about? '' means "not a product-information question".
	 *
	 * @param string $m Message.
	 * @return string suggest|safety|uses|faq|composition|strengths|storage|reviews|rx|about|''
	 */
	public static function section_for( $m ) {
		if ( preg_match( '/\b(price|cost|how much is|cheapest|in stock|available|availability|add to cart|buy|order)\b/i', $m ) ) {
			return '';
		}
		$map = array(
			'suggest'     => '/\b(alternatives?|alternate|generics?|cheaper|substitutes?|same (?:salt|ingredient|composition|molecule)|similar|other (?:brands?|options?)|suggest\w*|recommend\w*|related|goes with)\b/i',
			'safety'      => '/\b(side[- ]?effects?|safety|warnings?|precautions?|adverse|contraindicat\w*)\b/i',
			'uses'        => '/\b(used for|uses?|indicat\w*|purpose|benefits?|what (?:does|is) .{2,40} for)\b/i',
			'faq'         => '/\b(faqs?|frequently asked|common questions?)\b/i',
			'composition' => '/\b(composition|ingredients?|salt|contains?|active|generic name|molecule|drug class|made of)\b/i',
			'strengths'   => '/\b(strengths?|sizes?|packs?|packing|packaging|quantity|how many (?:tablets|capsules|pens|units|pills)|bulk|buy more|tiers?|other doses?)\b/i',
			'storage'     => '/\b(storage|stor(?:e|ed|ing)|keep|refrigerat\w*|cold chain|temperature|fridge)\b/i',
			'reviews'     => '/\b(reviews?|ratings?|reviewed|feedback)\b/i',
			'rx'          => '/\b(rx|prescription (?:needed|required)|need a prescription|require a prescription)\b/i',
			'about'       => '/\b(about|describe|description|details?|tell me (?:more|about)|information|info|what is|what\'?s|how (?:does|it) work\w*)\b/i',
		);
		foreach ( $map as $k => $re ) {
			if ( preg_match( $re, $m ) ) {
				return $k;
			}
		}
		return '';
	}

	/**
	 * Strip the question words so only the medicine name is left for the search.
	 *
	 * @param string $m Message.
	 * @return string
	 */
	public static function term( $m ) {
		$q = strtolower( $m );
		$q = preg_replace( '/\b(what|whats|what\'s|which|are|is|the|of|for|about|tell|me|more|info|information|details?|describe|description|side|effects?|safety|warnings?|precautions?|uses?|used|faqs?|frequently|asked|questions?|common|composition|ingredients?|salt|contains?|active|generic|name|molecule|drug|class|made|strengths?|sizes?|packs?|packing|quantity|bulk|buy|more|tiers?|storage|store|stored|keep|reviews?|ratings?|rx|prescription|needed|required|need|a|an|does|do|it|work|works|how|alternatives?|alternate|generics?|cheaper|substitutes?|same|ingredient|composition|similar|other|brands?|options?|suggest|recommend|related|and|to|can|you|please|show|give|list|any|your|its)\b/', ' ', $q );
		return trim( preg_replace( '/[^a-z0-9.\s\-\/]+|\s+/', ' ', $q ) );
	}

	/**
	 * Plain text from stored HTML; list items become "- " lines.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function plain( $html ) {
		$html = preg_replace( '#</(li|p|h[1-6]|div|tr)>#i', "\n", (string) $html );
		$html = preg_replace( '#<li[^>]*>#i', '- ', $html );
		$html = preg_replace( '#<br\s*/?>#i', "\n", $html );
		$txt  = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		$txt  = preg_replace( "/[ \t]+/", ' ', $txt );
		return trim( preg_replace( "/\n\s*\n+/", "\n", $txt ) );
	}

	/**
	 * Shorten at a line or sentence end.
	 *
	 * @param string $text Plain text.
	 * @param int    $max  Max characters.
	 * @return string
	 */
	public static function clip( $text, $max = 700 ) {
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$cut = mb_substr( $text, 0, $max );
		$pos = max( (int) mb_strrpos( $cut, "\n" ), (int) mb_strrpos( $cut, '. ' ) );
		if ( $pos > $max * 0.5 ) {
			$cut = mb_substr( $cut, 0, $pos + 1 );
		} else {
			$cut = mb_substr( $cut, 0, (int) mb_strrpos( $cut, ' ' ) );
		}
		return rtrim( $cut ) . ' …';
	}

	/**
	 * Everything the store has published about one product.
	 *
	 * @param WC_Product $p Product.
	 * @return array
	 */
	public static function profile( $p ) {
		$id   = $p->is_type( 'variation' ) ? $p->get_parent_id() : $p->get_id();
		$main = $id === $p->get_id() ? $p : wc_get_product( $id );
		$info = function_exists( 'mm_product_info' ) ? mm_product_info( $id ) : array( 'uses' => '', 'safety' => '', 'faqs' => array() );

		$comps = array();
		if ( function_exists( 'mm_composition_terms' ) ) {
			foreach ( mm_composition_terms( $id ) as $t ) {
				$comps[] = array(
					'id'      => $t->term_id,
					'name'    => html_entity_decode( $t->name ),
					'generic' => (string) get_term_meta( $t->term_id, 'dg_generic_name', true ),
					'class'   => (string) get_term_meta( $t->term_id, 'dg_drug_class', true ),
				);
			}
		}
		$attrs = array();
		foreach ( $main->get_attributes() as $a ) {
			if ( ! is_object( $a ) || ! method_exists( $a, 'get_name' ) ) {
				continue;
			}
			$vals = $a->is_taxonomy() ? wc_get_product_terms( $id, $a->get_name(), array( 'fields' => 'names' ) ) : $a->get_options();
			$vals = array_filter( array_map( 'trim', array_map( 'strval', (array) $vals ) ) );
			if ( $vals ) {
				$attrs[ wc_attribute_label( $a->get_name() ) ] = implode( ', ', $vals );
			}
		}
		// Public custom fields (names not starting with "_"), e.g. from ACF.
		$extra = array();
		foreach ( (array) get_post_meta( $id ) as $k => $vals ) {
			if ( '_' === substr( $k, 0, 1 ) || count( $extra ) >= 10 || ! isset( $vals[0] ) || is_serialized( $vals[0] ) ) {
				continue;
			}
			$v = trim( wp_strip_all_tags( (string) $vals[0] ) );
			if ( '' !== $v && mb_strlen( $v ) <= 300 && ! preg_match( '/^(total_sales|rank_math|_?yoast|footnotes)/i', $k ) ) {
				$extra[ ucwords( str_replace( array( '_', '-' ), ' ', $k ) ) ] = $v;
			}
		}
		$cats = get_the_terms( $id, 'product_cat' );

		return array(
			'id'        => $id,
			'name'      => wp_strip_all_tags( $main->get_name() ),
			'url'       => get_permalink( $id ),
			'sku'       => (string) $main->get_sku(),
			'about'     => self::plain( $main->get_description() ),
			'short'     => self::plain( $main->get_short_description() ),
			'how'       => self::plain( get_post_meta( $id, '_mm_how_it_works', true ) ),
			'uses'      => self::plain( $info['uses'] ),
			'safety'    => self::plain( $info['safety'] ),
			'faqs'      => $info['faqs'],
			'comps'     => $comps,
			'attrs'     => $attrs,
			'extra'     => $extra,
			'cats'      => ( $cats && ! is_wp_error( $cats ) ) ? wp_list_pluck( $cats, 'name' ) : array(),
			'rx'        => function_exists( 'mm_product_flag' ) ? (bool) mm_product_flag( $id, 'rx' ) : false,
			'cold'      => function_exists( 'mm_product_flag' ) ? (bool) mm_product_flag( $id, 'cold_chain' ) : false,
			'imported'  => function_exists( 'mm_product_flag' ) ? (bool) mm_product_flag( $id, 'imported' ) : false,
			'pack'      => function_exists( 'mm_pack_label' ) ? trim( (string) mm_pack_label( $main ) ) : '',
			'strengths' => function_exists( 'mm_dosage_data' ) ? mm_dosage_data( $main ) : array(),
			'tiers'     => function_exists( 'mm_bulk_display_tiers' ) ? mm_bulk_display_tiers( $main ) : array(),
			'rating'    => (float) $main->get_average_rating(),
			'reviews'   => (int) $main->get_review_count(),
		);
	}

	/**
	 * Context block for the optional language model: published text only, size-capped.
	 *
	 * @param array $pr Profile.
	 * @return array Entries shaped like knowledge-base entries.
	 */
	public static function llm_context( $pr ) {
		$bits = array( 'Product: ' . $pr['name'] );
		foreach ( $pr['comps'] as $c ) {
			$bits[] = 'Composition: ' . $c['name'] . ( $c['generic'] ? ' (' . $c['generic'] . ')' : '' ) . ( $c['class'] ? ', class ' . $c['class'] : '' );
		}
		foreach ( $pr['attrs'] as $k => $v ) {
			$bits[] = $k . ': ' . $v;
		}
		$bits[] = 'Prescription required: ' . ( $pr['rx'] ? 'yes' : 'no' );
		if ( $pr['cold'] ) {
			$bits[] = 'Ships cold-chain: yes';
		}
		foreach ( array( 'short' => 'Summary', 'about' => 'About', 'how' => 'How it works', 'uses' => 'Uses', 'safety' => 'Safety information' ) as $k => $label ) {
			if ( '' !== $pr[ $k ] ) {
				$bits[] = $label . ': ' . self::clip( $pr[ $k ], 900 );
			}
		}
		foreach ( array_slice( $pr['faqs'], 0, 6 ) as $f ) {
			$bits[] = 'FAQ: ' . $f['q'] . ' ' . self::clip( self::plain( $f['a'] ), 300 );
		}
		foreach ( $pr['extra'] as $k => $v ) {
			$bits[] = $k . ': ' . $v;
		}
		return array( array( 'q' => $pr['name'], 'a' => self::clip( implode( "\n", $bits ), 4200 ) ) );
	}

	/**
	 * Answer a product-information question, or null if this message is not one.
	 *
	 * @param string $m Message.
	 * @return array|null
	 */
	public static function reply( $m, $history = array() ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return null;
		}
		$sec = self::section_for( $m );
		if ( '' === $sec ) {
			return null;
		}
		$found = TCCA_Engine::find_products( self::term( $m ), 1 );
		if ( ! $found ) {
			return null;
		}
		$p    = $found[0];
		$pr   = self::profile( $p );
		$card = TCCA_Engine::product_card( $p );
		$name = $pr['name'];
		$open = array( 'type' => 'link', 'label' => __( 'Open product page', 'tcc-assistant' ), 'url' => $pr['url'] );
		$ask  = TCCA_Engine::human_actions( $m );
		$note = __( 'This is the information published on the product page, not advice for your situation. A pharmacist can answer questions about you.', 'tcc-assistant' );

		switch ( $sec ) {
			case 'suggest':
				return self::suggest( $p, $pr );

			case 'uses':
			case 'safety':
				$text  = 'uses' === $sec ? $pr['uses'] : $pr['safety'];
				$label = 'uses' === $sec ? __( 'Uses', 'tcc-assistant' ) : __( 'Safety', 'tcc-assistant' );
				if ( '' === $text ) {
					return TCCA_Engine::out( 'product_info', sprintf( /* translators: 1: tab, 2: product */ __( 'The %1$s section for %2$s has not been added to the product page yet. A pharmacist can help.', 'tcc-assistant' ), $label, $name ), array( 'cards' => array( $card ), 'actions' => $ask, 'answered' => false ) );
				}
				return TCCA_Engine::out( 'product_info', sprintf( __( 'From the %1$s section of %2$s:', 'tcc-assistant' ), $label, $name ) . "\n" . self::clip( $text, 900 ) . "\n\n" . $note, array( 'actions' => array_merge( array( $open ), $ask ), 'chips' => self::topic_chips( $pr ) ) );

			case 'faq':
				return self::faq_reply( $m, $pr, $open, $ask, $card );

			case 'composition':
				if ( ! $pr['comps'] ) {
					return TCCA_Engine::out( 'product_info', sprintf( __( 'No composition is listed for %s yet. A pharmacist can confirm it.', 'tcc-assistant' ), $name ), array( 'cards' => array( $card ), 'actions' => $ask, 'answered' => false ) );
				}
				$lines = array();
				foreach ( $pr['comps'] as $c ) {
					$lines[] = '- ' . $c['name'] . ( $c['generic'] && strcasecmp( $c['generic'], $c['name'] ) ? ' (' . $c['generic'] . ')' : '' ) . ( $c['class'] ? ', ' . $c['class'] : '' );
				}
				return TCCA_Engine::out( 'product_info', sprintf( __( 'Composition of %s:', 'tcc-assistant' ), $name ) . "\n" . implode( "\n", $lines ), array( 'actions' => array( $open ), 'chips' => self::topic_chips( $pr ) ) );

			case 'strengths':
				return self::strengths_reply( $pr, $open, $card );

			case 'storage':
				$t = $pr['cold']
					? sprintf( __( '%s ships in a cold-chain box. Storage instructions are on the pack and the product page; ask a pharmacist if you are unsure.', 'tcc-assistant' ), $name )
					: sprintf( __( 'Storage instructions for %s are printed on the pack. A pharmacist can confirm them for you.', 'tcc-assistant' ), $name );
				return TCCA_Engine::out( 'product_info', $t, array( 'actions' => array_merge( array( $open ), $ask ) ) );

			case 'reviews':
				if ( $pr['reviews'] < 1 ) {
					return TCCA_Engine::out( 'product_info', sprintf( __( '%s has no customer reviews yet.', 'tcc-assistant' ), $name ), array( 'actions' => array( $open ) ) );
				}
				return TCCA_Engine::out( 'product_info', sprintf( /* translators: 1: product 2: rating 3: count */ __( '%1$s is rated %2$s out of 5 from %3$d customer reviews.', 'tcc-assistant' ), $name, number_format_i18n( $pr['rating'], 1 ), $pr['reviews'] ), array( 'actions' => array( array( 'type' => 'link', 'label' => __( 'Read reviews', 'tcc-assistant' ), 'url' => $pr['url'] . '#reviews' ) ) ) );

			case 'rx':
				$a = array( $open );
				if ( $pr['rx'] && ( $u = TCCA_Core::page_url( 'upload-prescription' ) ) ) {
					$a[] = array( 'type' => 'link', 'label' => __( 'Upload prescription', 'tcc-assistant' ), 'url' => $u );
				}
				return TCCA_Engine::out( 'product_info', $pr['rx'] ? sprintf( __( 'Yes, %s needs a prescription.', 'tcc-assistant' ), $name ) : sprintf( __( '%s is not marked as prescription-only on our site.', 'tcc-assistant' ), $name ), array( 'actions' => $a ) );

			case 'about':
			default:
				// A real question about the product that is not just "tell me about it": let the model answer from the page text.
				if ( TCCA_LLM::enabled() && ( str_word_count( $m ) >= 6 || false !== strpos( $m, '?' ) ) && ! preg_match( '/^\s*(tell me|about|info|details)/i', $m ) ) {
					$ans = TCCA_LLM::ask( $m, $history, self::llm_context( $pr ) );
					if ( ! is_wp_error( $ans ) ) {
						return TCCA_Engine::out( 'product_ai', $ans, array( 'ai' => true, 'cards' => array( $card ), 'actions' => array_merge( array( $open ), $ask ) ) );
					}
				}
				return self::about_reply( $pr, $card, $open );
		}
	}

	/**
	 * Short chips for the sections that actually have content.
	 *
	 * @param array $pr Profile.
	 * @return array
	 */
	private static function topic_chips( $pr ) {
		$n    = $pr['name'];
		$out  = array();
		$has  = array(
			array( __( 'Uses', 'tcc-assistant' ), 'uses', '' !== $pr['uses'] ),
			array( __( 'Safety information', 'tcc-assistant' ), 'safety information', '' !== $pr['safety'] ),
			array( __( 'FAQs', 'tcc-assistant' ), 'FAQs', (bool) $pr['faqs'] ),
			array( __( 'Strengths and packs', 'tcc-assistant' ), 'strengths and packs', $pr['strengths'] || $pr['tiers'] || '' !== $pr['pack'] ),
			array( __( 'Same ingredient', 'tcc-assistant' ), 'same ingredient', (bool) $pr['comps'] ),
		);
		foreach ( $has as $h ) {
			if ( $h[2] ) {
				$out[] = array( $h[0], $h[1] . ' of ' . $n );
			}
		}
		return array_slice( $out, 0, 5 );
	}

	private static function about_reply( $pr, $card, $open ) {
		$parts = array();
		$body  = '' !== $pr['about'] ? $pr['about'] : $pr['short'];
		if ( '' !== $body ) {
			$parts[] = self::clip( $body, 450 );
		}
		if ( '' !== $pr['how'] ) {
			$parts[] = __( 'How it works:', 'tcc-assistant' ) . ' ' . self::clip( $pr['how'], 250 );
		}
		$facts = array();
		foreach ( $pr['comps'] as $c ) {
			$facts[] = $c['name'] . ( $c['class'] ? ' (' . $c['class'] . ')' : '' );
		}
		if ( $facts ) {
			$parts[] = __( 'Composition:', 'tcc-assistant' ) . ' ' . implode( ', ', $facts );
		}
		$flags = array();
		if ( $pr['rx'] ) {
			$flags[] = __( 'prescription needed', 'tcc-assistant' );
		}
		if ( $pr['cold'] ) {
			$flags[] = __( 'ships cold-chain', 'tcc-assistant' );
		}
		if ( $pr['imported'] ) {
			$flags[] = __( 'imported', 'tcc-assistant' );
		}
		if ( $flags ) {
			$parts[] = ucfirst( implode( ', ', $flags ) ) . '.';
		}
		if ( ! $parts ) {
			$parts[] = sprintf( __( 'The product page for %s has no description yet.', 'tcc-assistant' ), $pr['name'] );
		}
		return TCCA_Engine::out( 'product_info', implode( "\n", $parts ), array( 'cards' => array( $card ), 'actions' => array( $open ), 'chips' => self::topic_chips( $pr ) ) );
	}

	private static function faq_reply( $m, $pr, $open, $ask, $card ) {
		$faqs = $pr['faqs'];
		if ( ! $faqs ) {
			return TCCA_Engine::out( 'product_info', sprintf( __( 'There are no FAQs on the page for %s yet. A pharmacist can answer your question.', 'tcc-assistant' ), $pr['name'] ), array( 'cards' => array( $card ), 'actions' => $ask, 'answered' => false ) );
		}
		// "Name FAQ: question text" (from a chip) matches exactly; free text picks the FAQ sharing the most words.
		$low  = strtolower( preg_replace( '/\s+/', ' ', $m ) );
		$best = null;
		foreach ( $faqs as $f ) {
			if ( false !== strpos( $low, strtolower( trim( $f['q'] ) ) ) ) {
				$best = $f;
				break;
			}
		}
		if ( ! $best ) {
			$qt  = TCCA_KB::tokens( preg_replace( '/^.*\bfaqs?\s*:/i', '', $m ) );
			$top = 0;
			foreach ( $faqs as $f ) {
				$s = count( array_intersect( $qt, TCCA_KB::tokens( $f['q'] ) ) );
				if ( $s > $top ) {
					$top  = $s;
					$best = $f;
				}
			}
			if ( $top < 2 && ! ( $top >= 1 && count( $qt ) <= 2 ) ) {
				$best = null;
			}
		}
		if ( $best ) {
			return TCCA_Engine::out( 'product_info', $best['q'] . "\n" . self::clip( self::plain( $best['a'] ), 900 ), array( 'actions' => array( $open ), 'chips' => self::topic_chips( $pr ) ) );
		}
		$chips = array();
		foreach ( array_slice( $faqs, 0, 5 ) as $f ) {
			$chips[] = array( mb_strimwidth( $f['q'], 0, 42, '…' ), $pr['name'] . ' FAQ: ' . $f['q'] );
		}
		return TCCA_Engine::out( 'product_info', sprintf( __( 'Questions answered on the %s page. Tap one:', 'tcc-assistant' ), $pr['name'] ), array( 'chips' => $chips, 'actions' => array( $open ) ) );
	}

	private static function strengths_reply( $pr, $open, $card ) {
		$lines = array();
		if ( '' !== $pr['pack'] ) {
			$lines[] = sprintf( __( 'Pack: %s', 'tcc-assistant' ), $pr['pack'] );
		}
		$acts = array( $open );
		if ( $pr['strengths'] ) {
			$names = array();
			foreach ( $pr['strengths'] as $s ) {
				$names[] = $s['label'] . ( $s['stock'] ? '' : ' (' . __( 'out of stock', 'tcc-assistant' ) . ')' );
				if ( ! $s['active'] && count( $acts ) < 5 ) {
					$acts[] = array( 'type' => 'link', 'label' => $s['label'], 'url' => $s['url'] );
				}
			}
			$lines[] = __( 'Strengths available:', 'tcc-assistant' ) . ' ' . implode( ', ', $names );
		}
		if ( $pr['tiers'] ) {
			$lines[] = __( 'Buy more, save more:', 'tcc-assistant' );
			foreach ( $pr['tiers'] as $t ) {
				$lines[] = '- ' . $t['label'] . ': ' . TCCA_Core::money( $t['total'] ) . ( $t['save'] > 0 ? ' (' . sprintf( __( 'save %d%%', 'tcc-assistant' ), (int) $t['save'] ) . ')' : '' );
			}
		}
		if ( ! $lines ) {
			$lines[] = sprintf( __( 'No other strengths or pack options are listed for %s.', 'tcc-assistant' ), $pr['name'] );
		}
		return TCCA_Engine::out( 'product_info', implode( "\n", $lines ), array( 'cards' => array( $card ), 'actions' => $acts ) );
	}

	/**
	 * Same active ingredient first (other brands, other strengths), then the same category.
	 *
	 * @param WC_Product $p  Product.
	 * @param array      $pr Profile.
	 * @return array
	 */
	public static function suggest( $p, $pr ) {
		$ids  = array();
		$why  = '';
		$term = array();
		foreach ( $pr['comps'] as $c ) {
			$term[] = $c['id'];
		}
		if ( $term && taxonomy_exists( 'composition' ) ) {
			$ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 6, 'post__not_in' => array( $pr['id'] ), 'no_found_rows' => true, 'tax_query' => array( array( 'taxonomy' => 'composition', 'field' => 'term_id', 'terms' => $term ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			$why = __( 'Same active ingredient (other brands or strengths).', 'tcc-assistant' ) . ' ' . __( 'Please confirm any substitution with your pharmacist or prescriber.', 'tcc-assistant' );
		}
		if ( count( $ids ) < 4 ) {
			$cats = get_the_terms( $pr['id'], 'product_cat' );
			if ( $cats && ! is_wp_error( $cats ) ) {
				$more = wc_get_products( array( 'status' => 'publish', 'limit' => 6 - count( $ids ), 'exclude' => array_merge( array( $pr['id'] ), $ids ), 'return' => 'ids', 'category' => wp_list_pluck( $cats, 'slug' ) ) );
				if ( $more && ! $ids ) {
					$why = sprintf( __( 'Others in the same category as %s.', 'tcc-assistant' ), $pr['name'] ) . ' ' . __( 'Ask a pharmacist which suits you.', 'tcc-assistant' );
				}
				$ids = array_merge( $ids, $more );
			}
		}
		$cards = array();
		foreach ( array_slice( $ids, 0, 4 ) as $id ) {
			$o = wc_get_product( $id );
			if ( $o && $o->is_visible() ) {
				$cards[] = TCCA_Engine::product_card( $o );
			}
		}
		$acts = array();
		foreach ( $pr['strengths'] as $s ) {
			if ( ! $s['active'] && count( $acts ) < 4 ) {
				$acts[] = array( 'type' => 'link', 'label' => $s['label'], 'url' => $s['url'] );
			}
		}
		if ( ! $cards && ! $acts ) {
			return TCCA_Engine::out( 'suggest', sprintf( __( 'I could not find similar products to %s. A pharmacist may be able to help.', 'tcc-assistant' ), $pr['name'] ), array( 'actions' => TCCA_Engine::human_actions(), 'answered' => false ) );
		}
		return TCCA_Engine::out( 'suggest', $why, array( 'cards' => $cards, 'actions' => array_merge( $acts, TCCA_Engine::human_actions() ) ) );
	}
}
