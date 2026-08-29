<?php
/**
 * Определение и проверка полей, которые можно отдавать типографу.
 *
 * @package SF_Typograf
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SF_Typograf_Fields
 */
class SF_Typograf_Fields {

	/**
	 * Мета-ключ, в котором хранятся состояния чекбоксов для конкретной записи.
	 */
	const STATES_META_KEY = '_sf_typograf_field_states';

	/**
	 * Типы полей ACF, которые разрешено обрабатывать.
	 *
	 * Только «Текст» и «Область текста»: значения полей других типов
	 * (ссылки, изображения, файлы, отношения и т. д.) типограф не трогает.
	 *
	 * @return array
	 */
	public static function allowed_acf_types() {
		/**
		 * Фильтр списка допустимых типов полей ACF.
		 *
		 * @param array $types Типы полей.
		 */
		return (array) apply_filters( 'sf_typograf_allowed_acf_types', array( 'text', 'textarea' ) );
	}

	/**
	 * Активен ли ACF.
	 *
	 * @return bool
	 */
	public static function acf_active() {
		return function_exists( 'acf_get_field' );
	}

	/**
	 * Возвращает описание поля ACF по его ключу.
	 *
	 * @param string $key Ключ поля (field_xxx).
	 * @return array|false
	 */
	public static function get_acf_field( $key ) {
		if ( ! self::acf_active() || ! is_string( $key ) || ! preg_match( '/^field_[A-Za-z0-9_\-]+$/', $key ) ) {
			return false;
		}

		$field = acf_get_field( $key );

		return ( is_array( $field ) && ! empty( $field['type'] ) ) ? $field : false;
	}

	/**
	 * Проверяет, что тип поля ACF разрешён к обработке.
	 *
	 * @param string $key         Ключ поля.
	 * @param string $client_type Тип поля по данным браузера (используется только
	 *                            как запасной вариант, если поле не найдено в ACF
	 *                            и включён фильтр sf_typograf_trust_client_field_type).
	 * @return true|WP_Error
	 */
	public static function check_acf_field( $key, $client_type = '' ) {
		if ( ! self::acf_active() ) {
			return new WP_Error( 'sf_typograf_no_acf', __( 'ACF не активен.', 'sF-typograf' ) );
		}

		$field = self::get_acf_field( $key );

		if ( ! $field && self::trust_client_type() && in_array( $client_type, self::allowed_acf_types(), true ) ) {
			return true;
		}

		if ( ! $field ) {
			return new WP_Error(
				'sf_typograf_unknown_field',
				sprintf(
					/* translators: %s: ключ поля ACF. */
					__( 'Поле %s не найдено в ACF — тип не подтверждён, поле пропущено.', 'sF-typograf' ),
					$key
				)
			);
		}

		if ( ! in_array( $field['type'], self::allowed_acf_types(), true ) ) {
			return new WP_Error(
				'sf_typograf_wrong_type',
				sprintf(
					/* translators: %s: тип поля ACF. */
					__( 'Поле типа «%s» не является текстовым — пропущено.', 'sF-typograf' ),
					$field['type']
				)
			);
		}

		return true;
	}

	/**
	 * Разрешено ли доверять типу поля, присланному браузером.
	 *
	 * По умолчанию нет: тип поля всегда перепроверяется на сервере через
	 * acf_get_field(). Фильтр нужен для нестандартных сборок, где поле
	 * не удаётся найти по ключу.
	 *
	 * @return bool
	 */
	public static function trust_client_type() {
		/**
		 * Фильтр доверия типу поля из браузера.
		 *
		 * @param bool $trust По умолчанию false.
		 */
		return (bool) apply_filters( 'sf_typograf_trust_client_field_type', false );
	}

