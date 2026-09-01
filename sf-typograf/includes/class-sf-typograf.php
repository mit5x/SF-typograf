<?php
/**
 * Ядро плагина.
 *
 * @package SF_Typograf
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SF_Typograf
 */
final class SF_Typograf {

	/**
	 * Экземпляр плагина.
	 *
	 * @var SF_Typograf|null
	 */
	protected static $instance = null;

	/**
	 * Настройки.
	 *
	 * @var SF_Typograf_Settings
	 */
	public $settings;

	/**
	 * Админка.
	 *
	 * @var SF_Typograf_Admin
	 */
	public $admin;

	/**
	 * AJAX.
	 *
	 * @var SF_Typograf_Ajax
	 */
	public $ajax;

	/**
	 * Единственный экземпляр.
	 *
	 * @return SF_Typograf
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Конструктор.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		if ( is_admin() ) {
			$this->settings = new SF_Typograf_Settings();
			$this->admin    = new SF_Typograf_Admin();
			$this->ajax     = new SF_Typograf_Ajax();
		}
	}

	/**
	 * Загрузка переводов.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'sF-typograf',
			false,
			dirname( plugin_basename( SF_TYPOGRAF_FILE ) ) . '/languages'
		);
	}

	/**
	 * Обрабатывает текст типографом (публичное API плагина).
	 *
	 * Пример: echo sf_typograf()->typograf( $text, 'text' );
	 *
	 * @param string $text    Текст.
	 * @param string $context html|text.
	 * @return string
	 */
	public function typograf( $text, $context = 'html' ) {
		$engine = new SF_Typograf_Engine( SF_Typograf_Settings::engine_options() );

		return $engine->process( $text, $context );
	}
}
