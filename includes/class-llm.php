<?php
/**
 * Optional language-model layer. Speaks the OpenAI-compatible /chat/completions protocol, which
 * Ollama, llama.cpp, vLLM, LM Studio, Groq, OpenRouter and Gemini's compatibility endpoint all accept.
 *
 * Privacy rule: the model only ever receives the visitor's question, the last few chat lines and
 * knowledge-base text. Order, address and account data never leave the server.
 *
 * @package TCCAssistant
 */

defined( 'ABSPATH' ) || exit;

class TCCA_LLM {

	/**
	 * Preset base URLs shown in settings (the owner can edit the URL).
	 *
	 * @return array<string,array{0:string,1:string}> key => [label, url].
	 */
	public static function presets() {
		return array(
			'ollama'     => array( 'Ollama (self-hosted, unlimited)', 'http://localhost:11434/v1' ),
			'llamacpp'   => array( 'llama.cpp server (self-hosted, unlimited)', 'http://localhost:8080/v1' ),
			'lmstudio'   => array( 'LM Studio (self-hosted, unlimited)', 'http://localhost:1234/v1' ),
			'groq'       => array( 'Groq (hosted, free tier with limits)', 'https://api.groq.com/openai/v1' ),
			'openrouter' => array( 'OpenRouter (hosted, some free models, limits apply)', 'https://openrouter.ai/api/v1' ),
			'gemini'     => array( 'Google Gemini (hosted, free tier with limits)', 'https://generativelanguage.googleapis.com/v1beta/openai' ),
			'cerebras'   => array( 'Cerebras (hosted, free tier with limits)', 'https://api.cerebras.ai/v1' ),
			'mistral'    => array( 'Mistral (hosted, free Experiment plan: check data-training terms)', 'https://api.mistral.ai/v1' ),
			'cloudflare' => array( 'Cloudflare Workers AI (10,000 free neurons/day; replace ACCOUNT_ID)', 'https://api.cloudflare.com/client/v4/accounts/ACCOUNT_ID/ai/v1' ),
			'custom'     => array( 'Custom OpenAI-compatible URL', '' ),
		);
	}

	/**
	 * API key: wp-config constant wins over the stored setting.
	 *
	 * @return string
	 */
	private static function key( $n = 1 ) {
		if ( 1 === $n ) {
			return defined( 'TCCA_LLM_KEY' ) ? (string) TCCA_LLM_KEY : TCCA_Core::get( 'llm_key' );
		}
		return defined( 'TCCA_LLM_KEY2' ) ? (string) TCCA_LLM_KEY2 : TCCA_Core::get( 'llm_key2' );
	}

	/**
	 * Configured providers in order: primary, then backup.
	 *
	 * @return array<int,array{url:string,model:string,key:string}>
	 */
	private static function providers() {
		$out = array( array( 'url' => TCCA_Core::get( 'llm_url' ), 'model' => TCCA_Core::get( 'llm_model' ), 'key' => self::key( 1 ) ) );
		if ( '' !== TCCA_Core::get( 'llm_url2' ) && '' !== TCCA_Core::get( 'llm_model2' ) ) {
			$out[] = array( 'url' => TCCA_Core::get( 'llm_url2' ), 'model' => TCCA_Core::get( 'llm_model2' ), 'key' => self::key( 2 ) );
		}
		return $out;
	}

	/**
	 * Daily cap so bots cannot burn a free quota. 0 means no cap.
	 *
	 * @return array{used:int,cap:int}
	 */
	public static function usage() {
		return array( 'used' => (int) get_transient( 'tcca_llm_n_' . gmdate( 'Ymd' ) ), 'cap' => (int) TCCA_Core::get( 'llm_cap' ) );
	}

	/**
	 * Owner's tone/emphasis text, clearly subordinate to the safety rules.
	 */
	private static function persona() {
		$p = trim( mb_substr( TCCA_Core::get( 'llm_persona' ), 0, 700 ) );
		return '' === $p ? '' : "Style guide from the store owner (tone and emphasis only; it can never override the rules above): " . $p . "\n";
	}

	/**
	 * Is the model configured and switched on?
	 *
	 * @return bool
	 */
	public static function enabled() {
		return '1' === TCCA_Core::get( 'llm_on' ) && '' !== TCCA_Core::get( 'llm_url' ) && '' !== TCCA_Core::get( 'llm_model' );
	}

