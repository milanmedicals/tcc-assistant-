<?php
if ( PHP_SAPI !== 'cli' ) { exit; } // Tests run from the command line only.
require __DIR__ . '/bootstrap.php';
load_plugin();

section( 'Lexicon: brands (exact, typo, hyphen), no false positives' );
$f = TCCA_Lexicon::find( 'I want to buy Accutane' );
ok( $f && 'isotretinoin' === $f[0]['generic'] && ! $f[0]['fuzzy'], 'exact accutane' );
$f = TCCA_Lexicon::find( 'i bwant to buy Acutane. the US patented brand' );
ok( $f && 'isotretinoin' === $f[0]['generic'] && $f[0]['fuzzy'], 'typo "Acutane" resolves' );
ok( 'tretinoin' === ( TCCA_Lexicon::find( 'retin-a cream' )[0]['generic'] ?? '' ), 'hyphenated Retin-A' );
ok( 'tirzepatide' === ( TCCA_Lexicon::find( 'price of Mounjaro' )[0]['generic'] ?? '' ), 'Mounjaro' );
ok( 'fluticasone+salmeterol' === ( TCCA_Lexicon::find( 'seretide inhaler' )[0]['generic'] ?? '' ), 'combination' );
$common = array( 'advice', 'allergy', 'clarity', 'protein', 'premium', 'tablets', 'capsules', 'delivery', 'discount', 'medicine', 'pharmacy', 'insulin', 'injection', 'prescription', 'wellness', 'vitamin', 'aspirin', 'weekend', 'complete', 'products', 'shipping', 'payment', 'tracking', 'cheaper', 'sildenaf', 'metformin', 'ibuprofen', 'paracetamol', 'cetirizine' );
$bad = array(); foreach ( $common as $w ) { $r = TCCA_Lexicon::find( "do you have $w please" ); if ( $r ) { $bad[] = "$w=>{$r[0]['brand']}"; } }
ok( ! $bad, 'ordinary words are not mistaken for brands: ' . implode( ',', $bad ) );
ok( 'I want accutane isotretinoin' === TCCA_Lexicon::expand( 'I want accutane' ), 'expand inserts ingredient after brand' );
ok( 'compare accutane isotretinoin vs ozempic semaglutide' === TCCA_Lexicon::expand( 'compare accutane vs ozempic' ), 'expand keeps "vs" split intact' );
ok( TCCA_Lexicon::contains_generic( 'lantus (insulin glargine) pen', 'insulin glargine' ), 'insulin glargine' );
ok( TCCA_Lexicon::contains_generic( 'glargine pen', 'insulin glargine' ), 'glargine alone counts' );
ok( ! TCCA_Lexicon::contains_generic( 'human insulin vial', 'insulin glargine' ), 'other insulin does not' );
ok( ! TCCA_Lexicon::contains_generic( 'adapalene gel', 'adapalene+benzoyl peroxide' ), 'combination needs both' );

section( 'Lexicon: concerns' );
foreach ( array( 'pimple' => 'acne', 'pimples' => 'acne', 'zits' => 'acne', 'hairfall' => 'hair loss', 'blood sugar' => 'diabetes', 'heartburn' => 'acidity', 'lose weight' => 'weight loss' ) as $w => $k ) {
	$c = TCCA_Lexicon::find_concern( "anything for $w" ); ok( $c && $k === $c['label'], "$w => $k" );
}
ok( TCCA_Lexicon::find_concern( 'i feel depressed' )['sensitive'], 'depression is sensitive' );
ok( ! TCCA_Lexicon::find_concern( 'does it ship cold' ), '"ship cold" is not the common cold' );
$GLOBALS['opts']['tcca_settings'] = array( 'aliases' => 'Zorbex, Zorbex Forte = fluticasone', 'concerns' => 'acne = whiteheads2' ); TCCA_Lexicon::reset();
ok( 'fluticasone' === ( TCCA_Lexicon::find( 'zorbex' )[0]['generic'] ?? '' ), 'owner alias' );
ok( in_array( 'whiteheads2', TCCA_Lexicon::concerns()['acne']['phrases'], true ), 'owner concern word merges' );
$GLOBALS['opts']['tcca_settings'] = array(); TCCA_Lexicon::reset();

