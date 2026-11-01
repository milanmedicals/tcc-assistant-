<?php
/**
 * Knowledge base: answers built from the theme's own settings plus entries the owner adds.
 * Retrieval is plain keyword scoring in PHP: no external service, no limits.
 *
 * @package TCCAssistant
 */

defined( 'ABSPATH' ) || exit;

class TCCA_KB {

	/**
	 * Words that carry no meaning for matching.
	 *
	 * @return string[]
	 */
	private static function stop() {
		return array( 'a', 'an', 'the', 'is', 'are', 'was', 'be', 'do', 'does', 'did', 'i', 'me', 'my', 'we', 'you', 'your', 'to', 'of', 'in', 'on', 'for', 'and', 'or', 'it', 'its', 'this', 'that', 'can', 'could', 'would', 'will', 'how', 'what', 'when', 'where', 'which', 'who', 'why', 'with', 'at', 'by', 'from', 'about', 'please', 'tell', 'need', 'want', 'have', 'has', 'get', 'there', 'any', 'if', 'so', 'as', 'am', 'us', 'hi', 'hello', 'help', 'info', 'question', 'questions', 'know', 'something', 'anything', 'happen', 'happens', 'happened', 'gets', 'got', 'getting', 'make', 'makes', 'made', 'thing', 'things', 'stuff', 'let', 'lets', 'like', 'just', 'really', 'very', 'much', 'many', 'mean', 'means', 'meant', 'say', 'says', 'ask', 'asking', 'find', 'finding', 'looking', 'look', 'show', 'give', 'should', 'might', 'also', 'still', 'else', 'someone', 'everything', 'yes', 'okay', 'thanks', 'thank', 'pls', 'want', 'wanted', 'going', 'been', 'being', 'were', 'than', 'then', 'them', 'they', 'their', 'there', 'here', 'out', 'into', 'over', 'such', 'some', 'only', 'own', 'same', 'too', 'now', 'ever', 'anyone', 'one', 'ones', 'tell', 'explain', 'describe', 'define', 'definition', 'meaning', 'detail', 'details', 'information', 'learn', 'read', 'article', 'page', 'overview', 'basics', 'basic', 'whats', 'hows', 'wheres' );
	}

