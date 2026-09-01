<?php
/**
 * Клиент веб-сервиса «Типограф» Студии Артемия Лебедева.
 *
 * Порт ArtLebedevStudio.RemoteTypograf (remotetypograf.php) на WordPress HTTP API:
 * без fsockopen, по HTTPS, с таймаутом, кэшированием и корректным разбором ответа.
 *
 * Веб-сервис: https://typograf.artlebedev.ru/webservices/typograf.asmx
 *
 * @package SF_Typograf
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SF_Typograf_Remote
 */
class SF_Typograf_Remote {

	/**
	 * Адрес веб-сервиса.
	 */
	const ENDPOINT = 'https://typograf.artlebedev.ru/webservices/typograf.asmx';

	/**
	 * SOAPAction.
	 */
	const SOAP_ACTION = 'http://typograf.artlebedev.ru/webservices/ProcessText';

	/**
	 * Тип сущностей: 1 — HTML, 2 — XML, 3 — без сущностей, 4 — смешанные.
	 *
	 * @var int
	 */
	protected $entity_type = 4;

	/**
	 * Расставлять <br />.
	 *
	 * @var int
	 */
	protected $use_br = 0;

	/**
	 * Расставлять <p>.
	 *
	 * @var int
	 */
	protected $use_p = 0;

	/**
	 * Максимальная длина неразрывного участка.
	 *
	 * @var int
	 */
	protected $max_nobr = 3;

	/**
	 * Внешние кавычки.
	 *
	 * @var string
	 */
	protected $quot_a = 'laquo raquo';

	/**
	 * Внутренние кавычки.
	 *
	 * @var string
	 */
	protected $quot_b = 'bdquo ldquo';

	/**
	 * Таймаут запроса, сек.
	 *
	 * @var int
	 */
	protected $timeout = 15;

	/**
	 * Текст последней ошибки.
	 *
	 * @var string
	 */
	protected $last_error = '';

	/**
	 * Журнал обмена с веб-сервисом.
	 *
	 * @var array
	 */
	protected $log = array();

	/**
	 * Сколько символов запроса и ответа сохранять в журнале.
	 */
	const LOG_SNIPPET = 2000;

	/**
	 * Конструктор.
	 *
	 * @param array $args Параметры.
	 */
	public function __construct( $args = array() ) {
		foreach ( array( 'entity_type', 'use_br', 'use_p', 'max_nobr', 'quot_a', 'quot_b', 'timeout' ) as $key ) {
			if ( isset( $args[ $key ] ) ) {
				$this->{$key} = $args[ $key ];
			}
		}
	}

	/**
	 * Текст последней ошибки.
	 *
	 * @return string
	 */
	public function get_last_error() {
		return $this->last_error;
	}

	/**
	 * Журнал обмена с веб-сервисом за последний вызов.
	 *
	 * @return array
	 */
	public function get_log() {
		return $this->log;
	}

	/**
	 * Обрезает длинный фрагмент для журнала.
	 *
	 * @param string $text Текст.
	 * @return string
	 */
	protected function snippet( $text ) {
		$text = (string) $text;

		if ( strlen( $text ) <= self::LOG_SNIPPET ) {
			return $text;
		}

		return substr( $text, 0, self::LOG_SNIPPET ) . "\n…"
			. sprintf(
				/* translators: %s: размер в байтах. */
				__( '[обрезано, всего %s байт]', 'sF-typograf' ),
				number_format_i18n( strlen( $text ) )
			);
	}

	/**
	 * Отправляет текст в веб-сервис.
	 *
	 * @param string $text Исходный текст.
	 * @return string|WP_Error Обработанный текст либо ошибка.
	 */
	public function process_text( $text ) {
		$this->last_error = '';
		$this->log        = array();

		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return $text;
		}