section( 'Finder: message mode detection' );
$mode = function ( $m ) { $c = TCCA_Lexicon::find_concern( $m ); return $c ? TCCA_Finder::concern_mode( $m, $c ) : 'none'; };
foreach ( array( 'do you have anything for pimple' => 'shop', 'anything for pimples?' => 'shop', 'i have acne' => 'shop', 'acne' => 'shop', 'hair loss treatment' => 'shop', 'show me products for hair loss' => 'shop', 'cheapest options for acne' => 'shop',
	'what should i take for acne' => 'advice', 'best medicine for hair loss' => 'advice', 'how to get rid of pimples' => 'advice',
	'what causes acne' => '', 'why do i get pimples' => '', 'is acne contagious' => '' ) as $m => $want ) { ok( $want === $mode( $m ), "mode($m) = '$want' got '" . $mode( $m ) . "'" ); }
ok( TCCA_Finder::needs_care( 'something for my 2 year old with a fever' ) && TCCA_Finder::needs_care( 'acne cream while pregnant' ) && ! TCCA_Finder::needs_care( 'anything for acne' ), 'child/pregnancy guard' );
ok( '20mg' === TCCA_Finder::strength( 'accutane 20 mg please' ), 'strength' );
ok( TCCA_Finder::refine( 'cheapest acne options' )['cheap'] && TCCA_Finder::refine( 'non-prescription options for acne' )['otc'], 'refine' );

section( 'Concierge: worries, closing, restock, together (and not order tracking)' );
$wk = array( 'how long does delivery take' => 'ship_time', 'how long will it take to deliver to germany' => 'ship_time', 'is delivery free' => 'ship_time', 'will it arrive safely' => 'ship_safe', 'what if it does not arrive' => 'ship_safe', 'will it stay cold in transit' => 'ship_safe', 'customs charges' => 'ship_safe',
	'is it safe to pay online' => 'pay_safe', 'is payment secure' => 'pay_safe', 'my card was declined' => 'pay_fail', 'payment failed at checkout' => 'pay_fail', 'can i pay by paypal' => 'pay_method', 'do you accept upi' => 'pay_method', 'cash on delivery?' => 'pay_method', 'how can i pay' => 'pay_method',
	'where is my order' => '', 'track my order' => '', 'has my order arrived' => '', 'i placed an order and payment failed' => '', 'what is mounjaro' => '' );
foreach ( $wk as $m => $want ) { ok( $want === TCCA_Concierge::worry_kind( $m ), "worry($m) = '$want' got '" . TCCA_Concierge::worry_kind( $m ) . "'" ); }
foreach ( array( "i'll take it" => 'strong', "I'm ready to order" => 'help' === 'x' ? '' : 'strong', 'how do i order' => 'help', 'what is the next step' => 'help', 'hello' => '', 'add it' => '' ) as $m => $want ) { ok( $want === TCCA_Concierge::close_kind( $m ), "close($m)" ); }
ok( TCCA_Concierge::is_restock_ask( 'notify me when mounjaro is back in stock' ) && TCCA_Concierge::is_restock_ask( 'when will it be back in stock' ) && ! TCCA_Concierge::is_restock_ask( 'is it in stock' ), 'restock detection' );
ok( TCCA_Concierge::is_together_ask( 'what do people buy with ozempic' ) && TCCA_Concierge::is_together_ask( 'what else should i get' ) && ! TCCA_Concierge::is_together_ask( 'price of ozempic' ), 'together detection' );

