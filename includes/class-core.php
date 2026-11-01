<?php
/**
 * Core: settings, install, front-end loader, shared helpers.
 *
 * @package TCCAssistant
 */

defined( 'ABSPATH' ) || exit;

class TCCA_Core {

	const OPT    = 'tcca_settings';
	const DB_VER = '3';

	/**
	 * Default settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'enabled'        => '1',
			'name'           => 'Chemist Assistant',
			'welcome'        => 'Hi! I can find a medicine, check an order, or answer questions about delivery, returns and payments. For anything about how to take a medicine, I will connect you with a pharmacist.',
			'quick'          => "Track my order|Where is my order?\nFind a medicine|I am looking for a medicine\nShipping and delivery|How long does delivery take?\nReturns and refunds|What is your return policy?\nUpload prescription|How do I upload my prescription?\nTalk to a pharmacist|I want to talk to a pharmacist",
			'privacy_note'   => 'Please do not share card details or medical records in this chat.',
			'hide_checkout'  => '',
			'replace_theme'  => '1',
			'teaser'         => '1',
			'phone'          => '',
			'whatsapp'       => '',
			'email'          => '',
			'hours'          => '',
			'handoff_email'  => '',
			'kb'             => '',
			'llm_on'         => '',
			'llm_preset'     => 'ollama',
			'llm_url'        => 'http://localhost:11434/v1',
			'llm_model'      => '',
			'llm_key'        => '',
			'llm_url2'       => '',
			'llm_model2'     => '',
			'llm_key2'       => '',
			'llm_cap'        => '300',
			'log_on'         => '1',
			'coupon_on'      => '',
			'coupon_pct'     => '5',
			'coupon_days'    => '7',
			'coupon_min'     => '0',
			'coupon_cap_day' => '30',
			'coupon_lock_days' => '30',
			'coupon_pct_ret' => '3',
			'coupon_big_pct' => '',
			'coupon_big_min' => '',
			'free_ship_over' => '',
			'value_points'   => "Every product we supply is authentic and genuine.\nYour order is tracked from dispatch to delivery, with support if anything goes wrong.",
			'coupon_prefix'  => 'CHAT',
			'coupon_excl'    => '',
			'coupon_show'    => '1',
			'coupon_new_only' => '',
			'llm_persona'    => '',
			'idx_on'         => '1',
			'idx_posts'      => '1',
			'idx_pages'      => '1',
			'idx_products'   => '1',
			'idx_cats'       => '1',
			'idx_cpts'       => '',
			'idx_exclude'    => '',
			'retention_days' => '30',
			'page_on'        => '1',
			'together_on'    => '1',
			'restock_on'     => '1',
			'attr_on'        => '1',
			'aliases'        => '',
			'concerns'       => '',
			'delivery_facts' => '',
			'payment_facts'  => '',
		);
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function all() {
		$saved = get_option( self::OPT, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? (string) $all[ $key ] : '';
	}

	/**
	 * Hooks.
	 */
	public static function boot() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'tcca_purge', array( __CLASS__, 'purge' ) );
		add_action( 'init', array( __CLASS__, 'maybe_upgrade' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'wp_head', array( __CLASS__, 'swap_css' ), 1 );
		add_action( 'wp_footer', array( __CLASS__, 'failsafe' ), 99 );
	}

	/**
	 * CSS selectors of the theme's own chat button(s) that the assistant replaces. Empty = replace nothing.
	 *
	 * @return string
	 */
	public static function replaced_selectors() {
		if ( '1' !== self::get( 'replace_theme' ) ) {
			return '';
		}
		return trim( (string) apply_filters( 'tcca_replace_selectors', '.chat-fab' ) );
	}

	/**
	 * Mark the page server-side so the theme's chat button is already gone on first paint (no flash of two buttons).
	 *
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public static function body_class( $classes ) {
		if ( self::active() && '' !== self::replaced_selectors() ) {
			$classes[] = 'tcca-active';
		}
		return $classes;
	}

	/**
	 * Hide the theme's chat button while the assistant is in charge. The `tcca-failed` class (added when the
	 * assistant cannot start) switches this off again, so the visitor never ends up with no chat at all.
	 */
	public static function swap_css() {
		$sel = self::replaced_selectors();
		if ( ! self::active() || '' === $sel ) {
			return;
		}
		$parts = array();
		foreach ( explode( ',', $sel ) as $one ) {
			if ( '' !== trim( $one ) ) {
				$parts[] = 'body.tcca-active:not(.tcca-failed) ' . trim( $one );
			}
		}
		$list = implode( ',', $parts );
		echo '<style id="tcca-swap">' . $list . '{display:none!important}</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * If the assistant has not started a few seconds after the page loaded (script blocked, broken by an
	 * optimiser, stylesheet missing), give the theme's chat button back.
	 */
	public static function failsafe() {
		if ( ! self::active() || '' === self::replaced_selectors() ) {
			return;
		}
		echo '<script>setTimeout(function(){if(!window.tccaReady){document.body.classList.add("tcca-failed")}},6000);</script>' . "\n";
	}

	/**
	 * Create the log table and schedule the daily purge.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = $wpdb->prefix . 'tcca_log';
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created datetime NOT NULL,
				session varchar(32) NOT NULL DEFAULT '',
				intent varchar(24) NOT NULL DEFAULT '',
				answered tinyint(1) NOT NULL DEFAULT 1,
				msg varchar(300) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY created (created),
				KEY intent (intent)
			) {$charset};"
		);
		TCCA_Index::install();
		TCCA_Events::install();
		TCCA_Leads::install();
		update_option( 'tcca_db_ver', self::DB_VER );
		if ( ! get_option( 'tcca_built' ) ) {
			TCCA_Index::schedule_full();
		}
		if ( ! wp_next_scheduled( 'tcca_purge' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'tcca_purge' );
		}
	}

	/**
	 * Remove the schedule on deactivation (data stays until uninstall).
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'tcca_purge' );
	}

	/**
	 * Re-run install when the schema version changes (plugin updated by file copy).
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'tcca_db_ver' ) !== self::DB_VER ) {
			self::install();
		}
	}

	/**
	 * Delete log rows older than the retention window.
	 */
	public static function purge() {
		global $wpdb;
		$days = max( 1, (int) self::get( 'retention_days' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}tcca_log WHERE created < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Is the widget shown on this request?
	 *
	 * @return bool
	 */
	public static function active() {
		if ( is_admin() || '1' !== self::get( 'enabled' ) ) {
			return false;
		}
		if ( '1' === self::get( 'hide_checkout' ) && function_exists( 'is_checkout' ) && is_checkout() ) {
			return false;
		}
		// The theme shows no chat button on its focused pages (checkout, signed-out account): do the same.
		if ( function_exists( 'mm_is_bare' ) && mm_is_bare() && apply_filters( 'tcca_follow_theme_bare', true ) ) {
			return false;
		}
		return (bool) apply_filters( 'tcca_enabled', true );
	}

	/**
	 * Widget stylesheet, lightly minified and cached. It is sent inline so the assistant never depends on a
	 * second request that a cache, optimiser or security plugin could block or rewrite.
	 *
	 * @return string
	 */
	private static function css_text() {
		$file = TCCA_DIR . 'assets/assistant.css';
		if ( ! is_readable( $file ) ) {
			return '';
		}
		$key = 'tcca_css_' . md5( TCCA_VERSION . filemtime( $file ) );
		$css = get_transient( $key );
		if ( ! is_string( $css ) || '' === $css ) {
			$css = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$css = preg_replace( '#/\*.*?\*/#s', '', $css );
			$css = preg_replace( '/\s+/', ' ', $css );
			$css = trim( preg_replace( '/\s*([{};,])\s*/', '$1', $css ) );
			set_transient( $key, $css, DAY_IN_SECONDS );
		}
		return $css;
	}

	/**
	 * Load the widget.
	 */
	public static function enqueue() {
		if ( ! self::active() ) {
			return;
		}
		$ver = TCCA_VERSION;
		wp_enqueue_script( 'tcca', TCCA_URL . 'assets/assistant.js', array(), $ver, array( 'in_footer' => true, 'strategy' => 'defer' ) );

		$chips = array();
		foreach ( preg_split( '/\r\n|\r|\n/', self::get( 'quick' ) ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line, 2 ) );
			if ( '' !== $parts[0] ) {
				$chips[] = array( $parts[0], isset( $parts[1] ) && '' !== $parts[1] ? $parts[1] : $parts[0] );
			}
		}
		$mark = '';
		if ( file_exists( get_theme_file_path( 'assets/img/logo-mark.svg' ) ) ) {
			$mark = get_theme_file_uri( 'assets/img/logo-mark.svg' );
		}
		$data = array(
			'rest'    => esc_url_raw( rest_url( 'tcca/v1/' ) ),
			'css'     => TCCA_URL . 'assets/assistant.css?ver=' . $ver, // Fallback only.
			'cssText' => self::css_text(),
			'replace' => self::replaced_selectors(),
			// Only for signed-in visitors: the theme sends no-store for those pages, so the nonce is never stale.
			'nonce'   => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'name'    => self::get( 'name' ),
			'welcome' => self::get( 'welcome' ),
			'chips'   => $chips,
			'privacy' => self::get( 'privacy_note' ),
			'teaser'  => '1' === self::get( 'teaser' ),
			'mark'    => $mark,
			'cartUrl' => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '',
			'checkoutUrl' => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '',
			'loggedIn' => is_user_logged_in(),
			'page'    => TCCA_Concierge::page(),
			'attr'    => '1' === self::get( 'attr_on' ),
		);
		wp_localize_script( 'tcca', 'tccaData', $data );
	}

	/**
	 * Contact details: plugin settings win, then the TheChemistClub theme options, then WordPress.
	 *
	 * @return array<string,string>
	 */
	public static function site() {
		$theme = static function ( $key ) {
			return function_exists( 'mm_opt' ) ? (string) mm_opt( $key ) : '';
		};
		$phone    = self::get( 'phone' ) ?: $theme( 'phone' );
		$whatsapp = preg_replace( '/\D+/', '', self::get( 'whatsapp' ) ?: $theme( 'whatsapp' ) );
		$email    = self::get( 'email' ) ?: ( $theme( 'email_support' ) ?: $theme( 'email' ) );
		$hours    = self::get( 'hours' ) ?: ( function_exists( 'mm_support_hours' ) ? (string) mm_support_hours() : $theme( 'support_hours' ) );
		return array(
			'company'  => $theme( 'company' ) ?: get_bloginfo( 'name' ),
			'phone'    => $phone,
			'tel'      => $phone ? 'tel:' . preg_replace( '/[^\d+]/', '', $phone ) : '',
			'whatsapp' => $whatsapp ? 'https://wa.me/' . $whatsapp : '',
			'email'    => $email,
			'hours'    => $hours,
		);
	}

	/**
	 * A theme page URL by slug, or ''.
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	public static function page_url( $slug ) {
		if ( function_exists( 'mm_page_url' ) ) {
			return (string) mm_page_url( $slug );
		}
		$page = get_page_by_path( $slug );
		return $page ? get_permalink( $page ) : '';
	}

	/**
	 * Client IP (uses the theme's trusted-proxy aware helper when present).
	 *
	 * @return string
	 */
	public static function ip() {
		if ( function_exists( 'mm_client_ip' ) ) {
			return (string) mm_client_ip();
		}
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/**
	 * Fixed-window rate limit per IP. Returns false once the limit is hit.
	 *
	 * @param string $bucket Name.
	 * @param int    $max    Allowed hits.
	 * @param int    $window Seconds.
	 * @return bool
	 */
	public static function rate_ok( $bucket, $max, $window ) {
		$key = 'tcca_rl_' . md5( $bucket . '|' . self::ip() );
		$n   = (int) get_transient( $key );
		if ( $n >= $max ) {
			return false;
		}
		set_transient( $key, $n + 1, $window );
		return true;
	}

	/**
	 * Strip things that identify a person before text is stored.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function redact( $text ) {
		$text = preg_replace( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[email]', (string) $text );
		$text = preg_replace( '/\+?\d[\d\s().\-]{6,}\d/', '[number]', $text );
		$text = preg_replace( '/#?\b\d{4,}\b/', '[number]', $text );
		return $text;
	}

	/**
	 * Store one visitor message (redacted) so the owner can see what the assistant could not answer.
	 *
	 * @param string $session  Session id.
	 * @param string $intent   Intent.
	 * @param string $message  Visitor message.
	 * @param bool   $answered Whether the assistant had an answer.
	 */
	public static function log( $session, $intent, $message, $answered ) {
		if ( '1' !== self::get( 'log_on' ) ) {
			return;
		}
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB
			$wpdb->prefix . 'tcca_log',
			array(
				'created'  => gmdate( 'Y-m-d H:i:s' ),
				'session'  => substr( preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $session ) ), 0, 32 ),
				'intent'   => substr( sanitize_key( $intent ), 0, 24 ),
				'answered' => $answered ? 1 : 0,
				'msg'      => mb_substr( self::redact( $message ), 0, 300 ),
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Plain-text money.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	public static function money( $amount ) {
		if ( ! function_exists( 'wc_price' ) ) {
			return number_format_i18n( (float) $amount, 2 );
		}
		return trim( html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' ) );
	}
}
