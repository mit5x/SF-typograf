<?php
/**
 * Настройки плагина.
 *
 * @package SF_Typograf
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SF_Typograf_Settings
 */
class SF_Typograf_Settings {

	/**
	 * Имя опции.
	 */
	const OPTION = 'sf_typograf_settings';

	/**
	 * Слаг страницы настроек.
	 */
	const PAGE = 'sf-typograf';

	/**
	 * Регистрация хуков.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SF_TYPOGRAF_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Значения по умолчанию.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'engine'         => 'local',
			'quotes_style'   => 'ru',
			'specials'       => 1,
			'quotes'         => 1,
			'dashes'         => 1,
			'ranges'         => 1,
			'nbsp'           => 1,
			'spaces'         => 1,
			'nobr'           => 0,
			'process_title'  => 0,
			'post_types'     => array( 'post', 'page' ),
		);
	}

	/**
	 * Текущие настройки.
	 *
	 * @return array
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );

		$settings = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );

		/**
		 * Фильтр настроек плагина.
		 *
		 * @param array $settings Настройки.
		 */
		return apply_filters( 'sf_typograf_settings', $settings );
	}

	/**
	 * Настройки правил для движка типографа.
	 *
	 * @return array
	 */
	public static function engine_options() {
		$settings = self::get();

		return array(
			'specials'     => (bool) $settings['specials'],
			'quotes'       => (bool) $settings['quotes'],
			'quotes_style' => 'en' === $settings['quotes_style'] ? 'en' : 'ru',
			'dashes'       => (bool) $settings['dashes'],
			'ranges'       => (bool) $settings['ranges'],
			'nbsp'         => (bool) $settings['nbsp'],
			'spaces'       => (bool) $settings['spaces'],
			'nobr'         => (bool) $settings['nobr'],
		);
	}

	/**
	 * Типы записей, для которых выводится кнопка.
	 *
	 * @return array
	 */
	public static function post_types() {
		$settings = self::get();
		$types    = array_filter( (array) $settings['post_types'] );

		if ( empty( $types ) ) {
			$types = array( 'post', 'page' );
		}

		/**
		 * Фильтр типов записей с кнопкой «Оттипографить».
		 *
		 * @param array $types Типы записей.
		 */
		return (array) apply_filters( 'sf_typograf_post_types', $types );
	}

	/**
	 * Пункт меню.
	 *
	 * @return void
	 */
	public function add_page() {
		add_options_page(
			__( 'SF Typograf', 'sF-typograf' ),
			__( 'SF Typograf', 'sF-typograf' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Регистрация опции.
	 *
	 * @return void
	 */
	public function register() {
		register_setting(
			'sf_typograf_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Ссылка на настройки в списке плагинов.
	 *
	 * @param array $links Ссылки.
	 * @return array
	 */
	public function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE );

		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Настройки', 'sF-typograf' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Очистка значений формы.
	 *
	 * @param mixed $input Данные формы.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$clean    = array();

		$clean['engine']       = ( isset( $input['engine'] ) && 'remote' === $input['engine'] ) ? 'remote' : 'local';
		$clean['quotes_style'] = ( isset( $input['quotes_style'] ) && 'en' === $input['quotes_style'] ) ? 'en' : 'ru';

		foreach ( array( 'specials', 'quotes', 'dashes', 'ranges', 'nbsp', 'spaces', 'nobr', 'process_title' ) as $key ) {
			$clean[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		$types = isset( $input['post_types'] ) ? (array) $input['post_types'] : array();
		$clean['post_types'] = array_values( array_filter( array_map( 'sanitize_key', $types ) ) );

		if ( empty( $clean['post_types'] ) ) {
			$clean['post_types'] = $defaults['post_types'];
		}

		return $clean;
	}

	/**
	 * Вывод страницы настроек.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = self::get();
		$types    = get_post_types( array( 'show_ui' => true ), 'objects' );

		$rules = array(
			'specials' => __( 'Знаки в тексте и спецсимволы: (C) → ©, (R) → ®, (TM) → ™, ... → …, +- → ±, 2x3 → 2×3', 'sF-typograf' ),
			'quotes'   => __( 'Кавычки: «ёлочки» и „лапки“ вместо программистских', 'sF-typograf' ),
			'dashes'   => __( 'Тире: дефис между словами заменяется длинным тире', 'sF-typograf' ),
			'ranges'   => __( 'Числовые диапазоны: 1990-2000 → 1990–2000 (короткое тире)', 'sF-typograf' ),
			'nbsp'     => __( 'Привязки: неразрывные пробелы после предлогов, у инициалов, чисел, знаков № и §', 'sF-typograf' ),
			'spaces'   => __( 'Пробелы: лишние пробелы и пробелы перед знаками препинания', 'sF-typograf' ),
			'nobr'     => __( 'Неразрывные диапазоны &lt;nobr&gt;: телефоны и слова через дефис (только для контента редактора)', 'sF-typograf' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'SF Typograf', 'sF-typograf' ); ?></h1>

			<form method="post" action="options.php">
				<?php settings_fields( 'sf_typograf_group' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Движок', 'sF-typograf' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input type="radio" name="<?php echo esc_attr( self::OPTION ); ?>[engine]" value="local" <?php checked( 'local', $settings['engine'] ); ?> />
									<?php esc_html_e( 'Встроенный типограф (работает локально, без внешних запросов)', 'sF-typograf' ); ?>
								</label><br />
								<label>
									<input type="radio" name="<?php echo esc_attr( self::OPTION ); ?>[engine]" value="remote" <?php checked( 'remote', $settings['engine'] ); ?> />
									<?php esc_html_e( 'Веб-сервис «Типограф» Студии Артемия Лебедева', 'sF-typograf' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'При недоступности веб-сервиса плагин автоматически использует встроенный типограф.', 'sF-typograf' ); ?>
								</p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Стиль кавычек', 'sF-typograf' ); ?></th>
						<td>
							<label>
								<input type="radio" name="<?php echo esc_attr( self::OPTION ); ?>[quotes_style]" value="ru" <?php checked( 'ru', $settings['quotes_style'] ); ?> />
								<?php esc_html_e( 'Русские: «…» и внутренние „…“', 'sF-typograf' ); ?>
							</label><br />
							<label>
								<input type="radio" name="<?php echo esc_attr( self::OPTION ); ?>[quotes_style]" value="en" <?php checked( 'en', $settings['quotes_style'] ); ?> />
								<?php esc_html_e( 'Английские: “…” и внутренние ‘…’', 'sF-typograf' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Правила', 'sF-typograf' ); ?></th>
						<td>
							<fieldset>
								<?php foreach ( $rules as $key => $label ) : ?>
									<label>
										<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( 1, (int) $settings[ $key ] ); ?> />
										<?php echo wp_kses( $label, array() ); ?>
									</label><br />
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Типы записей', 'sF-typograf' ); ?></th>
						<td>
							<fieldset>
								<?php
								foreach ( $types as $type ) :
									if ( 'attachment' === $type->name ) {
										continue;
									}
									?>
									<label>
										<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[post_types][]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( true, in_array( $type->name, (array) $settings['post_types'], true ) ); ?> />
										<?php echo esc_html( $type->labels->name ); ?>
									</label><br />
								<?php endforeach; ?>
								<p class="description"><?php esc_html_e( 'Где показывать кнопку «Оттипографить».', 'sF-typograf' ); ?></p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Заголовок записи', 'sF-typograf' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[process_title]" value="1" <?php checked( 1, (int) $settings['process_title'] ); ?> />
								<?php esc_html_e( 'Показывать заголовок записи в окне предпросмотра', 'sF-typograf' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
