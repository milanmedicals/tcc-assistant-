<?php
/**
 * Sales assistant: everything here is derived from your own catalogue data (prices, bulk tiers, flags,
 * cross-sell links you set in WooCommerce). It never invents claims, urgency or medical advice.
 *
 * @package TCCAssistant
 */

defined( 'ABSPATH' ) || exit;

class TCCA_Sales {

	/**
	 * Per-unit price formatted with the shop currency, to 3 decimals ("$0.123").
	 */
	private static function unit_price( $v ) {
		$sym = function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol() ) : '';
		return $sym . number_format( (float) $v, 3 );
	}

	/**
	 * The bulk tier with the biggest saving, or null.
	 *
	 * @param WC_Product $p Product.
	 * @return array|null
	 */
	public static function best_tier( $p ) {
		if ( ! function_exists( 'mm_bulk_display_tiers' ) ) {
			return null;
		}
		$best = null;
		foreach ( (array) mm_bulk_display_tiers( $p ) as $t ) {
			if ( $t['save'] > 0 && ( ! $best || $t['save'] > $best['save'] ) ) {
				$best = $t;
			}
		}
		return $best;
	}

	/**
	 * One or two factual selling points for a product card.
	 *
	 * @param WC_Product $p Product.
	 * @return string[]
	 */
	public static function quick_tips( $p ) {
		$tips = array();
		$t    = self::best_tier( $p );
		if ( $t ) {
			$tips[] = sprintf( /* translators: 1: pack label 2: total 3: percent */ __( 'Buy more, save more: %1$s for %2$s (%3$d%% less per unit).', 'tcc-assistant' ), $t['label'], TCCA_Core::money( $t['total'] ), (int) $t['save'] );
		}
		if ( function_exists( 'mm_product_flag' ) ) {
			if ( mm_product_flag( $p, 'rx' ) ) {
				$tips[] = __( 'Needs a prescription. You can upload it any time on the upload page.', 'tcc-assistant' );
			} elseif ( mm_product_flag( $p, 'cold_chain' ) ) {
				$tips[] = __( 'Ships cold-chain.', 'tcc-assistant' );
			}
		}
		return array_slice( $tips, 0, 2 );
	}

	/* --------------------------------------------------------------- compare */

	/**
	 * "Compare Mounjaro and Zepbound": a side-by-side table from catalogue data.
	 *
	 * @param string $m Message.
	 * @return array|null
	 */
	public static function compare( $m ) {
		if ( ! class_exists( 'WooCommerce' ) || ! preg_match( '/\b(compare|comparison|versus|vs\.?|difference between|better than|which (?:is|one is) (?:better|cheaper|best))\b/i', $m ) ) {
			return null;
		}
		$clean = preg_replace( '/\b(compare|comparison|difference between|which (?:is|one is) (?:better|cheaper|best)|please|the|between)\b/i', ' ', $m );
		$parts = preg_split( '/\s+(?:vs\.?|versus|and|with|or|&|than)\s+|\s*,\s*/i', trim( $clean ) );
		$prods = array();
		foreach ( array_slice( array_filter( array_map( 'trim', (array) $parts ) ), 0, 3 ) as $part ) {
			$f = TCCA_Engine::find_products( $part, 1 );
			if ( $f && ! isset( $prods[ $f[0]->get_id() ] ) ) {
				$prods[ $f[0]->get_id() ] = $f[0];
			}
		}
		if ( count( $prods ) < 2 ) {
			return null;
		}
		$prods = array_values( $prods );
		$cols  = array();
		$data  = array();
		foreach ( $prods as $p ) {
			$card   = TCCA_Engine::product_card( $p );
			$attrs  = function_exists( 'mm_card_attrs' ) ? mm_card_attrs( $p ) : array( 'brand' => '', 'strength' => '', 'form' => '', 'pack' => '' );
			$tiers  = function_exists( 'mm_bulk_display_tiers' ) ? mm_bulk_display_tiers( $p ) : array();
			$price  = function_exists( 'mm_price_data' ) ? (float) mm_price_data( $p )['price'] : (float) $p->get_price();
			$data[] = array(
				'price'  => $price,
				'unit'   => ( $tiers && ! empty( $tiers[0]['unit'] ) ) ? (float) $tiers[0]['unit'] : 0.0,
				'off'    => (int) $card['off'],
				'pack'   => $attrs['pack'],
				'str'    => $attrs['strength'],
				'brand'  => $attrs['brand'],
				'rx'     => (bool) $card['rx'],
				'cold'   => function_exists( 'mm_product_flag' ) && mm_product_flag( $p, 'cold_chain' ),
				'stock'  => (bool) $card['stock'],
				'rating' => (float) $p->get_average_rating(),
			);
			$cols[] = array( 'name' => $card['name'], 'url' => $card['url'], 'id' => $card['id'], 'canAdd' => $card['canAdd'] );
		}
		$pick = static function ( $key, $mode ) use ( $data ) {
			$vals = array_column( $data, $key );
			$vals = array_filter( $vals, static function ( $v ) {
				return $v > 0;
			} );
			if ( count( $vals ) < 2 || count( array_unique( $vals ) ) < 2 ) {
				return -1;
			}
			$t = 'min' === $mode ? min( $vals ) : max( $vals );
			return (int) array_search( $t, array_column( $data, $key ), true );
		};
		$rows   = array();
		$rows[] = array( 'label' => __( 'Price', 'tcc-assistant' ), 'vals' => array_map( static function ( $d ) {
			return $d['price'] > 0 ? TCCA_Core::money( $d['price'] ) : '–';
		}, $data ), 'best' => $pick( 'price', 'min' ) );
		$rows[] = array( 'label' => __( 'Per unit', 'tcc-assistant' ), 'vals' => array_map( static function ( $d ) {
			return $d['unit'] > 0 ? self::unit_price( $d['unit'] ) : '–';
		}, $data ), 'best' => $pick( 'unit', 'min' ) );
		$rows[] = array( 'label' => __( 'Off', 'tcc-assistant' ), 'vals' => array_map( static function ( $d ) {
			return $d['off'] > 0 ? $d['off'] . '%' : '–';
		}, $data ), 'best' => $pick( 'off', 'max' ) );
		foreach ( array( 'pack' => __( 'Pack', 'tcc-assistant' ), 'str' => __( 'Strength', 'tcc-assistant' ), 'brand' => __( 'Brand', 'tcc-assistant' ) ) as $k => $label ) {
			$rows[] = array( 'label' => $label, 'vals' => array_map( static function ( $d ) use ( $k ) {
				return '' !== $d[ $k ] ? $d[ $k ] : '–';
			}, $data ), 'best' => -1 );
		}
		$rows[] = array( 'label' => __( 'Rx', 'tcc-assistant' ), 'vals' => array_map( static function ( $d ) {
			return $d['rx'] ? __( 'Required', 'tcc-assistant' ) : __( 'No', 'tcc-assistant' );
		}, $data ), 'best' => -1 );
		$rows[] = array( 'label' => __( 'Cold chain', 'tcc-assistant' ), 'vals' => array_map( static function ( $d ) {
			return $d['cold'] ? __( 'Yes', 'tcc-assistant' ) : '–';
		}, $data ), 'best' => -1 );
		$rows[] = array( 'label' => __( 'In stock', 'tcc-assistant' ), 'vals' => array_map( static function ( $d ) {
			return $d['stock'] ? __( 'Yes', 'tcc-assistant' ) : __( 'No', 'tcc-assistant' );
		}, $data ), 'best' => -1 );
		$rows[] = array( 'label' => __( 'Rating', 'tcc-assistant' ), 'vals' => array_map( static function ( $d ) {
			return $d['rating'] > 0 ? number_format_i18n( $d['rating'], 1 ) . ' / 5' : '–';
		}, $data ), 'best' => $pick( 'rating', 'max' ) );
		// Drop rows with nothing to compare.
		$rows = array_values( array_filter( $rows, static function ( $r ) {
			return count( array_unique( $r['vals'] ) ) > 1 || ( 1 === count( array_unique( $r['vals'] ) ) && '–' !== $r['vals'][0] && in_array( $r['label'], array( 'Price' ), true ) );
		} ) );
		return TCCA_Engine::out( 'compare', __( 'Side by side. A lower price is not always a like-for-like swap, so please confirm any substitution with your pharmacist or prescriber.', 'tcc-assistant' ), array( 'cards' => array( array( 'type' => 'compare', 'cols' => $cols, 'rows' => $rows ) ), 'actions' => TCCA_Engine::human_actions( $m ) ) );
	}

	/* --------------------------------------------------------------- savings */

	/**
	 * Cheaper products with the same active ingredient (never a different medicine).
	 *
	 * @param WC_Product $p Product.
	 * @return WC_Product[] Cheapest first.
	 */
	public static function cheaper_same( $p ) {
		if ( ! function_exists( 'mm_composition_terms' ) || ! taxonomy_exists( 'composition' ) ) {
			return array();
		}
		$ids = array();
		foreach ( mm_composition_terms( $p->get_id() ) as $t ) {
			$ids[] = $t->term_id;
		}
		if ( ! $ids ) {
			return array();
		}
		$mine  = function_exists( 'mm_price_data' ) ? (float) mm_price_data( $p )['price'] : (float) $p->get_price();
		$found = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 20, 'post__not_in' => array( $p->get_id() ), 'no_found_rows' => true, 'tax_query' => array( array( 'taxonomy' => 'composition', 'field' => 'term_id', 'terms' => $ids ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$out   = array();
		foreach ( $found as $id ) {
			$o = wc_get_product( $id );
			if ( ! $o || ! $o->is_visible() || ! $o->is_in_stock() ) {
				continue;
			}
			$pr = function_exists( 'mm_price_data' ) ? (float) mm_price_data( $o )['price'] : (float) $o->get_price();
			if ( $pr > 0 && $pr < $mine ) {
				$out[ $id ] = array( $pr, $o );
			}
		}
		uasort( $out, static function ( $a, $b ) {
			return $a[0] <=> $b[0];
		} );
		return array_values( array_map( static function ( $x ) {
			return $x[1];
		}, $out ) );
	}

	/**
	 * "How can I save?": cart-aware, then product-aware, then general.
	 *
	 * @param string $m Message.
	 * @return array
	 */
	public static function savings( $m ) {
		$lines = array();
		$cards = array();
		$cart  = self::cart_nudges();
		if ( $cart ) {
			$lines[] = __( 'Looking at your cart:', 'tcc-assistant' );
			foreach ( $cart as $n ) {
				$lines[] = '- ' . $n;
			}
		}
		$found = class_exists( 'WooCommerce' ) ? TCCA_Engine::find_products( TCCA_Product::term( $m ), 1 ) : array();
		if ( $found ) {
			$p = $found[0];
			$t = self::best_tier( $p );
			if ( $t ) {
				$lines[] = sprintf( __( '%1$s: %2$s for %3$s is %4$d%% less per unit than the single pack.', 'tcc-assistant' ), wp_strip_all_tags( $p->get_name() ), $t['label'], TCCA_Core::money( $t['total'] ), (int) $t['save'] );
			}
			foreach ( array_slice( self::cheaper_same( $p ), 0, 3 ) as $o ) {
				$cards[] = TCCA_Engine::product_card( $o );
			}
			if ( $cards ) {
				$lines[] = __( 'Lower-priced options with the same active ingredient (please confirm any substitution with your pharmacist):', 'tcc-assistant' );
			}
		}
		$chips = array( array( __( 'It is still too expensive', 'tcc-assistant' ), 'It is too expensive for me' ) );
		if ( ! $lines ) {
			$lines[] = __( 'Two ways to save: larger packs (many medicines are cheaper per unit when you buy more) and the discount shown on each product. Tell me which medicine you need and I will check its options.', 'tcc-assistant' );
		}
		return TCCA_Engine::out( 'savings', implode( "\n", $lines ), array( 'cards' => $cards, 'chips' => $chips ) );
	}

	/**
	 * Savings available on what is in the cart right now: the next bulk tier of each line.
	 *
	 * @return string[]
	 */
	public static function cart_nudges() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return array();
		}
		$out  = array();
		$free = (float) TCCA_Core::get( 'free_ship_over' );
		if ( $free > 0 ) {
			$gap = $free - (float) WC()->cart->get_subtotal();
			if ( $gap > 0.5 ) {
				$out[] = sprintf( /* translators: 1: amount 2: threshold */ __( 'Add %1$s more and your shipping is free (orders over %2$s).', 'tcc-assistant' ), TCCA_Core::money( $gap ), TCCA_Core::money( $free ) );
			}
		}
		if ( ! function_exists( 'mm_bulk_display_tiers' ) ) {
			return $out;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			$p = wc_get_product( $item['product_id'] );
			if ( ! $p ) {
				continue;
			}
			$tiers = (array) mm_bulk_display_tiers( $p );
			$have  = isset( $item['mm_bulk_qty'] ) ? (int) $item['mm_bulk_qty'] : 0;
			$cur   = 0;
			foreach ( $tiers as $t ) {
				if ( (int) $t['qty'] === $have ) {
					$cur = (float) $t['save'];
				}
			}
			foreach ( $tiers as $t ) {
				if ( (int) $t['qty'] > $have && $t['save'] > $cur ) {
					$out[] = sprintf( __( '%1$s: switching to %2$s (%3$s) saves %4$d%% per unit, about %5$s less than buying that quantity at the single-pack price.', 'tcc-assistant' ), wp_strip_all_tags( $p->get_name() ), $t['label'], TCCA_Core::money( $t['total'] ), (int) $t['save'], TCCA_Core::money( max( 0, $t['reg_total'] - $t['total'] ) ) );
					break;
				}
			}
			if ( count( $out ) >= 2 ) {
				break;
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------ cross-sell */

	/**
	 * Products you linked as cross-sells/upsells in WooCommerce, for what is in the cart.
	 *
	 * @return array Product cards (max 2).
	 */
	public static function cross_sell_cards() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return array();
		}
		$in  = array();
		$ids = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			$in[] = (int) $item['product_id'];
			$p    = wc_get_product( $item['product_id'] );
			if ( $p ) {
				$ids = array_merge( $ids, (array) $p->get_cross_sell_ids(), (array) $p->get_upsell_ids() );
			}
		}
		$cards = array();
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			if ( in_array( $id, $in, true ) ) {
				continue;
			}
			$o = wc_get_product( $id );
			if ( $o && $o->is_visible() && $o->is_in_stock() ) {
				$cards[] = TCCA_Engine::product_card( $o );
			}
			if ( count( $cards ) >= 2 ) {
				break;
			}
		}
		return $cards;
	}

	/* --------------------------------------------------------------- reorder */

	/**
	 * Items of an order that can be added to the cart again.
	 *
	 * @param WC_Order $order Order.
	 * @return array<int,array{id:int,vid:int,qty:int}>
	 */
	public static function reorder_items( $order ) {
		$out = array();
		foreach ( $order->get_items() as $it ) {
			$p = $it->get_product();
			if ( $p && $p->is_purchasable() && $p->is_in_stock() ) {
				$out[] = array( 'id' => (int) $it->get_product_id(), 'vid' => (int) $it->get_variation_id(), 'qty' => max( 1, min( 20, (int) $it->get_quantity() ) ) );
			}
			if ( count( $out ) >= 10 ) {
				break;
			}
		}
		return $out;
	}
}