section( 'END-TO-END: "do you have anything for pimple"' );
$r = TCCA_Engine::reply( 'do you have anything for pimple', array(), array( 'session' => 'abc12345' ) );
$names = array_column( $r['cards'], 'name' );
ok( 'concern' === $r['intent'], "intent concern (got {$r['intent']})" );
ok( in_array( 'Clearskin Gel', $names, true ) && in_array( 'Isotretinoin 20mg Capsules (Sotret)', $names, true ), 'finds products by their own Uses text: ' . implode( ' | ', $names ) );
ok( ! in_array( 'Minox 5% Solution', $names, true ), 'does not show unrelated products' );
ok( false !== strpos( $r['cards'][0]['why'], 'Listed use' ), 'each card says why it matched: ' . $r['cards'][0]['why'] );
ok( false !== strpos( $r['reply'], 'not a recommendation' ), 'framed as browsing, not a recommendation' );
$r = TCCA_Engine::reply( 'non-prescription options for acne', array(), array() );
ok( array( 'Clearskin Gel' ) === array_column( $r['cards'], 'name' ), 'non-prescription filter: ' . implode( '|', array_column( $r['cards'], 'name' ) ) );
$r = TCCA_Engine::reply( 'cheapest options for acne', array(), array() );
ok( 'Clearskin Gel' === $r['cards'][0]['name'], 'cheapest first' );
$r = TCCA_Engine::reply( 'what should i take for pimples', array(), array() );
ok( 'concern' === $r['intent'] && false !== strpos( $r['reply'], 'cannot tell you what to take' ) && count( $r['cards'] ) <= 4, 'advice question: no recommendation, still helped' );
$r = TCCA_Engine::reply( 'acne cream for my 3 year old', array(), array() );
ok( 'concern_care' === $r['intent'] && ! $r['cards'], 'child: pharmacist, no cards' );
$r = TCCA_Engine::reply( 'anything for depression', array(), array() );
ok( 'concern_care' === $r['intent'] && ! $r['cards'], 'sensitive concern: no product list' );
$r = TCCA_Engine::reply( 'anything for hair loss', array(), array() );
ok( 'concern' === $r['intent'] && 'Minox 5% Solution' === $r['cards'][0]['name'], 'hair loss finds Minox' );
$r = TCCA_Engine::reply( 'do you have anything for earwax', array(), array() );
ok( 'concern' !== $r['intent'], 'unknown concern is not forced' );

section( 'END-TO-END: ISOTRETINOIN listed, customer asks for Accutane / Acutane' );
foreach ( array( 'I want to buy Accutane', 'i want to buy Acutane. the US patented brand', 'do you have accutane 20mg', 'accutane' ) as $q ) {
	$r = TCCA_Engine::reply( $q, array(), array( 'session' => 'abc12345' ) );
	$names = array_column( $r['cards'], 'name' );
	ok( 'brand' === $r['intent'], "[$q] intent brand (got {$r['intent']})" );
	ok( 2 === count( $names ) && 0 === strpos( $names[0], 'Isotretinoin' ), "[$q] shows isotretinoin products: " . implode( ' | ', $names ) );
	ok( false !== strpos( $r['reply'], 'brand name for isotretinoin' ) && false !== strpos( $r['reply'], 'confirm any switch' ), "[$q] explains the brand/generic link and asks to confirm" );
	ok( ! empty( $r['cards'][0]['rx'] ) && false !== strpos( $r['reply'], 'Rx' ), "[$q] flags prescription" );
}
$r = TCCA_Engine::reply( 'accutane 10mg', array(), array() );
ok( false !== strpos( $r['cards'][0]['name'], '10mg' ), 'asked strength is shown first: ' . $r['cards'][0]['name'] );
$r = TCCA_Engine::reply( 'Acutane', array(), array() );
ok( false !== strpos( $r['reply'], 'I read' ), 'typo is acknowledged' );
$r = TCCA_Engine::reply( 'add accutane to my cart', array(), array() );
ok( 'brand' === $r['intent'] && ! $r['autoadd'], 'adding a brand we do not list never silently adds another product' );
$r = TCCA_Engine::reply( 'side effects of accutane', array(), array() );
ok( 'product_info' === $r['intent'] && false !== strpos( $r['reply'], 'Isotretinoin' ), 'info questions use the ingredient listing + note: ' . substr( $r['reply'], 0, 90 ) );
$r = TCCA_Engine::reply( 'i want to buy ozempic', array(), array( 'session' => 'abc12345' ) );
ok( 'brand_none' === $r['intent'] && 'request' === $r['form'], 'brand with no listed ingredient: honest, offers to source (form=request)' );
$r = TCCA_Engine::reply( 'i want to buy mounjaro', array(), array() );
ok( 'brand' !== $r['intent'], 'a brand the shop lists itself uses the normal flow (intent ' . $r['intent'] . ')' );