	/**
	 * Words that mean the same thing for matching purposes.
	 *
	 * @return array<string,string>
	 */
	private static function concepts() {
		return array(
			'deliver' => 'ship', 'delivery' => 'ship', 'delivered' => 'ship', 'shipping' => 'ship', 'shipped' => 'ship', 'dispatch' => 'ship', 'courier' => 'ship', 'arrive' => 'ship', 'postage' => 'ship',
			'refund' => 'return', 'refunds' => 'return', 'returns' => 'return', 'returning' => 'return', 'exchange' => 'return', 'replace' => 'return', 'replacement' => 'return',
			'cancel' => 'cancel', 'cancelling' => 'cancel', 'cancellation' => 'cancel', 'cancelled' => 'cancel',
			'pay' => 'pay', 'paying' => 'pay', 'payment' => 'pay', 'payments' => 'pay', 'card' => 'pay', 'upi' => 'pay', 'paypal' => 'pay', 'crypto' => 'pay', 'bank' => 'pay', 'wire' => 'pay',
			'prescription' => 'prescrip', 'prescriptions' => 'prescrip', 'rx' => 'prescrip', 'doctor' => 'prescrip',
			'contact' => 'contact', 'call' => 'contact', 'phone' => 'contact', 'email' => 'contact', 'whatsapp' => 'contact', 'reach' => 'contact', 'support' => 'contact',
			'login' => 'signin', 'signin' => 'signin', 'sign' => 'signin', 'join' => 'signin', 'password' => 'signin', 'otp' => 'signin', 'account' => 'signin',
			'invoice' => 'invoice', 'bill' => 'invoice', 'receipt' => 'invoice',
			'cold' => 'cold', 'fridge' => 'cold', 'refrigerated' => 'cold', 'refrigerate' => 'cold', 'refrigeration' => 'cold', 'refrigerator' => 'cold', 'temperature' => 'cold',
			'hot' => 'heat', 'heat' => 'heat', 'heated' => 'heat', 'warm' => 'heat', 'warmth' => 'heat', 'sunlight' => 'heat', 'sun' => 'heat', 'scorching' => 'heat', 'boiling' => 'heat',
			'cool' => 'cold', 'cooler' => 'cold', 'cooling' => 'cold', 'chilled' => 'cold', 'chill' => 'cold', 'freezing' => 'freeze', 'freeze' => 'freeze', 'frozen' => 'freeze',
			'car' => 'car', 'vehicle' => 'car', 'travel' => 'travel', 'travelling' => 'travel', 'traveling' => 'travel', 'trip' => 'travel', 'flight' => 'travel', 'airport' => 'travel', 'luggage' => 'travel', 'holiday' => 'travel', 'vacation' => 'travel',
			'glucose' => 'glucose', 'sugar' => 'glucose', 'diabetes' => 'diabetes', 'diabetic' => 'diabetes',
			'price' => 'price', 'prices' => 'price', 'pricing' => 'price', 'cost' => 'price', 'costs' => 'price', 'fee' => 'price', 'fees' => 'price', 'charge' => 'price', 'charges' => 'price', 'expensive' => 'price',
			'dose' => 'dose', 'doses' => 'dose', 'dosage' => 'dose', 'dosing' => 'dose', 'week' => 'week', 'weeks' => 'week', 'weekly' => 'week',
			'member' => 'member', 'members' => 'member', 'membership' => 'member', 'club' => 'member',
			'injection' => 'pen', 'injections' => 'pen', 'injectable' => 'pen', 'injectables' => 'pen', 'injector' => 'pen', 'autoinjector' => 'pen',
			'plane' => 'travel', 'airplane' => 'travel', 'aeroplane' => 'travel', 'abroad' => 'travel',
			'medication' => 'medicine', 'medications' => 'medicine', 'medicines' => 'medicine', 'drug' => 'medicine', 'drugs' => 'medicine',
			'safe' => 'safe', 'safely' => 'safe', 'safety' => 'safe', 'keep' => 'store', 'keeping' => 'store', 'kept' => 'store',
			'storage' => 'store', 'stored' => 'store', 'storing' => 'store', 'stores' => 'store',
			'hours' => 'hours', 'open' => 'hours', 'timing' => 'hours', 'timings' => 'hours',
		);
	}

