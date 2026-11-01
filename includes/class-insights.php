<?php
/**
 * Bridge to the TCC Insights plugin (read-mostly, optional).
 *
 * The assistant stays a separate plugin with its own log. When TCC Insights is active it can also
 * READ what that plugin knows about the visitor (usual medicines, refill timing, picks) to answer
 * "what should I order?", and it WRITES one-line chat events so each customer's 360 timeline shows chat activity.
 * If TCC Insights is off, every method here returns nothing and the assistant behaves as before.
 *
 * @package TCCAssistant
 */

defined( 'ABSPATH' ) || exit;

class TCCA_Insights {

	/** @return bool */
	public static function available() {
		return function_exists( 'tcci_customer_context' ) && function_exists( 'tcci_active' ) && tcci_active();
	}

	/**
	 * Mirror a chat moment into the Insights timeline (types: show, add, checkout, miss, brand, concern, restock).
	 *
	 * @param string $type Assistant event type.
	 * @param array  $a    pid, ref, qty.
	 */
	public static function mirror( $type, array $a ) {
		if ( ! self::available() || ! function_exists( 'tcci_track' ) || ! in_array( $type, array( 'show', 'add', 'checkout', 'miss', 'brand', 'concern', 'restock' ), true ) ) {
			return;
		}
		tcci_track( 'chat_' . $type, array( 'obj' => isset( $a['pid'] ) ? (int) $a['pid'] : 0, 'qty' => isset( $a['qty'] ) ? (int) $a['qty'] : 0, 'term' => isset( $a['ref'] ) ? mb_substr( (string) $a['ref'], 0, 120 ) : '' ) );
	}

	/**
	 * Answer "my usuals", "do I need a refill?", "what should I order?" from the customer's own history.
	 * Returns null for anything else, so normal routing continues.
	 *
	 * @param string $m   Message.
	 * @param array  $ctx Page context.
	 * @return array|null
	 */
	public static function for_you( $m, array $ctx = array() ) {
		unset( $ctx );
		if ( ! self::available() ) {
			return null;
		}
		$usuals = (bool) preg_match( '/\b(my usual(?:s)?|usual (?:medicines?|order|ones)|what do i (?:usually|normally|regularly) (?:order|buy|get|take)|things i (?:usually|always) (?:order|buy))\b/i', $m );
		$refill = (bool) preg_match( '/\b(?:(?:my|do i|am i|when).{0,24}\brefill|time to (?:refill|reorder|restock)|running (?:low|out)|(?:am i|do i).{0,14}(?:due|need).{0,14}(?:refill|reorder|restock))\b/i', $m );
		$reco   = (bool) preg_match( '/\b(?:recommend|suggest)\w* (?:me |something |anything )?(?:for me|i (?:might|may) like|based on)|\bwhat (?:should|can) i (?:order|reorder|buy|get)(?: next| today| now)?\b|\bpicked for me\b/i', $m );
		if ( ! $usuals && ! $refill && ! $reco ) {
			return null;
		}
		$c   = tcci_customer_context();
		$ids = array();
		$why = '';
		$msg = '';
		if ( ( $usuals || $refill ) && ! empty( $c['usuals'] ) ) {
			$ids = array_slice( $c['usuals'], 0, 4 );
			$why = __( 'You order this regularly', 'tcc-assistant' );
			if ( $refill && ! empty( $c['refill_due'] ) ) {
				$days = (int) round( ( $c['refill_due'] - time() ) / DAY_IN_SECONDS );
				if ( $days > 7 ) {
					/* translators: %d: days */
					$msg = sprintf( __( 'Going by how often you order, your next refill is in about %d days. Here is what you usually order, if you would like it sooner:', 'tcc-assistant' ), $days );
				} elseif ( $days >= 0 ) {
					/* translators: %d: days */
					$msg = sprintf( __( 'Going by how often you order, you will probably need a refill in about %d days. Your usual medicines:', 'tcc-assistant' ), $days );
				} else {
					$msg = __( 'Going by how often you order, you may be due a refill. Your usual medicines:', 'tcc-assistant' );
				}
			} else {
				$msg = __( 'These are the medicines you order most often:', 'tcc-assistant' );
			}
		} elseif ( $reco && ! empty( $c['known'] ) && ! empty( $c['picks'] ) ) {
			$ids = array_slice( $c['picks'], 0, 4 );
			$why = __( 'Matches what you buy and browse', 'tcc-assistant' );
			$msg = __( 'Based on what you have ordered and looked at, you might like these:', 'tcc-assistant' );
		} elseif ( function_exists( 'tcci_trending_products' ) ) {
			$ids = tcci_trending_products( 4 );
			$why = __( 'Popular right now', 'tcc-assistant' );
			$msg = ! empty( $c['known'] )
				? __( 'I do not have enough of your order history to personalise this yet. These are popular right now:', 'tcc-assistant' )
				: __( 'Sign in and I can show your usual medicines and refill timing. For now, these are popular right now:', 'tcc-assistant' );
		}
		$cards = array();
		foreach ( $ids as $id ) {
			$p = wc_get_product( (int) $id );
			if ( $p && $p->is_visible() ) {
				$cards[] = TCCA_Engine::product_card( $p, $why );
			}
		}
		if ( ! $cards ) {
			return null;
		}
		$msg .= "\n" . __( 'This is based on your orders, not medical advice. Ask your pharmacist before changing how you take any medicine.', 'tcc-assistant' );
		return TCCA_Engine::out( 'for_you', $msg, array( 'cards' => $cards, 'chips' => TCCA_Engine::default_chips_public() ) );
	}
}