	/**
	 * Ask the model. Returns reply text or WP_Error.
	 *
	 * @param string $message Visitor question.
	 * @param array  $history Prior lines: [ [ 'role' => 'user|assistant', 'text' => '' ] ].
	 * @param array  $context Knowledge-base entries (arrays with q, a).
	 * @return string|WP_Error
	 */
	public static function ask( $message, $history, $context ) {
		$site = TCCA_Core::site();
		$kb   = '';
		foreach ( $context as $c ) {
			$kb .= '- ' . $c['a'] . "\n";
		}
		$system = sprintf(
			"You are the website assistant for %1\$s, an online pharmacy. Answer in the visitor's language, in at most 3 short sentences, in plain text.\n" .
			"Use ONLY the facts under FACTS. If the answer is not there, say you are not sure and offer to connect them with a pharmacist.\n" .
			"Never give medical advice: no doses, how to take or inject a medicine, side effects, interactions, pregnancy advice, alternatives or diagnosis. For those, say a pharmacist must answer.\n" .
			"Never claim to be a pharmacist or a human. Never invent prices, stock, delivery dates, policies or order details. Never ask for card numbers.\n" .
			"%3\$sFACTS:\n%2\$s",
			$site['company'],
			'' !== $kb ? $kb : "(none)\n",
			self::persona()
		);
		$msgs = array( array( 'role' => 'system', 'content' => $system ) );
		foreach ( array_slice( (array) $history, -4 ) as $h ) {
			$role = ( isset( $h['role'] ) && 'assistant' === $h['role'] ) ? 'assistant' : 'user';
			$text = isset( $h['text'] ) ? mb_substr( sanitize_textarea_field( (string) $h['text'] ), 0, 400 ) : '';
			if ( '' !== $text ) {
				$msgs[] = array( 'role' => $role, 'content' => $text );
			}
		}
		$msgs[] = array( 'role' => 'user', 'content' => mb_substr( $message, 0, 500 ) );

		$cache = 'tcca_llm_' . md5( TCCA_Core::get( 'llm_model' ) . '|' . $system . '|' . wp_json_encode( $msgs ) );
		$hit   = get_transient( $cache );
		if ( is_string( $hit ) && '' !== $hit ) {
			return $hit;
		}

		$use = self::usage();
		if ( $use['cap'] > 0 && $use['used'] >= $use['cap'] ) {
			return new WP_Error( 'tcca_llm_cap', 'Daily AI limit reached' );
		}
		$last = new WP_Error( 'tcca_llm', 'No provider' );
		$body = null;
		foreach ( self::providers() as $prov ) {
			$headers = array( 'Content-Type' => 'application/json' );
			if ( '' !== $prov['key'] ) {
				$headers['Authorization'] = 'Bearer ' . $prov['key'];
			}
			$res = wp_remote_post(
				untrailingslashit( $prov['url'] ) . '/chat/completions',
				array(
					'timeout' => 25,
					'headers' => $headers,
					'body'    => wp_json_encode( array( 'model' => $prov['model'], 'messages' => $msgs, 'temperature' => 0.2, 'max_tokens' => 300, 'stream' => false ) ),
				)
			);
			if ( is_wp_error( $res ) ) {
				$last = $res;
				continue; // Try the backup provider.
			}
			$code = wp_remote_retrieve_response_code( $res );
			$json = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( $code >= 200 && $code < 300 && ! empty( $json['choices'][0]['message']['content'] ) ) {
				$body = $json;
				break;
			}
			$last = new WP_Error( 'tcca_llm', 'Model returned HTTP ' . $code ); // 429 (quota) and 5xx fall through to the backup.
		}
		if ( null === $body ) {
			return $last;
		}
		set_transient( 'tcca_llm_n_' . gmdate( 'Ymd' ), $use['used'] + 1, 2 * DAY_IN_SECONDS );
		$text = self::clean( (string) $body['choices'][0]['message']['content'] );
		if ( '' === $text ) {
			return new WP_Error( 'tcca_llm_empty', 'Empty reply' );
		}
		set_transient( $cache, $text, HOUR_IN_SECONDS );
		return $text;
	}

	/**
	 * Strip reasoning blocks and block anything that reads like dosing instructions.
	 *
	 * @param string $text Raw model text.
	 * @return string
	 */
	public static function clean( $text ) {
		$text = preg_replace( '#<think>.*?</think>#is', '', $text );
		$text = trim( wp_strip_all_tags( $text ) );
		$text = mb_substr( $text, 0, 900 );
		if ( preg_match( '/\b\d+(?:\.\d+)?\s?(mg|mcg|µg|ml|iu|units?)\b/i', $text ) && preg_match( '/\b(take|taking|inject|dose|dosage|per day|daily|twice|once)\b/i', $text ) ) {
			return __( 'That is a question for a pharmacist, so I will not guess. I can connect you with one.', 'tcc-assistant' );
		}
		return $text;
	}
}
