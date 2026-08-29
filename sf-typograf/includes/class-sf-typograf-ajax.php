<?php
/**
 * AJAX-обработчики: предпросмотр и сохранение выбора полей.
 *
 * @package SF_Typograf
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SF_Typograf_Ajax
 */
class SF_Typograf_Ajax {

	/**
	 * Регистрация хуков.
	 */
	public function __construct() {
		add_action( 'wp_ajax_sf_typograf_preview', array( $this, 'preview' ) );
		add_action( 'wp_ajax_sf_typograf_save_states', array( $this, 'save_states' ) );
	}

	/**
	 * Проверяет права и nonce, возвращает ID записи.
	 *
	 * @return int
	 */
	protected function verify() {
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! $post_id || ! wp_verify_nonce( $nonce, 'sf_typograf_' . $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Проверка безопасности не пройдена. Обновите страницу.', 'sF-typograf' ) ), 403 );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав для редактирования этой записи.', 'sF-typograf' ) ), 403 );
		}

		return $post_id;
	}

	/**
	 * Готовит предпросмотр изменений.
	 *
	 * @return void
	 */
	public function preview() {
		$post_id = $this->verify();

		$raw_fields = isset( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- значения полей санитизируются ниже поштучно.
		$fields     = json_decode( is_string( $raw_fields ) ? $raw_fields : '', true );

		if ( ! is_array( $fields ) ) {
			wp_send_json_error( array( 'message' => __( 'Не удалось прочитать список полей.', 'sF-typograf' ) ), 400 );
		}

		$states = SF_Typograf_Fields::get_states( $post_id );
		$rows   = array();

		foreach ( $fields as $field ) {
			$row = $this->process_field( $field, $states );
			if ( $row ) {
				$rows[] = $row;
			}
		}

		wp_send_json_success(
			array(
				'fields' => $rows,
				'engine' => SF_Typograf_Settings::get()['engine'],
			)
		);
	}

	/**
	 * Обрабатывает одно поле из запроса.
	 *
	 * @param mixed $field  Данные поля.
	 * @param array $states Сохранённые состояния чекбоксов.
	 * @return array|null
	 */
	protected function process_field( $field, $states ) {
		if ( ! is_array( $field ) || empty( $field['id'] ) ) {
			return null;
		}

		$id    = sanitize_text_field( (string) $field['id'] );
		$kind  = isset( $field['kind'] ) ? sanitize_key( $field['kind'] ) : '';
		$label = isset( $field['label'] ) ? sanitize_text_field( (string) $field['label'] ) : $id;
		$value = isset( $field['value'] ) && is_string( $field['value'] ) ? $field['value'] : '';

		$state_key = SF_Typograf_Fields::state_key( $id );

		$row = array(
			'id'        => $id,
			'stateKey'  => $state_key,
			'kind'      => $kind,
			'label'     => $label,
			'original'  => $value,
			'processed' => $value,
			'changed'   => false,
			'skipped'   => false,
			'reason'    => '',
			'code'      => '',
			'checked'   => SF_Typograf_Fields::is_checked( $states, $state_key ),
		);

		if ( ! in_array( $kind, array( 'editor', 'title', 'acf' ), true ) ) {
			return null;
		}

		// Поля ACF: обрабатываем только «Текст» и «Область текста».
		// Тип проверяется на сервере по ключу поля, а не по данным из браузера.
		if ( 'acf' === $kind ) {
			$key         = isset( $field['fieldKey'] ) ? sanitize_text_field( (string) $field['fieldKey'] ) : '';
			$client_type = isset( $field['acfType'] ) ? sanitize_key( $field['acfType'] ) : '';
			$check       = SF_Typograf_Fields::check_acf_field( $key, $client_type );

			if ( is_wp_error( $check ) ) {
				$row['skipped'] = true;
				$row['reason']  = $check->get_error_message();
				$row['code']    = $check->get_error_code();

				return $row;
			}

			$acf_field      = SF_Typograf_Fields::get_acf_field( $key );
			$row['acfType'] = $acf_field ? $acf_field['type'] : $client_type;
		}

		$check = SF_Typograf_Fields::check_value( $value );
		if ( is_wp_error( $check ) ) {
			$row['skipped'] = true;
			$row['reason']  = $check->get_error_message();
			$row['code']    = $check->get_error_code();

			return $row;
		}

		$context = ( 'editor' === $kind ) ? 'html' : 'text';

		/**
		 * Фильтр контекста обработки поля.
		 *
		 * @param string $context html|text.
		 * @param array  $row     Данные поля.
		 */
		$context = apply_filters( 'sf_typograf_field_context', $context, $row );

		$processed = $this->typograf( $value, $context );

		if ( is_wp_error( $processed ) ) {
			$row['skipped'] = true;
			$row['reason']  = $processed->get_error_message();
			$row['code']    = $processed->get_error_code();

			return $row;
		}

		/**
		 * Фильтр результата обработки поля.
		 *
		 * @param string $processed Обработанный текст.
		 * @param string $value     Исходный текст.
		 * @param array  $row       Данные поля.
		 */
		$processed = apply_filters( 'sf_typograf_processed_value', $processed, $value, $row );

		$row['processed'] = $processed;
		$row['changed']   = ( $processed !== $value );

		return $row;
	}

	/**
	 * Прогоняет текст через выбранный движок.
	 *
	 * @param string $text    Текст.
	 * @param string $context html|text.
	 * @return string|WP_Error
	 */
	protected function typograf( $text, $context ) {
		$settings = SF_Typograf_Settings::get();

		if ( 'remote' === $settings['engine'] ) {
			$remote = new SF_Typograf_Remote(
				array(
					'entity_type' => ( 'html' === $context ) ? 1 : 3,
					'use_br'      => 0,
					'use_p'       => 0,
					'max_nobr'    => $settings['nobr'] ? 3 : 0,
					'quot_a'      => ( 'en' === $settings['quotes_style'] ) ? 'ldquo rdquo' : 'laquo raquo',
					'quot_b'      => ( 'en' === $settings['quotes_style'] ) ? 'lsquo rsquo' : 'bdquo ldquo',
				)
			);

			$result = $remote->process_text( $text );

			if ( ! is_wp_error( $result ) ) {
				return $result;
			}

			// Веб-сервис недоступен — молча переходим на встроенный типограф.
		}

		$engine = new SF_Typograf_Engine( SF_Typograf_Settings::engine_options() );

		return $engine->process( $text, $context );
	}

	/**
	 * Сохраняет состояния чекбоксов для записи.
	 *
	 * @return void
	 */
	public function save_states() {
		$post_id = $this->verify();

		$raw    = isset( $_POST['states'] ) ? wp_unslash( $_POST['states'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- значения санитизируются в save_states().
		$states = json_decode( is_string( $raw ) ? $raw : '', true );

		if ( ! is_array( $states ) ) {
			wp_send_json_error( array( 'message' => __( 'Не удалось прочитать состояния полей.', 'sF-typograf' ) ), 400 );
		}

		SF_Typograf_Fields::save_states( $post_id, $states );

		wp_send_json_success( array( 'saved' => true ) );
	}
}