	/**
	 * Tokenise: lowercase, concept-map, light stem, drop stop words.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function tokens( $text, $unique = true ) {
		$text = strtolower( wp_strip_all_tags( (string) $text ) );
		// Multi-word ideas become one token ("about us" would otherwise vanish as two stop words).
		$text = preg_replace( '/\b(about us|about the company|about your company|about the business|who are we|who we are|our story|our company|your company|the company|the business|your business)\b/', ' aboutus ', $text );
		$text = preg_replace( '/\b(blood sugar)\b/', ' glucose ', $text );
		$text = preg_replace( '/\b([a-z]{2,})[\s\-]+(\d{1,2})\b(?!\s*(?:mg|mcg|ml|g|iu|%))/', '$1$2', $text ); // glp-1 / GLP 1 / glp1 are one word
		$raw  = preg_split( '/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$stop = self::stop();
		$map  = self::concepts();
		$out  = array();
		foreach ( $raw as $w ) {
			if ( isset( $map[ $w ] ) ) {
				$out[] = $map[ $w ];
				continue;
			}
			if ( in_array( $w, $stop, true ) ) {
				continue;
			}
			$len = strlen( $w );
			if ( $len > 5 && 'ing' === substr( $w, -3 ) ) {
				$w = substr( $w, 0, -3 );
			} elseif ( $len > 4 && 'es' === substr( $w, -2 ) ) {
				$w = substr( $w, 0, -2 );
			} elseif ( $len > 4 && 'ed' === substr( $w, -2 ) ) {
				$w = substr( $w, 0, -2 );
			} elseif ( $len > 3 && 's' === substr( $w, -1 ) && 'ss' !== substr( $w, -2 ) ) {
				$w = substr( $w, 0, -1 );
			}
			if ( strlen( $w ) > 5 && 'll' === substr( $w, -2 ) ) {
				$w = substr( $w, 0, -1 ); // travelling -> travell -> travel.
			}
			$out[] = isset( $map[ $w ] ) ? $map[ $w ] : $w;
		}
		return $unique ? array_values( array_unique( $out ) ) : $out;
	}

	/**
	 * Every entry: built-in (from the theme and WooCommerce) then the owner's own.
	 *
	 * @return array<int,array{q:string,a:string,link:array,own:bool}>
	 */
	public static function entries() {
		$site = TCCA_Core::site();
		$opt  = static function ( $key ) {
			return function_exists( 'mm_opt' ) ? trim( (string) mm_opt( $key ) ) : '';
		};
		$e = array();

		// Delivery times: only when the theme has them configured.
		$std = $opt( 'ship_std_eta' );
		$exp = $opt( 'ship_exp_eta' );
		if ( $std || $exp ) {
			$a = array();
			if ( $std ) {
				$a[] = sprintf( /* translators: 1: time, 2: detail */ __( 'Standard shipping: %1$s%2$s.', 'tcc-assistant' ), $std, $opt( 'ship_std_detail' ) ? ' (' . $opt( 'ship_std_detail' ) . ')' : '' );
			}
			if ( $exp ) {
				$a[] = sprintf( __( 'Express shipping: %1$s%2$s.', 'tcc-assistant' ), $exp, $opt( 'ship_exp_detail' ) ? ' (' . $opt( 'ship_exp_detail' ) . ')' : '' );
			}
			$a[] = __( 'The exact options and prices for your country are shown at checkout.', 'tcc-assistant' );
			$e[] = array( 'q' => 'shipping delivery time how long days arrive express standard cost courier', 'a' => implode( ' ', $a ), 'link' => array(), 'own' => false );
		}
		if ( $opt( 'pharmacy_note' ) ) {
			$e[] = array( 'q' => 'cold chain refrigerated temperature fridge insulin shipping worldwide countries cities', 'a' => $opt( 'pharmacy_note' ), 'link' => array(), 'own' => false );
		}

		// Returns and cancellation: the exact wording the customer sees at checkout.
		$final = $opt( 'commit_final_text' );
		if ( $final ) {
			$days = $opt( 'return_days' ) ?: '7';
			$e[]  = array(
				'q'    => 'return refund cancel cancellation exchange money back policy',
				'a'    => str_replace( array( '{days}', '{company}' ), array( $days, $site['company'] ), $final ),
				'link' => ( $url = TCCA_Core::page_url( 'returns' ) ) ? array( __( 'Returns policy', 'tcc-assistant' ), $url ) : array(),
				'own'  => false,
			);
		}

		// Payment methods: whatever WooCommerce has switched on.
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			$names = array();
			foreach ( WC()->payment_gateways()->get_available_payment_gateways() as $gw ) {
				$names[] = wp_strip_all_tags( $gw->get_title() );
			}
			if ( ! $names ) {
				foreach ( WC()->payment_gateways()->payment_gateways() as $gw ) {
					if ( 'yes' === $gw->enabled ) {
						$names[] = wp_strip_all_tags( $gw->get_title() );
					}
				}
			}
			$names = array_values( array_unique( array_filter( $names ) ) );
			if ( $names ) {
				$e[] = array( 'q' => 'payment pay methods card paypal crypto bank wire upi how to pay accept', 'a' => sprintf( __( 'You can pay by: %s. Payment instructions are shown at checkout.', 'tcc-assistant' ), implode( ', ', $names ) ), 'link' => array(), 'own' => false );
			}
		}

		$rx = TCCA_Core::page_url( 'upload-prescription' );
		$e[] = array(
			'q'    => 'prescription rx upload doctor need required how send',
			'a'    => __( 'You can upload your prescription (PDF, JPG or PNG) on the upload page. Medicines marked Rx need one, and a pharmacist checks it before your order is packed.', 'tcc-assistant' ),
			'link' => $rx ? array( __( 'Upload prescription', 'tcc-assistant' ), $rx ) : array(),
			'own'  => false,
		);

		$e[] = array(
			'q'    => 'sign in login join club account password verification code otp email magic link not received',
			'a'    => __( 'There is no password to remember. Choose “Join the club”, enter your email, then type the 6-digit code we send you (or tap the link in the email).', 'tcc-assistant' ),
			'link' => array(),
			'own'  => false,
		);