		$cache_key = 'sf_typograf_' . md5( $text . wp_json_encode( array( $this->entity_type, $this->use_br, $this->use_p, $this->max_nobr, $this->quot_a, $this->quot_b ) ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			$this->log[] = array(
				'status'   => 'cache',
				'endpoint' => self::ENDPOINT,
				'message'  => __( 'Ответ взят из кэша — запрос к веб-сервису не отправлялся.', 'sF-typograf' ),
			);

			return $cached;
		}

		$body = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
			. '<soap:Body>'
			. '<ProcessText xmlns="http://typograf.artlebedev.ru/webservices/">'
			. '<text>' . $this->escape( $text ) . '</text>'
			. '<entityType>' . (int) $this->entity_type . '</entityType>'
			. '<useBr>' . (int) $this->use_br . '</useBr>'
			. '<useP>' . (int) $this->use_p . '</useP>'
			. '<maxNobr>' . (int) $this->max_nobr . '</maxNobr>'
			. '<quotA>' . esc_html( $this->quot_a ) . '</quotA>'
			. '<quotB>' . esc_html( $this->quot_b ) . '</quotB>'
			. '</ProcessText>'
			. '</soap:Body>'
			. '</soap:Envelope>';

		$started = microtime( true );

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout'     => $this->timeout,
				'redirection' => 3,
				'headers'     => array(
					'Content-Type' => 'text/xml; charset=utf-8',
					'SOAPAction'   => '"' . self::SOAP_ACTION . '"',
				),
				'body'        => $body,
			)
		);

		$elapsed = round( ( microtime( true ) - $started ) * 1000 );

		$entry = array(
			'status'   => 'ok',
			'endpoint' => self::ENDPOINT,
			'action'   => self::SOAP_ACTION,
			'ms'       => $elapsed,
			'sent'     => strlen( $body ),
			'request'  => $this->snippet( $body ),
		);

		if ( is_wp_error( $response ) ) {
			$this->last_error = $response->get_error_message();

			$entry['status']  = 'error';
			$entry['message'] = sprintf(
				/* translators: %s: сообщение об ошибке HTTP-запроса. */
				__( 'Запрос не выполнен: %s', 'sF-typograf' ),
				$this->last_error
			);
			$this->log[]      = $entry;

			return $response;
		}

		$code            = (int) wp_remote_retrieve_response_code( $response );
		$raw             = wp_remote_retrieve_body( $response );
		$entry['code']   = $code;
		$entry['received'] = strlen( $raw );
		$entry['response'] = $this->snippet( $raw );

		if ( 200 !== $code ) {
			$this->last_error = sprintf(
				/* translators: %d: HTTP-код ответа. */
				__( 'Веб-сервис «Типограф» вернул код %d.', 'sF-typograf' ),
				$code
			);

			$entry['status']  = 'error';
			$entry['message'] = $this->last_error;
			$this->log[]      = $entry;

			return new WP_Error( 'sf_typograf_http', $this->last_error );
		}

		// SOAP-ошибка приходит с кодом 200 не всегда, но разобрать её стоит всегда.
		if ( preg_match( '~<(?:soap:)?Fault>.*?<faultstring>(.*?)</faultstring>~s', $raw, $fault ) ) {
			$this->last_error = sprintf(
				/* translators: %s: текст SOAP-ошибки. */
				__( 'Веб-сервис вернул SOAP-ошибку: %s', 'sF-typograf' ),
				html_entity_decode( $fault[1], ENT_QUOTES, 'UTF-8' )
			);

			$entry['status']  = 'error';
			$entry['message'] = $this->last_error;
			$this->log[]      = $entry;

			return new WP_Error( 'sf_typograf_fault', $this->last_error );
		}

		if ( ! preg_match( '~<ProcessTextResult>(.*?)</ProcessTextResult>~s', $raw, $m ) ) {
			$this->last_error = __( 'Не удалось разобрать ответ веб-сервиса «Типограф».', 'sF-typograf' );

			$entry['status']  = 'error';
			$entry['message'] = $this->last_error;
			$this->log[]      = $entry;

			return new WP_Error( 'sf_typograf_parse', $this->last_error );
		}

		$result = $this->unescape( $m[1] );

		$entry['message'] = sprintf(
			/* translators: 1: HTTP-код, 2: время ответа в миллисекундах. */
			__( 'Успешно: HTTP %1$d, %2$d мс.', 'sF-typograf' ),
			$code,
			$elapsed
		);
		$this->log[] = $entry;

		set_transient( $cache_key, $result, DAY_IN_SECONDS );

		return $result;
	}

	/**
	 * Экранирует текст для передачи в SOAP-конверте.
	 *
	 * @param string $text Текст.
	 * @return string
	 */
	protected function escape( $text ) {
		return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $text );
	}

	/**
	 * Разэкранирует ответ веб-сервиса.
	 *
	 * @param string $text Текст.
	 * @return string
	 */
	protected function unescape( $text ) {
		return str_replace( array( '&lt;', '&gt;', '&amp;' ), array( '<', '>', '&' ), $text );
	}
}
