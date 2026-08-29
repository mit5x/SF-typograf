<?php
/**
 * Plugin Name:       SF Typograf
 * Plugin URI:        https://web-format.net
 * Description:       Кнопка «Оттипографить» в правой колонке редактора записи/страницы: исправляет типографику в контенте редактора и в текстовых полях ACF с предварительным просмотром изменений.
 * Version:           1.0.1
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Author:            Saytformat
 * Author URI:        https://web-format.net
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sF-typograf
 * Domain Path:       /languages
 *
 * @package SF_Typograf
 */

defined( 'ABSPATH' ) || exit;

define( 'SF_TYPOGRAF_VERSION', '1.0.1' );
define( 'SF_TYPOGRAF_FILE', __FILE__ );
define( 'SF_TYPOGRAF_PATH', plugin_dir_path( __FILE__ ) );
define( 'SF_TYPOGRAF_URL', plugin_dir_url( __FILE__ ) );

require_once SF_TYPOGRAF_PATH . 'includes/class-sf-typograf-engine.php';
require_once SF_TYPOGRAF_PATH . 'includes/class-sf-typograf-remote.php';
require_once SF_TYPOGRAF_PATH . 'includes/class-sf-typograf-fields.php';
require_once SF_TYPOGRAF_PATH . 'includes/class-sf-typograf-settings.php';
require_once SF_TYPOGRAF_PATH . 'includes/class-sf-typograf-admin.php';
require_once SF_TYPOGRAF_PATH . 'includes/class-sf-typograf-ajax.php';
require_once SF_TYPOGRAF_PATH . 'includes/class-sf-typograf.php';

/**
 * Основной экземпляр плагина.
 *
 * @return SF_Typograf
 */
function sf_typograf() {
	return SF_Typograf::instance();
}

sf_typograf();