	/**
	 * Проверяет, что значение похоже на текст, а не на ссылку, путь, число и т. п.
	 *
	 * Дополнительная страховка: даже поле типа «Текст» может хранить URL,
	 * путь к картинке, идентификатор или JSON — такие значения не трогаем.
	 *
	 * @param string $value Значение поля.
	 * @return true|WP_Error
	 */
	public static function check_value( $value ) {
		if ( ! is_string( $value ) ) {
			return new WP_Error( 'sf_typograf_not_string', __( 'Значение не является строкой.', 'sF-typograf' ) );
		}

		$trimmed = trim( $value );

		if ( '' === $trimmed ) {
			return new WP_Error( 'sf_typograf_empty', __( 'Пустое значение.', 'sF-typograf' ) );
		}

		// Значение целиком — ссылка, путь или e-mail.
		if ( preg_match( '~^(?:https?:|ftp:|mailto:|tel:|//|/|\./|\.\./|#)\S*$~i', $trimmed ) ) {
			return new WP_Error( 'sf_typograf_url', __( 'Похоже на ссылку или путь — пропущено.', 'sF-typograf' ) );
		}

		if ( is_email( $trimmed ) ) {
			return new WP_Error( 'sf_typograf_email', __( 'Похоже на e-mail — пропущено.', 'sF-typograf' ) );
		}

		// Значение без пробелов, но с расширением файла: image.jpg, doc.pdf.
		if ( preg_match( '/^\S+\.(?:jpe?g|png|gif|svg|webp|avif|pdf|docx?|xlsx?|zip|mp4|mp3|css|js)$/i', $trimmed ) ) {
			return new WP_Error( 'sf_typograf_file', __( 'Похоже на имя файла — пропущено.', 'sF-typograf' ) );
		}

		// Число, идентификатор, код цвета, дата, телефон.
		if ( preg_match( '/^[\d\s\+\-\.,:\/#()]+$/u', $trimmed ) ) {
			return new WP_Error( 'sf_typograf_numeric', __( 'Значение не содержит текста — пропущено.', 'sF-typograf' ) );
		}

		// JSON или сериализованные данные.
		if ( is_serialized( $trimmed ) ) {
			return new WP_Error( 'sf_typograf_serialized', __( 'Сериализованные данные — пропущено.', 'sF-typograf' ) );
		}

		if ( preg_match( '/^[\[{].*[\]}]$/s', $trimmed ) && null !== json_decode( $trimmed, true ) ) {
			return new WP_Error( 'sf_typograf_json', __( 'Похоже на JSON — пропущено.', 'sF-typograf' ) );
		}

		// Одиночный шорткод без текста вокруг.
		if ( preg_match( '/^\[[^\]]+\](?:.*\[\/[^\]]+\])?$/s', $trimmed ) && ! preg_match( '/\]\s*\S+\s*\[/', $trimmed ) ) {
			$stripped = trim( preg_replace( '/\[[^\]]*\]/', '', $trimmed ) );
			if ( '' === $stripped ) {
				return new WP_Error( 'sf_typograf_shortcode', __( 'Только шорткод — пропущено.', 'sF-typograf' ) );
			}
		}

		// Нет ни одной буквы — типографить нечего.
		if ( ! preg_match( '/\p{L}/u', $trimmed ) ) {
			return new WP_Error( 'sf_typograf_no_letters', __( 'Нет текста — пропущено.', 'sF-typograf' ) );
		}

		return true;
	}

	/**
	 * Приводит идентификатор поля к ключу, под которым хранится состояние чекбокса.
	 *
	 * Номера строк повторителей и гибкого контента выбрасываются: исключается
	 * поле целиком, а не конкретная строка. Иначе добавление или перестановка
	 * строк сдвигала бы сохранённый выбор на соседние поля.
	 *
	 * @param string $id Идентификатор поля (имя input).
	 * @return string
	 */
	public static function state_key( $id ) {
		return preg_replace( '/\[(?:row-)?\d+\]/', '[]', (string) $id );
	}

	/**
	 * Сохранённые состояния чекбоксов записи.
	 *
	 * @param int $post_id ID записи.
	 * @return array Массив вида ключ => 0|1.
	 */
	public static function get_states( $post_id ) {
		$states = get_post_meta( (int) $post_id, self::STATES_META_KEY, true );

		return is_array( $states ) ? $states : array();
	}

	/**
	 * Сохраняет состояния чекбоксов записи.
	 *
	 * @param int   $post_id ID записи.
	 * @param array $states  Массив вида ключ => 0|1.
	 * @return void
	 */
	public static function save_states( $post_id, $states ) {
		$post_id = (int) $post_id;
		$clean   = array();

		foreach ( (array) $states as $key => $value ) {
			$key = sanitize_text_field( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			$clean[ $key ] = $value ? 1 : 0;
		}

		// Не раздуваем мету: храним только исключённые поля.
		$excluded = array_filter(
			$clean,
			function ( $value ) {
				return 0 === $value;
			}
		);

		if ( empty( $excluded ) ) {
			delete_post_meta( $post_id, self::STATES_META_KEY );

			return;
		}

		update_post_meta( $post_id, self::STATES_META_KEY, $excluded );
	}

	/**
	 * Отмечен ли чекбокс поля по умолчанию.
	 *
	 * По умолчанию отмечены все поля; снятые ранее — остаются снятыми.
	 *
	 * @param array  $states Сохранённые состояния.
	 * @param string $key    Ключ поля.
	 * @return bool
	 */
	public static function is_checked( $states, $key ) {
		if ( array_key_exists( $key, $states ) ) {
			return (bool) $states[ $key ];
		}

		return true;
	}
}