section( 'END-TO-END: out-of-stock rescue' );
$r = TCCA_Engine::reply( 'do you have mounjaro 5mg', array(), array( 'session' => 'abc12345' ) );
ok( 'rescue' === $r['intent'], "intent rescue (got {$r['intent']})" );
ok( in_array( 'Tirzepatide 10mg Pen', array_column( $r['cards'], 'name' ), true ), 'shows in-stock same-ingredient option' );
ok( 'restock' === $r['form'] && 14 === $r['fpid'], 'offers back-in-stock email' );
$r = TCCA_Engine::reply( 'add mounjaro 5mg to cart', array(), array() );
ok( 'rescue' === $r['intent'], 'adding an out-of-stock item rescues instead of dead-ending' );
$r = TCCA_Engine::reply( 'notify me when mounjaro is back in stock', array(), array() );
ok( 'rescue' === $r['intent'] && 'restock' === $r['form'], 'explicit restock request' );
$r = TCCA_Engine::reply( 'do you sell zyxwvut 5mg', array(), array() );
ok( 'product_none' === $r['intent'] && 'request' === $r['form'], 'not found: demand captured + request form' );

section( 'END-TO-END: worries answered from real settings' );
$r = TCCA_Engine::reply( 'how can i pay', array(), array() );
ok( 0 === strpos( $r['intent'], 'worry_pay' ), 'payment method question handled (' . $r['intent'] . ')' );
$r = TCCA_Engine::reply( 'is it safe to pay online', array(), array() );
ok( 'worry_pay_safe' === $r['intent'] && false !== strpos( $r['reply'], 'never see or store card details' ), 'payment safety: only true statements' );
$r = TCCA_Engine::reply( 'my card was declined', array(), array() );
ok( 'worry_pay_fail' === $r['intent'], 'payment failure handled' );
$r = TCCA_Engine::reply( 'will it arrive safely', array(), array() );
ok( 'worry_ship_safe' === $r['intent'], 'arrival worry handled' );
$r = TCCA_Engine::reply( 'ready to order', array(), array() );
ok( 'close' === $r['intent'], 'close with empty cart explains the steps (' . $r['intent'] . ')' );
$r = TCCA_Engine::reply( "i'll take it", array(), array( 'pid' => 13 ) );
ok( 'add' === $r['intent'] && ! empty( $r['autoadd'] ) && 13 === $r['autoadd']['id'], 'commitment adds the product in context' );

section( 'Page awareness' );
$p = TCCA_Concierge::validate_page( array( 'type' => 'product', 'id' => 13 ) );
ok( 13 === $p['id'], 'valid product page accepted' );
ok( null === TCCA_Concierge::validate_page( array( 'type' => 'product', 'id' => 99999 ) ) && null === TCCA_Concierge::validate_page( array( 'type' => 'evil', 'id' => 1 ) ), 'invalid page rejected' );
$r = TCCA_Engine::reply( 'is this genuine', array(), array( 'pid' => 13, 'pid_src' => 'page' ) );
ok( 'deal_trust' === $r['intent'] && 'Clearskin Gel' === $r['cards'][0]['name'], 'page product is the subject of "is this genuine"' );
$r = TCCA_Engine::reply( 'what is your return policy for this', array(), array( 'pid' => 13, 'pid_src' => 'page' ) );
ok( false === strpos( $r['reply'], 'Clearskin' ), 'page product is NOT forced into unrelated questions' );

section( 'Safety rails still hold' );
foreach ( array( 'i have chest pain and want something for it' => 'emergency', 'can i take accutane while pregnant' => 'clinical', 'what dose of accutane should i take' => 'clinical' ) as $q => $want ) {
	$r = TCCA_Engine::reply( $q, array(), array() ); ok( $want === $r['intent'], "[$q] => $want (got {$r['intent']})" );
}

echo "\n" . $GLOBALS['passes'] . " passed, " . $GLOBALS['fails'] . " failed\n";
exit( $GLOBALS['fails'] ? 1 : 0 );

section( 'Insights bridge: harmless without the plugin' );
ok( ! TCCA_Insights::available(), 'not available without TCC Insights' );
ok( null === TCCA_Insights::for_you( 'what should i order', array() ), 'for_you returns null so normal routing continues' );
TCCA_Insights::mirror( 'show', array( 'pid' => 1 ) ); ok( true, 'mirror is a silent no-op' );