		$e[] = array(
			'q'    => 'invoice bill receipt download pdf tax',
			'a'    => __( 'Open My account, then My orders, and choose Download invoice on the order.', 'tcc-assistant' ),
			'link' => function_exists( 'wc_get_page_permalink' ) ? array( __( 'My account', 'tcc-assistant' ), wc_get_page_permalink( 'myaccount' ) ) : array(),
			'own'  => false,
		);

		$e[] = array(
			'q'    => 'coupon promo code voucher discount offer apply',
			'a'    => __( 'You can add a coupon code in your cart before checkout. If it is not accepted, the message there will say why.', 'tcc-assistant' ),
			'link' => function_exists( 'wc_get_cart_url' ) ? array( __( 'Go to cart', 'tcc-assistant' ), wc_get_cart_url() ) : array(),
			'own'  => false,
		);

		$pap = TCCA_Core::page_url( 'patient-assistance' );
		if ( $pap ) {
			$e[] = array( 'q' => 'patient assistance program pap help afford discount support cost', 'a' => __( 'Our Patient Assistance Program page explains who can apply and how.', 'tcc-assistant' ), 'link' => array( __( 'Patient assistance', 'tcc-assistant' ), $pap ), 'own' => false );
		}

		// Contact details.
		$bits = array();
		if ( $site['phone'] ) {
			$bits[] = sprintf( __( 'call %s', 'tcc-assistant' ), $site['phone'] );
		}
		if ( $site['whatsapp'] ) {
			$bits[] = __( 'WhatsApp us', 'tcc-assistant' );
		}
		if ( $site['email'] ) {
			$bits[] = sprintf( __( 'email %s', 'tcc-assistant' ), $site['email'] );
		}
		if ( $bits ) {
			$e[] = array( 'q' => 'contact phone call whatsapp email support hours open reach talk', 'a' => sprintf( __( 'You can %1$s.%2$s', 'tcc-assistant' ), implode( ', ', $bits ), $site['hours'] ? ' ' . $site['hours'] . '.' : '' ), 'link' => array(), 'own' => false );
		}

		// Owner's own entries: "Question | Answer | optional link".
		foreach ( preg_split( '/\r\n|\r|\n/', TCCA_Core::get( 'kb' ) ) as $line ) {
			$p = array_map( 'trim', explode( '|', $line ) );
			if ( count( $p ) < 2 || '' === $p[0] || '' === $p[1] ) {
				continue;
			}
			$link = ( ! empty( $p[2] ) && wp_http_validate_url( $p[2] ) ) ? array( __( 'Read more', 'tcc-assistant' ), esc_url_raw( $p[2] ) ) : array();
			$e[]  = array( 'q' => $p[0], 'a' => $p[1], 'link' => $link, 'own' => true );
		}
		return $e;
	}

	/**
	 * Best matches for a question.
	 *
	 * @param string $query Visitor text.
	 * @param int    $limit How many.
	 * @return array<int,array{score:float,entry:array}> Highest first; only entries with some overlap.
	 */
	public static function entry_id( $entry ) {
		return substr( md5( $entry['q'] ), 0, 12 );
	}

	public static function search( $query, $limit = 3 ) {
		$qt = self::tokens( $query );
		if ( ! $qt ) {
			return array();
		}
		$hits = array();
		foreach ( self::entries() as $entry ) {
			$title = self::tokens( $entry['q'] );
			$body  = self::tokens( $entry['a'] );
			$hq    = count( array_intersect( $qt, $title ) );
			$ha    = count( array_intersect( $qt, $body ) );
			if ( ! $hq && ! $ha ) {
				continue;
			}
			$score = $hq + 0.3 * $ha + ( $entry['own'] ? 0.4 : 0 );
			// A one-word question ("returns?") that hits the entry's own words is good enough.
			if ( 1 === $hq && count( $qt ) <= 2 ) {
				$score = max( $score, 2.0 );
			}
			$hits[] = array( 'score' => $score, 'entry' => $entry, 'cov' => $hq / count( $qt ) );
		}
		usort(
			$hits,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);
		return array_slice( $hits, 0, $limit );
	}
}
