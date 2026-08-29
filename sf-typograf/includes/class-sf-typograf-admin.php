<?php
/**
 * Интерфейс в админке: метабокс с кнопкой и подключение ресурсов.
 *
 * @package SF_Typograf
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SF_Typograf_Admin
 */
class SF_Typograf_Admin {

	/**
	 * Регистрация хуков.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Метабокс в правой колонке редактора.
	 *
	 * @return void
	 */
	public function add_meta_box() {
		foreach ( SF_Typograf_Settings::post_types() as $post_type ) {
			add_meta_box(
				'sf-typograf-box',
				__( 'Типографика', 'sF-typograf' ),
				array( $this, 'render_meta_box' ),
				$post_type,
				'side',
				'high'
			);
		}
	}

	/**
	 * Содержимое метабокса.
	 *
	 * @param WP_Post $post Запись.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		$states   = SF_Typograf_Fields::get_states( $post->ID );
		$excluded = count( $states );
		?>
		<div class="sf-typograf-box">
			<p>
				<button type="button" class="button button-primary button-large sf-typograf-run" id="sf-typograf-run">
					<?php esc_html_e( 'Оттипографить', 'sF-typograf' ); ?>
				</button>
			</p>
			<p class="description">
				<?php esc_html_e( 'Исправит кавычки, тире, спецсимволы и привязки в контенте редактора и текстовых полях ACF. Перед применением покажет, что именно изменится.', 'sF-typograf' ); ?>
			</p>
			<?php if ( $excluded ) : ?>
				<p class="description sf-typograf-excluded-note">
					<?php
					printf(
						/* translators: %d: количество полей. */
						esc_html( _n( 'Для этой страницы из обработки исключено %d поле.', 'Для этой страницы из обработки исключено полей: %d.', $excluded, 'sF-typograf' ) ),
						(int) $excluded
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Подключение стилей и скриптов на экране редактирования.
	 *
	 * @param string $hook Текущий экран.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, SF_Typograf_Settings::post_types(), true ) ) {
			return;
		}

		$post_id = isset( $GLOBALS['post'] ) && $GLOBALS['post'] instanceof WP_Post ? (int) $GLOBALS['post']->ID : 0;

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		wp_enqueue_style(
			'sf-typograf-admin',
			SF_TYPOGRAF_URL . 'assets/css/sf-typograf-admin.css',
			array(),
			SF_TYPOGRAF_VERSION
		);

		wp_enqueue_script(
			'sf-typograf-admin',
			SF_TYPOGRAF_URL . 'assets/js/sf-typograf-admin.js',
			array( 'jquery', 'wp-i18n' ),
			SF_TYPOGRAF_VERSION,
			true
		);

		$settings = SF_Typograf_Settings::get();

		wp_localize_script(
			'sf-typograf-admin',
			'sfTypografData',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'nonce'            => wp_create_nonce( 'sf_typograf_' . $post_id ),
				'postId'           => $post_id,
				'allowedAcfTypes'  => array_values( SF_Typograf_Fields::allowed_acf_types() ),
				'processTitle'     => (bool) $settings['process_title'],
				'i18n'             => array(
					'modalTitle'    => __( 'Предпросмотр типографики', 'sF-typograf' ),
					'colField'      => __( 'Поле', 'sF-typograf' ),
					'colCurrent'    => __( 'Текущее значение', 'sF-typograf' ),
					'colNew'        => __( 'После типографа', 'sF-typograf' ),
					'apply'         => __( 'Применить', 'sF-typograf' ),
					'cancel'        => __( 'Отмена', 'sF-typograf' ),
					'close'         => __( 'Закрыть', 'sF-typograf' ),
					'selectAll'     => __( 'Отметить все', 'sF-typograf' ),
					'deselectAll'   => __( 'Снять все', 'sF-typograf' ),
					'onlyChanged'   => __( 'Показывать только изменённые поля', 'sF-typograf' ),
					'loading'       => __( 'Обработка текста…', 'sF-typograf' ),
					'noFields'      => __( 'Не найдено ни одного текстового поля для обработки.', 'sF-typograf' ),
					'noChanges'     => __( 'Типографика уже в порядке: изменений нет.', 'sF-typograf' ),
					'unchanged'     => __( 'Без изменений', 'sF-typograf' ),
					'skipped'       => __( 'Пропущено', 'sF-typograf' ),
					'error'         => __( 'Ошибка обработки', 'sF-typograf' ),
					'applied'       => __( 'Изменения внесены. Не забудьте сохранить страницу.', 'sF-typograf' ),
					'nothingChecked' => __( 'Не отмечено ни одного поля.', 'sF-typograf' ),
					'postContent'   => __( 'Контент редактора', 'sF-typograf' ),
					'postTitle'     => __( 'Заголовок', 'sF-typograf' ),
					'acfField'      => __( 'Поле ACF', 'sF-typograf' ),
					'statesSaved'   => __( 'Выбор полей сохранён для этой страницы.', 'sF-typograf' ),
					'legend'        => __( '· и &nbsp; — неразрывный пробел', 'sF-typograf' ),
					'noEditorFound' => __( 'Контент редактора WordPress на этом экране не найден: возможно, редактор отключён для этого типа записи или содержимое хранится только в полях ACF.', 'sF-typograf' ),
					'noTitleFound'  => __( 'Поле заголовка на этом экране не найдено.', 'sF-typograf' ),
					'noAcfFields'   => __( 'Поля ACF на этом экране не найдены.', 'sF-typograf' ),
				),
			)
		);
	}
}
