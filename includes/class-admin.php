<?php
/**
 * Admin: Settings → Shop Assistant (Settings, Knowledge base, AI model, Insights).
 *
 * @package TCCAssistant
 */

defined( 'ABSPATH' ) || exit;

class TCCA_Admin {

	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( TCCA_FILE ), array( __CLASS__, 'links' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_post_tcca_add_kb', array( __CLASS__, 'add_kb' ) );
	}

	public static function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=tcca' ) ) . '">' . esc_html__( 'Settings', 'tcc-assistant' ) . '</a>' );
		return $links;
	}

	public static function notices() {
		if ( ! class_exists( 'WooCommerce' ) && current_user_can( 'manage_options' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'TCC Shop Assistant: WooCommerce is not active, so product search and order tracking are switched off. The FAQ and pharmacist hand-off still work.', 'tcc-assistant' ) . '</p></div>';
		}
	}

	public static function menu() {
		add_options_page( __( 'Shop Assistant', 'tcc-assistant' ), __( 'Shop Assistant', 'tcc-assistant' ), 'manage_options', 'tcca', array( __CLASS__, 'page' ) );
	}

	public static function register() {
		register_setting( 'tcca', TCCA_Core::OPT, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	/**
	 * Sanitise everything on save. Each tab posts only its own fields, so keep the rest.
	 *
	 * @param mixed $in Posted values.
	 * @return array
	 */
	public static function sanitize( $in ) {
		$old  = TCCA_Core::all();
		$in   = is_array( $in ) ? $in : array();
		$text = array( 'name', 'phone', 'whatsapp', 'hours', 'privacy_note', 'llm_preset', 'llm_model', 'llm_model2', 'idx_cpts', 'idx_exclude', 'coupon_prefix', 'coupon_excl' );
		$area = array( 'welcome', 'quick', 'kb', 'llm_persona', 'value_points', 'aliases', 'concerns', 'delivery_facts', 'payment_facts' );
		$bool = array( 'enabled', 'hide_checkout', 'replace_theme', 'teaser', 'llm_on', 'log_on', 'idx_on', 'idx_posts', 'idx_pages', 'idx_products', 'idx_cats', 'coupon_on', 'coupon_show', 'coupon_new_only', 'page_on', 'together_on', 'restock_on', 'attr_on' );
		$tab  = isset( $in['_tab'] ) ? sanitize_key( $in['_tab'] ) : '';
		$out  = $old;
		$map  = array(
			'general' => array( 'enabled', 'name', 'welcome', 'quick', 'privacy_note', 'hide_checkout', 'replace_theme', 'teaser', 'phone', 'whatsapp', 'email', 'hours', 'handoff_email', 'log_on', 'retention_days' ),
			'kb'      => array( 'kb' ),
			'sales'   => array( 'coupon_on', 'coupon_pct', 'coupon_days', 'coupon_min', 'coupon_cap_day', 'coupon_prefix', 'coupon_excl', 'coupon_show', 'coupon_new_only', 'coupon_lock_days', 'coupon_pct_ret', 'coupon_big_pct', 'coupon_big_min', 'free_ship_over', 'value_points' ),
			'smart'   => array( 'page_on', 'together_on', 'restock_on', 'attr_on', 'aliases', 'concerns', 'delivery_facts', 'payment_facts' ),
			'index'   => array( 'idx_on', 'idx_posts', 'idx_pages', 'idx_products', 'idx_cats', 'idx_cpts', 'idx_exclude' ),
			'ai'      => array( 'llm_on', 'llm_preset', 'llm_url', 'llm_model', 'llm_key', 'llm_cap', 'llm_url2', 'llm_model2', 'llm_key2', 'llm_persona' ),
		);
		if ( ! isset( $map[ $tab ] ) ) {
			return $old;
		}
		foreach ( $map[ $tab ] as $k ) {
			$v = isset( $in[ $k ] ) ? wp_unslash( $in[ $k ] ) : '';
			if ( in_array( $k, $bool, true ) ) {
				$out[ $k ] = $v ? '1' : '';
			} elseif ( in_array( $k, $area, true ) ) {
				$out[ $k ] = sanitize_textarea_field( $v );
			} elseif ( in_array( $k, $text, true ) ) {
				$out[ $k ] = sanitize_text_field( $v );
			} elseif ( 'email' === $k || 'handoff_email' === $k ) {
				$out[ $k ] = sanitize_email( $v );
			} elseif ( 'llm_url' === $k || 'llm_url2' === $k ) {
				$out[ $k ] = esc_url_raw( trim( $v ), array( 'http', 'https' ) );
			} elseif ( 'coupon_pct' === $k ) {
				$out[ $k ] = (string) min( 50, max( 1, (int) $v ) );
			} elseif ( in_array( $k, array( 'coupon_days', 'coupon_cap_day' ), true ) ) {
				$out[ $k ] = (string) min( 365, max( 1, (int) $v ) );
			} elseif ( 'coupon_min' === $k ) {
				$out[ $k ] = (string) max( 0, (float) $v );
			} elseif ( 'coupon_lock_days' === $k ) {
				$out[ $k ] = (string) min( 365, max( 1, (int) $v ) );
			} elseif ( in_array( $k, array( 'coupon_pct_ret', 'coupon_big_pct' ), true ) ) {
				$out[ $k ] = '' === trim( (string) $v ) ? '' : (string) min( 50, max( 0, (int) $v ) );
			} elseif ( in_array( $k, array( 'coupon_big_min', 'free_ship_over' ), true ) ) {
				$out[ $k ] = '' === trim( (string) $v ) ? '' : (string) max( 0, (float) $v );
			} elseif ( 'llm_cap' === $k ) {
				$out[ $k ] = (string) min( 100000, max( 0, (int) $v ) );
			} elseif ( 'retention_days' === $k ) {
				$out[ $k ] = (string) min( 365, max( 1, (int) $v ) );
			} elseif ( 'llm_key' === $k || 'llm_key2' === $k ) {
				// Blank keeps the saved key; the "remove" box clears it.
				$out[ $k ] = ! empty( $in[ $k . '_clear' ] ) ? '' : ( '' !== trim( $v ) ? sanitize_text_field( $v ) : $old[ $k ] );
			}
		}
		if ( 'smart' === $tab ) {
			update_option( 'tcca_settings', $out );
			TCCA_Lexicon::reset();
			delete_transient( 'tcca_together' );
		}
		if ( 'index' === $tab ) {
			update_option( 'tcca_settings', $out ); // So the rebuild sees the new choices.
			TCCA_Index::schedule_full();
		}
		return $out;
	}

	private static function field( $label, $name, $type = 'text', $hint = '', $extra = '' ) {
		$val = TCCA_Core::get( $name );
		$id  = 'tcca-' . $name;
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		$n = esc_attr( TCCA_Core::OPT . '[' . $name . ']' );
		if ( 'checkbox' === $type ) {
			echo '<label><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . $n . '" value="1" ' . checked( '1', $val, false ) . '> ' . esc_html( $hint ) . '</label>';
			$hint = '';
		} elseif ( 'textarea' === $type ) {
			echo '<textarea id="' . esc_attr( $id ) . '" name="' . $n . '" rows="6" class="large-text code">' . esc_textarea( $val ) . '</textarea>';
		} elseif ( 'password' === $type ) {
			echo '<input type="password" autocomplete="new-password" id="' . esc_attr( $id ) . '" name="' . $n . '" class="regular-text" placeholder="' . esc_attr( '' !== $val || defined( 'TCCA_LLM_KEY' ) ? '•••••••• (saved)' : '' ) . '">';
		} else {
			echo '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . $n . '" value="' . esc_attr( $val ) . '" class="regular-text" ' . $extra . '>';
		}
		if ( $hint ) {
			echo '<p class="description">' . wp_kses_post( $hint ) . '</p>';
		}
		echo '</td></tr>';
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification
		$tabs = array( 'general' => __( 'General', 'tcc-assistant' ), 'kb' => __( 'Your answers', 'tcc-assistant' ), 'sales' => __( 'Sales and coupons', 'tcc-assistant' ), 'smart' => __( 'Smart sales', 'tcc-assistant' ), 'index' => __( 'Knowledge index', 'tcc-assistant' ), 'ai' => __( 'AI model (optional)', 'tcc-assistant' ), 'insights' => __( 'Insights', 'tcc-assistant' ) );
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'general';
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Shop Assistant', 'tcc-assistant' ) . '</h1><nav class="nav-tab-wrapper">';
		foreach ( $tabs as $k => $label ) {
			echo '<a class="nav-tab' . ( $k === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'options-general.php?page=tcca&tab=' . $k ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		if ( 'insights' === $tab ) {
			self::insights();
			echo '</div>';
			return;
		}
		echo '<form method="post" action="options.php">';
		settings_fields( 'tcca' );
		echo '<input type="hidden" name="' . esc_attr( TCCA_Core::OPT ) . '[_tab]" value="' . esc_attr( $tab ) . '"><table class="form-table" role="presentation">';
		if ( 'general' === $tab ) {
			self::field( __( 'Show the assistant', 'tcc-assistant' ), 'enabled', 'checkbox', __( 'On', 'tcc-assistant' ) );
			self::field( __( 'Assistant name', 'tcc-assistant' ), 'name' );
			self::field( __( 'Welcome message', 'tcc-assistant' ), 'welcome', 'textarea' );
			self::field( __( 'Quick replies', 'tcc-assistant' ), 'quick', 'textarea', __( 'One per line: <code>Button label | Message it sends</code>. The first four also appear after greetings.', 'tcc-assistant' ) );
			self::field( __( 'Privacy line', 'tcc-assistant' ), 'privacy_note' );
			self::field( __( 'Greeting bubble', 'tcc-assistant' ), 'teaser', 'checkbox', __( 'Show a small “Need help?” bubble once per visit', 'tcc-assistant' ) );
			self::field( __( 'Theme chat button', 'tcc-assistant' ), 'replace_theme', 'checkbox', __( 'Replace the theme’s own chat button with this assistant (the theme’s button returns automatically if the assistant is switched off or cannot start)', 'tcc-assistant' ) );
			self::field( __( 'Checkout page', 'tcc-assistant' ), 'hide_checkout', 'checkbox', __( 'Hide the assistant on checkout', 'tcc-assistant' ) );
			echo '</table><h2>' . esc_html__( 'Contact details', 'tcc-assistant' ) . '</h2><p>' . esc_html__( 'Leave blank to use the values from your TheChemistClub theme settings.', 'tcc-assistant' ) . '</p><table class="form-table" role="presentation">';
			self::field( __( 'Phone', 'tcc-assistant' ), 'phone' );
			self::field( __( 'WhatsApp number', 'tcc-assistant' ), 'whatsapp', 'text', __( 'Digits with country code, e.g. 919911661996', 'tcc-assistant' ) );
			self::field( __( 'Support email', 'tcc-assistant' ), 'email', 'email' );
			self::field( __( 'Support hours', 'tcc-assistant' ), 'hours' );
			self::field( __( 'Extra copy of pharmacist messages to', 'tcc-assistant' ), 'handoff_email', 'email', __( 'Optional. Messages are always saved in your Pharmacy inbox (or emailed to the site admin without the theme).', 'tcc-assistant' ) );
			echo '</table><h2>' . esc_html__( 'Privacy', 'tcc-assistant' ) . '</h2><table class="form-table" role="presentation">';
			self::field( __( 'Keep a log', 'tcc-assistant' ), 'log_on', 'checkbox', __( 'Store visitor questions (emails, phone numbers and order numbers are removed first) to power the Insights tab', 'tcc-assistant' ) );
			self::field( __( 'Delete log after (days)', 'tcc-assistant' ), 'retention_days', 'number', '', 'min="1" max="365"' );
		} elseif ( 'kb' === $tab ) {
			echo '<tr><td colspan="2"><p>' . wp_kses_post( __( 'The assistant already answers shipping times, returns, payment methods, prescriptions, sign-in, invoices and contact questions from your theme and WooCommerce settings. Add your own answers here, <strong>one per line</strong>: <code>Question | Answer | Optional link</code>', 'tcc-assistant' ) ) . '</p></td></tr>';
			self::field( __( 'Your answers', 'tcc-assistant' ), 'kb', 'textarea', __( 'Example: <code>Do you sell insulin? | Yes, we stock several insulin brands. Use the search to see availability. | https://example.com/shop</code><br>Only write facts you can stand behind. Never put dosing or treatment advice here.', 'tcc-assistant' ) );
			echo '<tr><td colspan="2"><script>document.getElementById("tcca-kb").rows=18;</script></td></tr>';
		} elseif ( 'sales' === $tab ) {
			echo '<tr><td colspan="2"><p>' . wp_kses_post( __( 'The assistant already acts as a salesperson using <strong>your own data</strong>: bulk-tier savings, lower-priced options with the same active ingredient, side-by-side comparisons, add-to-cart and checkout in chat, reorder, cross-sells you set in WooCommerce, and emailing a cart or order update. It never makes up urgency, claims or medical advice.', 'tcc-assistant' ) ) . '</p><h2>' . esc_html__( 'One-time discount codes', 'tcc-assistant' ) . '</h2><p>' . wp_kses_post( __( 'When a customer asks for a discount, it can create a real WooCommerce coupon inside the limits below. <strong>Off by default.</strong> Each code is single-use, locked to the customer\'s email, expires, and is emailed to them. One code per email per 30 days; a per-device limit and a daily cap stop abuse. Check your local rules on discounting prescription medicines and use <em>Exclude categories</em> if needed.', 'tcc-assistant' ) ) . '</p></td></tr>';
			self::field( __( 'Allow discount codes', 'tcc-assistant' ), 'coupon_on', 'checkbox', __( 'On', 'tcc-assistant' ) );
			self::field( __( 'Discount %', 'tcc-assistant' ), 'coupon_pct', 'number', __( 'Maximum 50. The standard code for a customer with a price concern. The assistant never goes above this (or the bigger % below, if you set one).', 'tcc-assistant' ), 'min="1" max="50"' );
			self::field( __( 'Valid for (days)', 'tcc-assistant' ), 'coupon_days', 'number', '', 'min="1" max="365"' );
			self::field( __( 'Minimum spend', 'tcc-assistant' ), 'coupon_min', 'number', __( '0 = no minimum. Enforced by WooCommerce when the code is used.', 'tcc-assistant' ), 'min="0" step="0.01"' );
			self::field( __( 'Codes per day (whole shop)', 'tcc-assistant' ), 'coupon_cap_day', 'number', __( 'Stops it giving out more than this many new codes a day.', 'tcc-assistant' ), 'min="1"' );
			self::field( __( 'One code per customer every (days)', 'tcc-assistant' ), 'coupon_lock_days', 'number', __( 'Stops anyone, including returning customers, collecting codes again and again. Matches the person, not just the exact email: dots and +tags in Gmail addresses count as the same inbox. Default 30. Shop staff are exempt so you can test.', 'tcc-assistant' ), 'min="1" max="365"' );
			self::field( __( 'Returning customers get (%)', 'tcc-assistant' ), 'coupon_pct_ret', 'number', __( 'A smaller discount for people who have ordered before (they already trust you). 0 = no chat code for returning customers. Leave empty to give them the same %.', 'tcc-assistant' ), 'min="0" max="50"' );
			self::field( __( 'Spend more, save more: bigger %', 'tcc-assistant' ), 'coupon_big_pct', 'number', __( 'Optional. A higher % that applies only when the order reaches the amount below. The assistant offers both and nudges the customer to add more. Leave empty to turn off.', 'tcc-assistant' ), 'min="1" max="50"' );
			self::field( __( '…when the order reaches', 'tcc-assistant' ), 'coupon_big_min', 'number', __( 'Order value for the bigger %. The code is locked to this minimum spend.', 'tcc-assistant' ), 'min="0" step="0.01"' );
			self::field( __( 'Free shipping over', 'tcc-assistant' ), 'free_ship_over', 'number', __( 'Optional. If you offer free shipping above an amount, enter it and the assistant will tell customers how much more to add. Leave empty if you do not.', 'tcc-assistant' ), 'min="0" step="0.01"' );
			self::field( __( 'Why our prices (your selling points)', 'tcc-assistant' ), 'value_points', 'textarea', __( 'One per line. The assistant says these when a customer finds something expensive or doubts it is genuine. Only write what is true for your shop. It also adds facts from each product automatically (cold-chain, imported, prescription, reviews, % below MRP).', 'tcc-assistant' ) );
			self::field( __( 'Code prefix', 'tcc-assistant' ), 'coupon_prefix' );
			self::field( __( 'Exclude categories', 'tcc-assistant' ), 'coupon_excl', 'text', __( 'Product category slugs or IDs, comma-separated. Items in these categories are not discounted by these codes.', 'tcc-assistant' ) );
			self::field( __( 'First orders only', 'tcc-assistant' ), 'coupon_new_only', 'checkbox', __( 'Only for emails with no previous order', 'tcc-assistant' ) );
			self::field( __( 'Show the code in chat', 'tcc-assistant' ), 'coupon_show', 'checkbox', __( 'Show it in the chat as well as emailing it (turn off to email only)', 'tcc-assistant' ) );
		} elseif ( 'smart' === $tab ) {
			echo '<tr><td colspan="2"><p>' . wp_kses_post( __( 'Everything here works from <strong>your own catalogue, settings and order history</strong>. Nothing is invented, and it never recommends a medicine for a condition: it shows products whose own pages say what they are used for.', 'tcc-assistant' ) ) . '</p></td></tr>';
			self::field( __( 'Read the page', 'tcc-assistant' ), 'page_on', 'checkbox', __( 'Open with help that fits the page (product, category, cart, search) and understand “this medicine”', 'tcc-assistant' ) );
			self::field( __( 'Bought together', 'tcc-assistant' ), 'together_on', 'checkbox', __( 'Suggest what customers actually order together (counted from completed and processing orders; product ids only; never pushes an Rx item at a cart with none)', 'tcc-assistant' ) );
			self::field( __( 'Back in stock', 'tcc-assistant' ), 'restock_on', 'checkbox', __( 'Offer to email a visitor once when an out-of-stock product returns (stores their email until sent)', 'tcc-assistant' ) );
			self::field( __( 'Measure sales', 'tcc-assistant' ), 'attr_on', 'checkbox', __( 'Count orders placed after a chat. Sets a first-party cookie (a random id, 7 days) once a visitor uses the chat. Mention it in your cookie notice. Shop staff are not counted.', 'tcc-assistant' ) );
			self::field( __( 'Brand names you sell', 'tcc-assistant' ), 'aliases', 'textarea', __( 'One per line: <code>Brand, Other brand = active ingredient</code>, e.g. <code>Accutane, Roaccutane = isotretinoin</code>. A customer who types a brand you do not list is shown products with that ingredient. Common brands are built in; yours win. Use <code>+</code> for combinations: <code>Seretide = fluticasone+salmeterol</code>.', 'tcc-assistant' ) );
			self::field( __( 'Extra concern words', 'tcc-assistant' ), 'concerns', 'textarea', __( 'One per line: <code>concern = word, word</code>, e.g. <code>acne = zits, breakouts</code>. Start a line with ! to hand a concern to a pharmacist instead of listing products. Words only: which products appear is decided by the text on your product pages.', 'tcc-assistant' ) );
			self::field( __( 'Delivery facts', 'tcc-assistant' ), 'delivery_facts', 'textarea', __( 'One per line, only what is true, e.g. “Orders ship from Mumbai within 24 hours.” Added to delivery and “will it arrive safely?” answers.', 'tcc-assistant' ) );
			self::field( __( 'Payment facts', 'tcc-assistant' ), 'payment_facts', 'textarea', __( 'One per line, only what is true, e.g. “Card payments are handled by Stripe.” Added to payment answers.', 'tcc-assistant' ) );
		} elseif ( 'index' === $tab ) {
			echo '<tr><td colspan="2"><p>' . wp_kses_post( __( 'The assistant builds its own searchable database from your site: <strong>products</strong> (description, how it works, Uses, Safety, each FAQ, composition), <strong>categories</strong>, <strong>blog posts</strong> and <strong>pages</strong>. It answers by quoting the best passage with a link to the source. No outside AI is needed. It updates by itself whenever you save content.', 'tcc-assistant' ) ) . '</p></td></tr>';
			self::field( __( 'Answer from the index', 'tcc-assistant' ), 'idx_on', 'checkbox', __( 'On', 'tcc-assistant' ) );
			self::field( __( 'Blog posts', 'tcc-assistant' ), 'idx_posts', 'checkbox', __( 'Index', 'tcc-assistant' ) );
			self::field( __( 'Pages', 'tcc-assistant' ), 'idx_pages', 'checkbox', __( 'Index (cart, checkout and account pages are always skipped)', 'tcc-assistant' ) );
			self::field( __( 'Products', 'tcc-assistant' ), 'idx_products', 'checkbox', __( 'Index', 'tcc-assistant' ) );
			self::field( __( 'Product categories', 'tcc-assistant' ), 'idx_cats', 'checkbox', __( 'Index', 'tcc-assistant' ) );
			self::field( __( 'Other post types', 'tcc-assistant' ), 'idx_cpts', 'text', __( 'Comma-separated slugs, e.g. <code>faq,guide</code>', 'tcc-assistant' ) );
			self::field( __( 'Leave out', 'tcc-assistant' ), 'idx_exclude', 'text', __( 'Page/post IDs or slugs, comma-separated. Password-protected, private and “noindex” content is always left out.', 'tcc-assistant' ) );
			echo '</table>';
			submit_button( __( 'Save and rebuild', 'tcc-assistant' ) );
			$st = TCCA_Index::stats();
			echo '<h2>' . esc_html__( 'Index status', 'tcc-assistant' ) . '</h2><p>' . esc_html( sprintf( __( '%1$d passages. Last full build: %2$s.', 'tcc-assistant' ), $st['rows'], $st['built'] ? wp_date( 'j M Y H:i', $st['built'] ) : __( 'never (it builds in the background after activation)', 'tcc-assistant' ) ) ) . '</p>';
			if ( $st['types'] ) {
				echo '<table class="widefat striped" style="max-width:420px"><tbody>';
				foreach ( $st['types'] as $r ) {
					echo '<tr><td>' . esc_html( $r->type ) . '</td><td>' . (int) $r->n . ' ' . esc_html__( 'items', 'tcc-assistant' ) . ' / ' . (int) $r->c . ' ' . esc_html__( 'passages', 'tcc-assistant' ) . '</td></tr>';
				}
				echo '</tbody></table>';
			}
			$nonce = wp_create_nonce( 'tcca_admin' );
			echo '<p><button type="button" class="button button-primary" id="tcca-rb">' . esc_html__( 'Rebuild now', 'tcc-assistant' ) . '</button> <progress id="tcca-rbp" value="0" max="1" style="display:none;width:240px;vertical-align:middle"></progress> <span id="tcca-rbt"></span></p>';
			echo '<h2>' . esc_html__( 'Test a question', 'tcc-assistant' ) . '</h2><p><input type="text" id="tcca-tq" class="regular-text" placeholder="' . esc_attr__( 'e.g. how do I store my insulin pen?', 'tcc-assistant' ) . '"> <button type="button" class="button" id="tcca-tb">' . esc_html__( 'What does the index find?', 'tcc-assistant' ) . '</button></p><div id="tcca-tr"></div>';
			echo '<script>(function(){var N=' . wp_json_encode( $nonce ) . ',U=window.ajaxurl;function post(b){return fetch(U,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:b+"&_ajax_nonce="+encodeURIComponent(N)}).then(function(r){return r.json();});}
var rb=document.getElementById("tcca-rb"),bar=document.getElementById("tcca-rbp"),t=document.getElementById("tcca-rbt");
function step(o){post("action=tcca_rebuild&offset="+o).then(function(j){if(!j.success){t.textContent="Failed";rb.disabled=false;return;}var d=j.data;bar.max=Math.max(d.total,1);bar.value=Math.min(d.next,d.total);t.textContent=Math.min(d.next,d.total)+" / "+d.total;if(d.done){t.textContent="Done: "+d.total+" items checked.";rb.disabled=false;}else{step(d.next);}}).catch(function(){t.textContent="Failed";rb.disabled=false;});}
rb.addEventListener("click",function(){rb.disabled=true;bar.style.display="inline-block";step(0);});
var tb=document.getElementById("tcca-tb"),tq=document.getElementById("tcca-tq"),tr=document.getElementById("tcca-tr");
tb.addEventListener("click",function(){post("action=tcca_test&q="+encodeURIComponent(tq.value)).then(function(j){tr.textContent="";if(!j.success){tr.textContent="Error";return;}var p=document.createElement("p");p.innerHTML="";var b=document.createElement("strong");b.textContent="Verdict: "+j.data.verdict;p.appendChild(b);tr.appendChild(p);j.data.hits.forEach(function(h){var d=document.createElement("p");d.textContent=h.title+" ["+h.type+"] match "+h.cov+": "+h.text;tr.appendChild(d);});});});})();</script>';
			echo '<table class="form-table"><tbody>';
		} else {
			echo '<tr><td colspan="2"><p>' . wp_kses_post( __( '<strong>The assistant works fully without this.</strong> Turn it on only if you want it to phrase answers to general questions using a language model. The model receives the visitor\'s question and your knowledge-base text only. It never receives order, address or account data.', 'tcc-assistant' ) ) . '</p><p>' . wp_kses_post( __( '<strong>Truly unlimited and free</strong> means running an open-source model yourself (for example Ollama or llama.cpp) on a server you control; your shared WordPress host usually cannot do that, but the model can run on a separate small server and this plugin just calls its URL. Hosted free tiers (Groq, OpenRouter, Gemini) work too but have rate limits that can change.', 'tcc-assistant' ) ) . '</p></td></tr>';
			self::field( __( 'Use an AI model', 'tcc-assistant' ), 'llm_on', 'checkbox', __( 'On', 'tcc-assistant' ) );
			echo '<tr><th scope="row"><label for="tcca-llm_preset">' . esc_html__( 'Provider', 'tcc-assistant' ) . '</label></th><td><select id="tcca-llm_preset" name="' . esc_attr( TCCA_Core::OPT ) . '[llm_preset]">';
			foreach ( TCCA_LLM::presets() as $k => $p ) {
				echo '<option value="' . esc_attr( $k ) . '" data-url="' . esc_attr( $p[1] ) . '"' . selected( TCCA_Core::get( 'llm_preset' ), $k, false ) . '>' . esc_html( $p[0] ) . '</option>';
			}
			echo '</select></td></tr>';
			self::field( __( 'Base URL', 'tcc-assistant' ), 'llm_url', 'url', __( 'Must end where <code>/chat/completions</code> begins, e.g. <code>http://localhost:11434/v1</code>', 'tcc-assistant' ) );
			self::field( __( 'Model name', 'tcc-assistant' ), 'llm_model', 'text', __( 'As your provider names it, e.g. <code>llama3.2</code> or <code>qwen2.5:7b</code> on Ollama.', 'tcc-assistant' ) );
			self::field( __( 'API key', 'tcc-assistant' ), 'llm_key', 'password', __( 'Not needed for self-hosted models. Safer: put <code>define( \'TCCA_LLM_KEY\', \'…\' );</code> in wp-config.php instead.', 'tcc-assistant' ) );
			echo '<tr><th></th><td><label><input type="checkbox" name="' . esc_attr( TCCA_Core::OPT ) . '[llm_key_clear]" value="1"> ' . esc_html__( 'Remove the saved key', 'tcc-assistant' ) . '</label></td></tr>';
			self::field( __( 'Voice and extra rules', 'tcc-assistant' ), 'llm_persona', 'textarea', __( 'Optional. Tell the AI how to sound and what to emphasise, e.g. “Warm and concise. Mention free tracked delivery when relevant.” This shapes tone only; it can never override the safety rules (no medical advice, facts from your site only).', 'tcc-assistant' ) );
			self::field( __( 'Daily AI limit', 'tcc-assistant' ), 'llm_cap', 'number', sprintf( /* translators: %d: used today */ __( 'AI answers per day (0 = no limit). Protects a free quota from bots; once reached, the assistant keeps working without AI. Used today: %d', 'tcc-assistant' ), TCCA_LLM::usage()['used'] ), 'min="0"' );
			echo '<tr><td colspan="2"><h3>' . esc_html__( 'Backup provider (optional)', 'tcc-assistant' ) . '</h3><p class="description">' . esc_html__( 'Used automatically if the first one is rate-limited or down. Pair two free tiers for far more headroom.', 'tcc-assistant' ) . '</p></td></tr>';
			self::field( __( 'Backup base URL', 'tcc-assistant' ), 'llm_url2', 'url' );
			self::field( __( 'Backup model', 'tcc-assistant' ), 'llm_model2' );
			self::field( __( 'Backup API key', 'tcc-assistant' ), 'llm_key2', 'password', __( 'Or <code>define( \'TCCA_LLM_KEY2\', \'…\' );</code> in wp-config.php', 'tcc-assistant' ) );
			echo '<tr id="tcca-cf" style="display:none"><th scope="row"><label for="tcca-cfid">' . esc_html__( 'Cloudflare Account ID', 'tcc-assistant' ) . '</label></th><td><input type="text" id="tcca-cfid" class="regular-text" autocomplete="off" placeholder="32-character account ID"><p class="description">' . wp_kses_post( __( 'Not enough on its own: Cloudflare also needs an <strong>API token</strong> with the <em>Workers AI</em> permission. Paste that token in the API key field above. Model names look like <code>@cf/meta/llama-3.1-8b-instruct</code>; check Cloudflare\'s model catalogue for current ones.', 'tcc-assistant' ) ) . '</p></td></tr>';
			echo '<tr><td colspan="2"><script>(function(){var s=document.getElementById("tcca-llm_preset"),u=document.getElementById("tcca-llm_url"),cf=document.getElementById("tcca-cf"),id=document.getElementById("tcca-cfid");function sync(){cf.style.display=s.value==="cloudflare"?"":"none";}
s.addEventListener("change",function(){var v=s.options[s.selectedIndex].getAttribute("data-url");if(v){u.value=v;}sync();});
id.addEventListener("input",function(){var a=id.value.replace(/[^a-z0-9]/gi,"");if(a){u.value="https://api.cloudflare.com/client/v4/accounts/"+a+"/ai/v1";}});
var m=u.value.match(/accounts\/([a-z0-9]+)\/ai/i);if(m&&m[1]!=="ACCOUNT_ID"){id.value=m[1];}sync();})();</script></td></tr>';
		}
		echo '</table>';
		if ( 'index' !== $tab ) {
			submit_button();
		}
		echo '</form></div>';
	}

	/**
	 * One-click: turn an unanswered question into a saved answer.
	 */
	public static function add_kb() {
		check_admin_referer( 'tcca_add_kb' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed' );
		}
		$q = str_replace( '|', '/', sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) ) );
		$a = str_replace( array( '|', "\n" ), array( '/', ' ' ), sanitize_text_field( wp_unslash( $_POST['a'] ?? '' ) ) );
		if ( '' !== $q && '' !== $a ) {
			$all       = TCCA_Core::all();
			$all['kb'] = trim( $all['kb'] . "\n" . $q . ' | ' . $a );
			update_option( TCCA_Core::OPT, $all );
		}
		wp_safe_redirect( admin_url( 'options-general.php?page=tcca&tab=insights&saved=1' ) );
		exit;
	}

	private static function stat( $label, $value, $hint = '' ) {
		return '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;min-width:150px;flex:1"><div style="font-size:24px;font-weight:700;line-height:1.2">' . esc_html( $value ) . '</div><div>' . esc_html( $label ) . '</div>' . ( $hint ? '<div class="description" style="margin-top:2px">' . esc_html( $hint ) . '</div>' : '' ) . '</div>';
	}

	private static function plist( $rows, $key = 'ref' ) {
		if ( ! $rows ) {
			return '<p>' . esc_html__( 'Nothing yet.', 'tcc-assistant' ) . '</p>';
		}
		$o = '<table class="widefat striped" style="max-width:520px"><tbody>';
		foreach ( $rows as $r ) {
			$label = 'pid' === $key ? get_the_title( (int) $r['pid'] ) : $r['ref'];
			$o    .= '<tr><td>' . esc_html( $label ) . '</td><td style="width:60px">' . (int) $r['c'] . '</td></tr>';
		}
		return $o . '</tbody></table>';
	}

	/**
	 * What the assistant earned: orders after a chat, revenue, codes, demand it could not meet.
	 */
	private static function sales_panel() {
		$r   = TCCA_Events::report( 30 );
		$mon = static function ( $v ) {
			return TCCA_Core::money( (float) $v );
		};
		echo '<h2>' . esc_html__( 'What the assistant earned (last 30 days)', 'tcc-assistant' ) . '</h2>';
		if ( '1' !== TCCA_Core::get( 'attr_on' ) ) {
			echo '<p><em>' . esc_html__( 'Sales measurement is switched off (Smart sales tab), so orders are not counted.', 'tcc-assistant' ) . '</em></p>';
		}
		$conv = $r['chats'] ? round( 100 * $r['orders'] / $r['chats'], 1 ) . '%' : '–';
		echo '<div style="display:flex;gap:10px;flex-wrap:wrap;max-width:1000px">';
		echo self::stat( __( 'Revenue after a chat', 'tcc-assistant' ), $mon( $r['revenue'] ), sprintf( /* translators: %d: orders */ __( '%d paid orders, net of refunds', 'tcc-assistant' ), $r['orders'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::stat( __( 'Of which items the chat added', 'tcc-assistant' ), $mon( $r['direct'] ), __( 'Lines the visitor added through the chat', 'tcc-assistant' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::stat( __( 'Chats that became orders', 'tcc-assistant' ), $conv, sprintf( __( '%1$d chats, %2$d added to cart', 'tcc-assistant' ), $r['chats'], $r['added'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::stat( __( 'Awaiting payment', 'tcc-assistant' ), $mon( $r['waiting_value'] ), sprintf( __( '%d orders on hold or pending, not counted above', 'tcc-assistant' ), $r['waiting'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</div><div style="display:flex;gap:10px;flex-wrap:wrap;max-width:1000px;margin-top:10px">';
		echo self::stat( __( 'Chats that showed a product', 'tcc-assistant' ), (string) $r['shown'] ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::stat( __( 'Items added to cart in chat', 'tcc-assistant' ), (string) $r['items'], sprintf( __( 'by %d visitors', 'tcc-assistant' ), $r['added'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::stat( __( 'Went on to checkout', 'tcc-assistant' ), (string) $r['checkouts'] ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo self::stat( __( 'Discount codes', 'tcc-assistant' ), $r['codes_used'] . ' / ' . $r['codes'], sprintf( __( 'used / issued. Discount given: %s', 'tcc-assistant' ), $mon( $r['discount'] ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</div><p class="description" style="max-width:1000px">' . esc_html__( '“After a chat” means the visitor used the chat in the 7 days before ordering, so it shows influence, not proof. “Items the chat added” is the stricter number. Order status is re-checked each time, so cancelled, failed and refunded orders drop out.', 'tcc-assistant' ) . '</p>';

		echo '<h3>' . esc_html__( 'Money left on the table', 'tcc-assistant' ) . '</h3>';
		echo '<div style="display:flex;gap:30px;flex-wrap:wrap"><div><h4>' . esc_html__( 'Asked for, not in your catalogue', 'tcc-assistant' ) . '</h4>' . self::plist( $r['miss'] ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		$wait = TCCA_Leads::waiting();
		echo '<div><h4>' . esc_html__( 'Waiting for a restock (people to email)', 'tcc-assistant' ) . '</h4>' . self::plist( $wait, 'pid' ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div style="display:flex;gap:30px;flex-wrap:wrap"><div><h4>' . esc_html__( 'Brand names customers typed', 'tcc-assistant' ) . '</h4>' . self::plist( $r['brands'] ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div><h4>' . esc_html__( 'Concerns customers searched for', 'tcc-assistant' ) . '</h4>' . self::plist( $r['concern'] ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div style="display:flex;gap:30px;flex-wrap:wrap"><div><h4>' . esc_html__( 'Most shown in chat', 'tcc-assistant' ) . '</h4>' . self::plist( $r['top_shown'], 'pid' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div><h4>' . esc_html__( 'Most added to cart in chat', 'tcc-assistant' ) . '</h4>' . self::plist( $r['top_added'], 'pid' ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput

		foreach ( array( 'request' => __( 'Sourcing requests', 'tcc-assistant' ), 'restock' => __( 'Back-in-stock requests', 'tcc-assistant' ) ) as $kind => $title ) {
			$rows = TCCA_Leads::open( $kind, 30 );
			echo '<h3>' . esc_html( $title ) . '</h3>';
			if ( ! $rows ) {
				echo '<p>' . esc_html__( 'None open.', 'tcc-assistant' ) . '</p>';
				continue;
			}
			echo '<table class="widefat striped" style="max-width:900px"><tbody>';
			foreach ( $rows as $l ) {
				echo '<tr><td>' . esc_html( $l['term'] ) . '</td><td>' . esc_html( $l['email'] ) . '</td><td>' . esc_html( wp_date( 'j M Y', strtotime( $l['created'] . ' UTC' ) ) ) . '</td><td style="width:90px"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="tcca_lead_done"><input type="hidden" name="id" value="' . (int) $l['id'] . '">' . wp_nonce_field( 'tcca_lead_done', '_wpnonce', true, false ) . '<button class="button">' . esc_html__( 'Done', 'tcc-assistant' ) . '</button></form></td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '<hr>';
	}

	/**
	 * What visitors asked, and what the assistant could not answer.
	 */
	private static function insights() {
		global $wpdb;
		self::sales_panel();
		$t     = $wpdb->prefix . 'tcca_log';
		$since = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$tot   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE created >= %s", $since ) ); // phpcs:ignore WordPress.DB
		$miss  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE created >= %s AND answered = 0", $since ) ); // phpcs:ignore WordPress.DB
		$sess  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT session) FROM {$t} WHERE created >= %s", $since ) ); // phpcs:ignore WordPress.DB
		echo '<h2>' . esc_html__( 'Last 30 days', 'tcc-assistant' ) . '</h2>';
		if ( '1' !== TCCA_Core::get( 'log_on' ) ) {
			echo '<p><em>' . esc_html__( 'Logging is switched off on the General tab.', 'tcc-assistant' ) . '</em></p>';
		}
		printf(
			'<p><strong>%1$d</strong> %2$s &nbsp; <strong>%3$d</strong> %4$s &nbsp; <strong>%5$s</strong> %6$s</p>',
			$sess,
			esc_html__( 'conversations', 'tcc-assistant' ),
			$tot,
			esc_html__( 'messages', 'tcc-assistant' ),
			$tot ? esc_html( round( ( ( $tot - $miss ) / $tot ) * 100 ) . '%' ) : '–',
			esc_html__( 'answered', 'tcc-assistant' )
		);
		echo '<h3>' . esc_html__( 'Questions it could not answer: add these to the Knowledge base', 'tcc-assistant' ) . '</h3>';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT msg, COUNT(*) c FROM {$t} WHERE created >= %s AND answered = 0 AND msg <> '' GROUP BY msg ORDER BY c DESC, MAX(created) DESC LIMIT 25", $since ) ); // phpcs:ignore WordPress.DB
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Nothing yet.', 'tcc-assistant' ) . '</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>' . esc_html__( 'Question', 'tcc-assistant' ) . '</th><th style="width:60px">' . esc_html__( 'Times', 'tcc-assistant' ) . '</th><th style="width:380px">' . esc_html__( 'Add an answer', 'tcc-assistant' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				echo '<tr><td>' . esc_html( $r->msg ) . '</td><td>' . (int) $r->c . '</td><td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex;gap:6px"><input type="hidden" name="action" value="tcca_add_kb">' . wp_nonce_field( 'tcca_add_kb', '_wpnonce', true, false ) . '<input type="hidden" name="q" value="' . esc_attr( $r->msg ) . '"><input type="text" name="a" class="regular-text" style="flex:1" placeholder="' . esc_attr__( 'Your answer', 'tcc-assistant' ) . '" required><button class="button">' . esc_html__( 'Save', 'tcc-assistant' ) . '</button></form></td></tr>';
			}
			echo '</tbody></table>';
		}
		// What the assistant has learned from customers.
		$lt   = $wpdb->prefix . 'tcca_learn';
		$good = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$lt} WHERE pos > neg" ); // phpcs:ignore WordPress.DB
		$bad  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$lt} WHERE neg > pos" ); // phpcs:ignore WordPress.DB
		echo '<h3>' . esc_html__( 'What the assistant has learned', 'tcc-assistant' ) . '</h3><p>' . esc_html( sprintf( __( '%1$d question patterns confirmed by customers (now answered directly). %2$d marked not helpful (that answer is avoided for those questions).', 'tcc-assistant' ), $good, $bad ) ) . '</p>';
		$lrows = $wpdb->get_results( "SELECT tokens, type, obj_id, part, pos, neg FROM {$lt} ORDER BY (pos + neg) DESC, updated DESC LIMIT 15" ); // phpcs:ignore WordPress.DB
		if ( $lrows ) {
			echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>' . esc_html__( 'Customers asked (key words)', 'tcc-assistant' ) . '</th><th>' . esc_html__( 'Answer', 'tcc-assistant' ) . '</th><th style="width:60px">👍</th><th style="width:60px">👎</th></tr></thead><tbody>';
			foreach ( $lrows as $r ) {
				$title = 'kb' === $r->type ? __( 'Built-in answer', 'tcc-assistant' ) : ( 'cat' === $r->type ? ( ( $tt = get_term( (int) $r->obj_id ) ) && ! is_wp_error( $tt ) ? $tt->name : '#' . $r->obj_id ) : get_the_title( (int) $r->obj_id ) );
				echo '<tr><td>' . esc_html( $r->tokens ) . '</td><td>' . esc_html( $title ) . '</td><td>' . (int) $r->pos . '</td><td>' . (int) $r->neg . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '<h3>' . esc_html__( 'What people ask about', 'tcc-assistant' ) . '</h3>';
		$by = $wpdb->get_results( $wpdb->prepare( "SELECT intent, COUNT(*) c FROM {$t} WHERE created >= %s GROUP BY intent ORDER BY c DESC", $since ) ); // phpcs:ignore WordPress.DB
		echo '<table class="widefat striped" style="max-width:420px"><tbody>';
		foreach ( $by as $r ) {
			echo '<tr><td>' . esc_html( $r->intent ) . '</td><td>' . (int) $r->c . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
