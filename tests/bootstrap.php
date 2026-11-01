<?php
if ( PHP_SAPI !== 'cli' ) { exit; } // Tests run from the command line only.
/**
 * Test harness: minimal stand-ins for WordPress and WooCommerce so the assistant's own logic
 * (language, search, routing, replies) can be run from the command line: php tests/run.php
 * This is NOT WordPress. It cannot prove MySQL FULLTEXT, theme functions or real checkout behave; run a staging pass.
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 ); define( 'HOUR_IN_SECONDS', 3600 ); define( 'MINUTE_IN_SECONDS', 60 );
define( 'TCCA_DIR', dirname( __DIR__ ) . '/' ); define( 'TCCA_VERSION', 'test' ); define( 'TCCA_FILE', TCCA_DIR . 'tcc-assistant.php' );
define( 'ARRAY_A', 'ARRAY_A' );
class WooCommerce {}
class WP_Error { public $c; function __construct( $c = '', $m = '' ) { $this->c = $c; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function __( $s ) { return $s; } function esc_html__( $s ) { return $s; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_email( $s ) { return trim( (string) $s ); } function is_email( $s ) { return (bool) filter_var( $s, FILTER_VALIDATE_EMAIL ); }
function absint( $n ) { return abs( (int) $n ); } function wp_unslash( $s ) { return $s; }
function number_format_i18n( $n, $d = 0 ) { return number_format( $n, $d ); }
function get_bloginfo() { return 'Test Shop'; } function home_url( $p = '' ) { return 'https://shop.test' . $p; }
function add_query_arg( $a, $u ) { return $u . '?' . http_build_query( $a ); }
function get_permalink( $id ) { return 'https://shop.test/p/' . $id; }
function wp_get_attachment_image_url() { return ''; }
function get_term_meta() { return ''; } function get_term_link() { return 'https://shop.test/cat'; }
function is_user_logged_in() { return false; } function current_user_can() { return false; } function get_current_user_id() { return 0; }
function is_ssl() { return true; } function wp_date( $f, $t ) { return date( $f, $t ); }
function wp_json_encode( $x ) { return json_encode( $x ); } function wp_salt() { return 's'; }
function apply_filters( $t, $v ) { return $v; } function add_action() {} function add_filter() {}
function page_url_stub() { return ''; }
$GLOBALS['opts'] = array(); $GLOBALS['trans'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; }
function get_transient( $k ) { return array_key_exists( $k, $GLOBALS['trans'] ) ? $GLOBALS['trans'][ $k ] : false; }
function set_transient( $k, $v ) { $GLOBALS['trans'][ $k ] = $v; } function delete_transient( $k ) { unset( $GLOBALS['trans'][ $k ] ); }
function mb_strimwidth_stub() {}
function get_page_by_path() { return null; }
// ---- fake catalogue ----
class WC_Product {
	public $d;
	function __construct( $d ) { $this->d = $d; }
	function get_id() { return $this->d['id']; } function get_name() { return $this->d['name']; }
	function get_price() { return $this->d['price']; } function get_regular_price() { return $this->d['price']; }
	function is_type( $t ) { return 'simple' === $t; } function get_image_id() { return 0; }
	function is_purchasable() { return true; } function is_in_stock() { return ! empty( $this->d['stock'] ); }
	function is_visible() { return true; } function get_total_sales() { return $this->d['sales'] ?? 0; }
	function get_parent_id() { return 0; } function get_cross_sell_ids() { return array(); } function get_upsell_ids() { return array(); }
	function get_review_count() { return 0; } function get_average_rating() { return 0; }
	function get_sku() { return ''; } function get_short_description() { return ''; } function get_description() { return ''; } function get_attributes() { return array(); }
}
$GLOBALS['cat'] = array(
	array( 'id' => 11, 'name' => 'Isotretinoin 20mg Capsules (Sotret)', 'price' => 30, 'stock' => 1, 'comp' => 'Isotretinoin', 'rx' => 1, 'uses' => 'Used for severe acne that has not responded to other treatment.' ),
	array( 'id' => 12, 'name' => 'Isotretinoin 10mg Capsules', 'price' => 22, 'stock' => 1, 'comp' => 'Isotretinoin', 'rx' => 1, 'uses' => 'Used for severe acne.' ),
	array( 'id' => 13, 'name' => 'Clearskin Gel', 'price' => 9, 'stock' => 1, 'comp' => 'Benzoyl peroxide', 'rx' => 0, 'uses' => 'Helps clear pimples and blackheads. Apply to affected skin.' ),
	array( 'id' => 14, 'name' => 'Mounjaro 5mg Pen', 'price' => 300, 'stock' => 0, 'comp' => 'Tirzepatide', 'rx' => 1, 'uses' => 'Used for type 2 diabetes and weight management.' ),
	array( 'id' => 15, 'name' => 'Tirzepatide 10mg Pen', 'price' => 280, 'stock' => 1, 'comp' => 'Tirzepatide', 'rx' => 1, 'uses' => 'Used for weight management.' ),
	array( 'id' => 16, 'name' => 'Minox 5% Solution', 'price' => 14, 'stock' => 1, 'comp' => 'Minoxidil', 'rx' => 0, 'uses' => 'Used to treat hair loss and promote regrowth.' ),
);
function cat_find( $id ) { foreach ( $GLOBALS['cat'] as $r ) { if ( $r['id'] === (int) $id ) { return $r; } } return null; }
function wc_get_product( $id ) { $r = cat_find( is_object( $id ) ? $id->get_id() : $id ); return $r ? new WC_Product( $r ) : false; }
function wc_get_products( $a ) {
	$out = array();
	foreach ( $GLOBALS['cat'] as $r ) {
		if ( isset( $a['s'] ) ) {
			$hit = false;
			foreach ( preg_split( '/\s+/', strtolower( $a['s'] ) ) as $w ) { if ( strlen( $w ) >= 3 && false !== strpos( strtolower( $r['name'] . ' ' . $r['comp'] ), $w ) ) { $hit = true; } }
			if ( ! $hit ) { continue; }
		}
		$out[] = ( ( $a['return'] ?? '' ) === 'ids' ) ? $r['id'] : new WC_Product( $r );
	}
	return array_slice( $out, 0, $a['limit'] ?? 50 );
}
function taxonomy_exists( $t ) { return 'composition' === $t; }
function get_terms( $a ) {
	if ( ( $a['taxonomy'] ?? '' ) === 'composition' && isset( $a['search'] ) ) {
		$ids = array(); foreach ( $GLOBALS['cat'] as $r ) { if ( false !== stripos( $r['comp'], $a['search'] ) ) { $ids[ crc32( $r['comp'] ) ] = crc32( $r['comp'] ); } }
		return array_values( $ids );
	}
	return array();
}
function get_posts( $a ) {
	$ids = array();
	foreach ( $a['tax_query'] ?? array() as $tq ) { foreach ( $GLOBALS['cat'] as $r ) { if ( in_array( crc32( $r['comp'] ), $tq['terms'], true ) ) { $ids[] = $r['id']; } } }
	return $ids;
}
function mm_product_flag( $p, $f ) { $r = cat_find( is_object( $p ) ? $p->get_id() : $p ); return 'rx' === $f && $r && ! empty( $r['rx'] ); }
// ---- fake database: index passages ----
class FakeDB {
	public $prefix = 'wp_'; public $rows = array(); public $inserted = array();
	function prepare( $q, ...$a ) { if ( 1 === count( $a ) && is_array( $a[0] ) ) { $a = $a[0]; } return vsprintf( str_replace( array( '%s', '%d', '%f' ), array( "'%s'", '%d', '%f' ), $q ), $a ); }
	function get_results( $q, $o = null ) {
		if ( false !== strpos( $q, "type = 'product'" ) && preg_match( "/AGAINST \('([^']*)' IN BOOLEAN/", $q, $m ) ) {
			$need = array_filter( explode( ' +', ltrim( $m[1], '+' ) ) ); $out = array();
			foreach ( $this->rows as $r ) { $ok = true; foreach ( $need as $t ) { if ( false === strpos( ' ' . $r['terms'] . ' ', ' ' . $t . ' ' ) ) { $ok = false; } } if ( $ok ) { $out[] = $r; } }
			return $out;
		}
		return array();
	}
	function get_var() { return 0; } function insert( $t, $d ) { $this->inserted[] = array( $t, $d ); return 1; } function query() { return 1; }
}
$GLOBALS['wpdb'] = new FakeDB();
function load_plugin() {
	foreach ( array( 'core', 'kb', 'llm', 'product', 'index', 'lexicon', 'track', 'insights', 'sales', 'coupons', 'deal', 'account', 'concierge', 'finder', 'engine' ) as $f ) { require_once TCCA_DIR . "includes/class-$f.php"; }
	// Index the fake catalogue the way the real indexer does (Uses and description text, tokenised).
	global $wpdb;
	foreach ( $GLOBALS['cat'] as $r ) {
		$wpdb->rows[] = array( 'obj_id' => $r['id'], 'part' => 'uses', 'body' => $r['uses'], 'terms' => implode( ' ', TCCA_KB::tokens( $r['uses'], false ) ) );
		$wpdb->rows[] = array( 'obj_id' => $r['id'], 'part' => 'about', 'body' => $r['name'] . ', ' . $r['comp'], 'terms' => implode( ' ', TCCA_KB::tokens( $r['name'] . ' ' . $r['comp'], false ) ) );
	}
}
$GLOBALS['fails'] = 0; $GLOBALS['passes'] = 0;
function ok( $cond, $label ) { if ( $cond ) { $GLOBALS['passes']++; } else { $GLOBALS['fails']++; echo "  FAIL: $label\n"; } }
function section( $t ) { echo "\n== $t\n"; }
function get_post_meta( $id, $k = '', $single = false ) { if ( '' === $k ) { return array(); } $r = cat_find( $id ); return ( '_mm_how_it_works' === $k ) ? '' : ''; }
function get_the_terms() { return false; }
function wc_attribute_label( $s ) { return $s; }
function is_serialized() { return false; }
function mm_product_info( $id ) { $r = cat_find( $id ); return array( 'uses' => $r['uses'] ?? '', 'safety' => 'Do not use during pregnancy. Prescription medicine.', 'faqs' => array() ); }
function wc_get_product_terms() { return array(); }
