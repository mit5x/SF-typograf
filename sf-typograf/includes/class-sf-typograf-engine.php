<?php
/**
 * Локальный типограф.
 *
 * Реализует правила из «§ 62. Экранная типографика» (Артемий Лебедев):
 * спецсимволы, кавычки, тире, перенос слов, привязки, знаки в тексте.
 *
 * Работает без обращения к внешним сервисам, не изменяет HTML-разметку:
 * теги, комментарии, шорткоды, сущности, ссылки и содержимое
 * <pre>, <code>, <script>, <style> остаются нетронутыми.
 *
 * @package SF_Typograf
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SF_Typograf_Engine
 */
class SF_Typograf_Engine {

	/**
	 * Открывающий символ плейсхолдера защищённого фрагмента.
	 */
	const PH_OPEN = "\x01";

	/**
	 * Закрывающий символ плейсхолдера защищённого фрагмента.
	 */
	const PH_CLOSE = "\x02";

	/**
	 * Неразрывный пробел (U+00A0).
	 */
	const NBSP = "\xC2\xA0";

	/**
	 * Активные правила и настройки.
	 *
	 * @var array
	 */
	protected $options;

	/**
	 * Защищённые фрагменты текущего текстового узла.
	 *
	 * @var array
	 */
	protected $protected = array();

	/**
	 * Текущая глубина вложенности кавычек (сохраняется между узлами).
	 *
	 * @var int
	 */
	protected $quote_depth = 0;

	/**
	 * Контекст обработки: html|text.
	 *
	 * @var string
	 */
	protected $context = 'html';

	/**
	 * Теги, внутрь которых типограф не заходит.
	 *
	 * @var array
	 */
	protected static $skip_tags = array( 'script', 'style', 'pre', 'code', 'kbd', 'samp', 'var', 'textarea', 'nobr', 'tt' );

	/**
	 * Трёхбуквенные предлоги и союзы, привязываемые к следующему слову.
	 *
	 * Односимвольные и двухсимвольные слова привязываются все (см. § 62, п. 09).
	 *
	 * @var array
	 */
	protected static $short_words = array(
		'без', 'близ', 'вне', 'для', 'из', 'изо', 'как', 'меж', 'над', 'надо', 'обо', 'ото',
		'под', 'подо', 'пред', 'при', 'про', 'сквозь', 'среди', 'что', 'чем', 'или', 'либо',
		'ибо', 'дабы', 'если', 'чтобы', 'пока', 'лишь', 'хотя', 'уже', 'ещё', 'еще',
		'the', 'and', 'for', 'was', 'not',
	);

	/**
	 * Единицы измерения и сокращения, привязываемые к числу.
	 *
	 * @var array
	 */
	protected static $units = array(
		'тыс', 'млн', 'млрд', 'трлн', 'руб', 'коп', 'долл', 'евро',
		'км', 'см', 'мм', 'дм', 'кв', 'куб', 'га', 'кг', 'мг', 'мл', 'гр',
		'шт', 'чел', 'экз', 'стр', 'мин', 'сек', 'час', 'ч', 'мес',
		'г', 'гг', 'в', 'вв', 'м', 'л', 'т', 'с', 'р',
		'kg', 'km', 'cm', 'mm', 'ml', 'pc', 'pcs',
	);

	/**
	 * Названия месяцев (для привязки числа к месяцу).
	 *
	 * @var array
	 */
	protected static $months = array(
		'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля',
		'августа', 'сентября', 'октября', 'ноября', 'декабря',
		'январь', 'февраль', 'март', 'апрель', 'май', 'июнь', 'июль',
		'август', 'сентябрь', 'октябрь', 'ноябрь', 'декабрь',
	);

	/**
	 * Конструктор.
	 *
	 * @param array $options Настройки правил.
	 */
	public function __construct( $options = array() ) {
		$this->options = wp_parse_args(
			$options,
			array(
				'specials'     => true,  // Спецсимволы и знаки в тексте: (C) → ©.
				'quotes'       => true,  // Кавычки-ёлочки и лапки.
				'quotes_style' => 'ru',  // ru: «…» / „…“; en: “…” / ‘…’.
				'dashes'       => true,  // Тире вместо дефиса.
				'ranges'       => true,  // Числовые диапазоны через короткое тире.
				'nbsp'         => true,  // Привязки неразрывным пробелом.
				'spaces'       => true,  // Лишние пробелы и пробелы у знаков препинания.
				'nobr'         => false, // Неразрывные диапазоны <nobr> (только для HTML).
			)
		);
	}

	/**
	 * Возвращает текущие настройки правил.
	 *
	 * @return array
	 */
	public function get_options() {
		return $this->options;
	}

	/**
	 * Обрабатывает текст.
	 *
	 * @param string $text    Исходный текст (HTML или простой текст).
	 * @param string $context html — контент редактора, text — простое текстовое поле.
	 * @return string
	 */
	public function process( $text, $context = 'html' ) {
		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return $text;
		}

		$this->context     = ( 'text' === $context ) ? 'text' : 'html';
		$this->quote_depth = 0;

		// Простой текст без тегов обрабатываем целиком.
		if ( ! preg_match( '/<[a-z!\/][^>]*>/i', $text ) ) {
			return $this->process_text_node( $text );
		}

		$parts = preg_split(
			'/(<!--.*?-->|<!\[CDATA\[.*?\]\]>|<\?.*?\?>|<\/?[a-zA-Z][^>]*>)/s',
			$text,
			-1,
			PREG_SPLIT_DELIM_CAPTURE
		);

		if ( false === $parts ) {
			return $text;
		}

		$out  = '';
		$skip = 0;

		foreach ( $parts as $i => $part ) {
			if ( 1 === $i % 2 ) {
				$out .= $part;

				if ( preg_match( '/^<(\/?)([a-zA-Z0-9]+)/', $part, $m ) ) {
					$tag = strtolower( $m[2] );
					if ( in_array( $tag, self::$skip_tags, true ) ) {
						if ( '/' === $m[1] ) {
							$skip = max( 0, $skip - 1 );
						} elseif ( '/>' !== substr( $part, -2 ) ) {
							++$skip;
						}
					}
				}
				continue;
			}

			$out .= ( $skip > 0 ) ? $part : $this->process_text_node( $part );
		}

		return $out;
	}

	/**
	 * Обрабатывает один текстовый узел (без тегов).
	 *
	 * @param string $text Текстовый узел.
	 * @return string
	 */
	protected function process_text_node( $text ) {
		if ( '' === $text ) {
			return $text;
		}

		$this->protected = array();

		// Приводим неразрывные пробелы к единому виду, чтобы правила работали
		// одинаково на «сыром» тексте и на уже обработанном ранее.
		$text = preg_replace( '/&(?:nbsp|#160|#xA0|#x00A0);/i', self::NBSP, $text );

		$text = $this->protect_fragments( $text );

		if ( $this->options['specials'] ) {
			$text = $this->rule_specials( $text );
		}
		if ( $this->options['spaces'] ) {
			$text = $this->rule_spaces( $text );
		}
		if ( $this->options['quotes'] ) {
			$text = $this->rule_quotes( $text );
			if ( $this->options['spaces'] ) {
				$text = $this->rule_quote_spaces( $text );
			}
		}
		if ( $this->options['dashes'] ) {
			$text = $this->rule_dashes( $text );
		}
		if ( $this->options['ranges'] ) {
			$text = $this->rule_ranges( $text );
		}
		if ( $this->options['nbsp'] ) {
			$text = $this->rule_nbsp( $text );
		}
		if ( $this->options['nobr'] && 'html' === $this->context ) {
			$text = $this->rule_nobr( $text );
		}

		if ( 'html' === $this->context ) {
			$text = str_replace( self::NBSP, '&nbsp;', $text );
		}

		return $this->restore_fragments( $text );
	}

	/* ---------------------------------------------------------------------
	 * Защита фрагментов, которые нельзя трогать
	 * ------------------------------------------------------------------ */

	/**
	 * Заменяет неприкосновенные фрагменты на плейсхолдеры.
	 *
	 * @param string $text Текст.
	 * @return string
	 */
	protected function protect_fragments( $text ) {
		$patterns = array(
			// HTML-сущности: &nbsp; &#8212; &#x2014;.
			'/&(?:[a-zA-Z][a-zA-Z0-9]{1,15}|#\d{1,7}|#x[0-9a-fA-F]{1,6});/',
			// Шорткоды WordPress.
			'/\[\/?[a-zA-Z0-9_\-]+(?:[^\]]*)\]/',
			// Ссылки и e-mail.
			'~\b(?:https?://|ftp://|//|www\.)[^\s<>"\'\x{00AB}\x{00BB}]+~ui',
			'/\b[\w.+\-]+@[\w\-]+\.[\w.\-]+\b/u',
			// Шаблонные метки {{...}}, %s, %1$s.
			'/\{\{[^}]*\}\}/',
			'/%\d*\$?[sdf]/',
		);

		foreach ( $patterns as $pattern ) {
			$text = preg_replace_callback(
				$pattern,
				function ( $m ) {
					return $this->protect( $m[0] );
				},
				$text
			);
		}

		return $text;
	}

	/**
	 * Сохраняет фрагмент и возвращает плейсхолдер.
	 *
	 * @param string $fragment Фрагмент.
	 * @return string
	 */
	protected function protect( $fragment ) {
		$this->protected[] = $fragment;

		return self::PH_OPEN . ( count( $this->protected ) - 1 ) . self::PH_CLOSE;
	}

	/**
	 * Возвращает защищённые фрагменты на место.
	 *
	 * @param string $text Текст с плейсхолдерами.
	 * @return string
	 */
	protected function restore_fragments( $text ) {
		if ( empty( $this->protected ) ) {
			return $text;
		}

		return preg_replace_callback(
			'/' . self::PH_OPEN . '(\d+)' . self::PH_CLOSE . '/',
			function ( $m ) {
				$index = (int) $m[1];

				return isset( $this->protected[ $index ] ) ? $this->protected[ $index ] : $m[0];
			},
			$text
		);
	}

	/* ---------------------------------------------------------------------
	 * Правила
	 * ------------------------------------------------------------------ */

	/**
	 * Спецсимволы и знаки в тексте (§ 62, пп. 02, 16).
	 *
	 * @param string $text Текст.
	 * @return string
	 */
	protected function rule_specials( $text ) {
		$replacements = array(
			// (C) → ©, (R) → ®, (TM) → ™. Учитываем и кириллические буквы.
			'/\(\s*(?:c|с)\s*\)/iu'                => '©',
			'/\(\s*(?:r|р)\s*\)/iu'                => '®',
			'/\(\s*(?:tm|тм)\s*\)/iu'              => '™',
			'/\(\s*(?:p|р)\s*\)\s*(?=\d{4})/iu'    => '℗',
			// Многоточие.
			'/\.{3,}/u'                            => '…',
			// Плюс-минус.
			'/(?<![\p{L}\d])\+[\/]?-(?=\s?[\d\p{L}])/u' => '±',
			// Знак умножения между числами: 2x3 → 2×3.
			'/(?<=\d)\s?[xх]\s?(?=\d)/u'           => '×',
			// Стрелки-«минусы» из двух и трёх дефисов → тире.
			'/(?<!-)-{2,3}(?!-)/u'                 => '—',
			// Знак номера.
			'/(?<![\p{L}\d])(?:No|N)[°ºo]?\.?\s*(?=\d)/u' => '№ ',
			'/(?<![\p{L}\d])№\s*(?=\d)/u'          => '№ ',
			// Градусы: 20 ° C → 20 °C.
			'/(\d)\s*°\s*([CСF])(?![\p{L}])/u'     => '$1 °$2',
			'/(\d)\s+°(?![\p{L}])/u'               => '$1 °',
			// Знак параграфа.
			'/(?<![\p{L}\d])§\s*(?=\d)/u'          => '§ ',
			// Апостроф.
			'/(?<=\p{L})\'(?=\p{L})/u'             => '’',
		);

		foreach ( $replacements as $pattern => $replacement ) {
			$result = preg_replace( $pattern, $replacement, $text );
			if ( null !== $result ) {
				$text = $result;
			}
		}

		return $text;
	}

	/**
	 * Пробелы и знаки препинания.
	 *
	 * @param string $text Текст.
	 * @return string
	 */
	protected function rule_spaces( $text ) {
		$replacements = array(
			// Повторяющиеся пробелы и табуляции.
			'/[ \t]{2,}/u'                    => ' ',
			// Пробелы в конце строк.
			'/[ \t]+(\r?\n)/u'                => '$1',
			// Пробел перед знаками препинания.
			'/[ \t]+([,;:!?…])/u'             => '$1',
			// Пробел перед точкой (но не в числах вида «1 .5» — там точка+цифра).
			'/[ \t]+\.(?=[ \t\r\n]|$)/u'      => '.',
			// Пробел после запятой/точки с запятой перед буквой.
			'/([,;])(?=[\p{L}])/u'            => '$1 ',
			// Пробелы внутри скобок и кавычек.
			'/([(\[])[ \t]+/u'                => '$1',
			'/[ \t]+([)\]])/u'                => '$1',
		);

		foreach ( $replacements as $pattern => $replacement ) {
			$result = preg_replace( $pattern, $replacement, $text );
			if ( null !== $result ) {
				$text = $result;
			}
		}

		return $text;
	}

	/**
	 * Возвращает набор кавычек для выбранного стиля.
	 *
	 * @return array [открывающая, закрывающая, открывающая внутренняя, закрывающая внутренняя]
	 */
	protected function get_quote_glyphs() {
		if ( 'en' === $this->options['quotes_style'] ) {
			return array( '“', '”', '‘', '’' );
		}

		return array( '«', '»', '„', '“' );
	}

	/**
	 * Убирает лишние пробелы у кавычек с учётом выбранного стиля.
	 *
	 * @param string $text Текст.
	 * @return string
	 */
	protected function rule_quote_spaces( $text ) {
		list( $open1, $close1, $open2, $close2 ) = $this->get_quote_glyphs();

		$openers = preg_quote( $open1, '/' ) . '|' . preg_quote( $open2, '/' );
		$closers = preg_quote( $close1, '/' ) . '|' . preg_quote( $close2, '/' );

		$text = preg_replace( '/(' . $openers . ')[ \t]+/u', '$1', $text );
		$text = preg_replace( '/[ \t]+(' . $closers . ')/u', '$1', $text );

		return $text;
	}

	/**
	 * Кавычки (§ 62, пп. 03–05).
	 *
	 * Состояние вложенности сохраняется между текстовыми узлами,
	 * поэтому конструкции вида «<b>текст</b>» обрабатываются корректно.
	 *
	 * @param string $text Текст.
	 * @return string
	 */
	protected function rule_quotes( $text ) {
		list( $open1, $close1, $open2, $close2 ) = $this->get_quote_glyphs();

		$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $chars ) ) {
			return $text;
		}

		$openers   = array( '«', '„', '‘' );
		$closers   = array( '»', '”', '“' );
		$quotables = array_merge( array( '"' ), $openers, $closers );

		$out   = '';
		$total = count( $chars );

		for ( $i = 0; $i < $total; $i++ ) {
			$char = $chars[ $i ];

			if ( ! in_array( $char, $quotables, true ) ) {
				$out .= $char;
				continue;
			}

			$prev = ( $i > 0 ) ? $chars[ $i - 1 ] : '';
			$next = ( $i + 1 < $total ) ? $chars[ $i + 1 ] : '';

			if ( in_array( $char, $openers, true ) ) {
				$is_open = true;
			} elseif ( in_array( $char, $closers, true ) ) {
				$is_open = false;
			} else {
				// Прямая кавычка: решаем по окружению.
				$is_open = ( '' === $prev || $this->is_open_boundary( $prev ) );

				// Знак дюйма/минуты после числа вне кавычек не трогаем.
				if ( ! $is_open && $this->quote_depth < 1 && preg_match( '/\d/u', $prev ) ) {
					$out .= $char;
					continue;
				}
			}

			if ( $is_open ) {
				$out .= ( 0 === $this->quote_depth ) ? $open1 : $open2;
				++$this->quote_depth;
			} else {
				if ( $this->quote_depth > 0 ) {
					--$this->quote_depth;
				}
				$out .= ( 0 === $this->quote_depth ) ? $close1 : $close2;
			}

			unset( $next );
		}

		return $out;
	}

	/**
	 * Является ли символ границей, после которой кавычка — открывающая.
	 *
	 * @param string $char Символ.
	 * @return bool
	 */
	protected function is_open_boundary( $char ) {
		if ( '' === $char ) {
			return true;
		}

		if ( self::PH_OPEN === $char || self::PH_CLOSE === $char ) {
			return false;
		}

		return (bool) preg_match( '/[\s\x{00A0}([{<\-–—\/…]/u', $char );
	}

	/**
	 * Тире (§ 62, пп. 06–07).
	 *
	 * @param string $text Текст.
	 * @return string
	 */
	protected function rule_dashes( $text ) {
		// Тире в начале строки (прямая речь, списки).
		$text = preg_replace( '/(^|\r?\n)([ \t]*)[-–—][ \t]+/u', '$1$2— ', $text );

		// Тире между словами: слово&nbsp;— слово.
		$text = preg_replace( '/(\S)[ \t]+[-–—][ \t]+/u', '$1' . self::NBSP . '— ', $text );

		// Тире перед закрывающей кавычкой/скобкой не отрывается от текста.
		$text = preg_replace( '/(\S)[ \t]+—(?=[\r\n]|$)/u', '$1' . self::NBSP . '—', $text );

		return $text;
	}

	/**
	 * Числовые диапазоны через короткое тире: 1990—2000 → 1990–2000.
	 *
	 * Телефоны (212-85-06) и даты (2020-01-01) не затрагиваются.
	 *
	 * @param string $text Текст.
	 * @return string
	 */
	protected function rule_ranges( $text ) {
		return preg_replace(
			'/(?<![\d\-–—])(\d{1,4})[ \t]*[-–—][ \t]*(\d{1,4})(?![\d\-–—])/u',
			'$1–$2',
			$text
		);
	}

	/**
	 * Привязки неразрывным пробелом (§ 62, пп. 08–12).
	 *
	 * @param string $text Текст.
	 * @return string
	 */
	protected function rule_nbsp( $text ) {
		$nbsp  = self::NBSP;
		$short = implode( '|', array_map( 'preg_quote', self::$short_words ) );
		$units = implode( '|', array_map( 'preg_quote', self::$units ) );
		$mon   = implode( '|', array_map( 'preg_quote', self::$months ) );

		$rules = array(
			// Инициалы: В. И. Пупкин.
			'/(?<![\p{L}])([А-ЯЁA-Z])\.[ \t]*([А-ЯЁA-Z])\.[ \t]*(?=[А-ЯЁA-Z][а-яёa-z])/u' => '$1.' . $nbsp . '$2.' . $nbsp,
			'/(?<![\p{L}\.])([А-ЯЁA-Z])\.[ \t]+(?=[А-ЯЁA-Z][а-яёa-z])/u'                  => '$1.' . $nbsp,
			// Одно- и двухбуквенные слова, а также короткие предлоги и союзы.
			'/(?<![\p{L}\p{N}\-’\/])(' . $short . '|[\p{L}]{1,2})[ \t]+(?=[^\s])/ui'        => '$1' . $nbsp,
			// Частицы привязываются к предыдущему слову.
			'/[ \t]+(бы|б|же|ж|ли|ль)(?![\p{L}\p{N}])/u'                                  => $nbsp . '$1',
			// Число и единица измерения / сокращение.
			'/(\d)[ \t]+((?:' . $units . ')\.?)(?![\p{L}])/ui'                            => '$1' . $nbsp . '$2',
			// Число и месяц.
			'/(\d)[ \t]+(' . $mon . ')(?![\p{L}])/ui'                                     => '$1' . $nbsp . '$2',
			// Знаки номера, параграфа, процента, градуса, валюты.
			'/([№§])[ \t]*(?=\d)/u'                                                       => '$1' . $nbsp,
			'/(\d)[ \t]+([%‰°€$₽£])/u'                                                    => '$1' . $nbsp . '$2',
			'/(©|®|™)[ \t]+(?=[\p{L}\d])/u'                                               => '$1' . $nbsp,
			// Разряды в числах: 1 000 000.
			'/(?<=\d)[ \t](?=\d{3}(?![\d]))/u'                                            => $nbsp,
			// Сокращения: т. е., т. д., т. п., и т. д.
			'/(?<![\p{L}])т\.[ \t]*([едпнч])\./ui'                                        => 'т.' . $nbsp . '$1.',
			'/(?<![\p{L}])(и|а)[ \t]*т\.[ \t]*([дп])\./ui'                                => '$1' . $nbsp . 'т.' . $nbsp . '$2.',
			// г. Москва, ул. Ленина и т. п.
			'/(?<![\p{L}\.\x{00A0}])(г|ул|пр|пер|д|стр|корп|оф|кв|пл)\.[ \t]+(?=[А-ЯЁ\d])/u' => '$1.' . $nbsp,
		);

		foreach ( $rules as $pattern => $replacement ) {
			$result = preg_replace( $pattern, $replacement, $text );
			if ( null !== $result ) {
				$text = $result;
			}
		}

		// Тире не должно начинать строку: привязываем к предыдущему слову.
		$text = preg_replace( '/(\S)[ \t]+(—)/u', '$1' . $nbsp . '$2', $text );

		return $text;
	}

	/**
	 * Неразрывные диапазоны <nobr> (§ 62, пп. 13–15).
	 *
	 * @param string $text Текст.
	 * @return string
	 */
	protected function rule_nobr( $text ) {
		// Телефонные номера вида 212-85-06.
		$text = preg_replace(
			'/(?<![\d\-])(\d{3}-\d{2}-\d{2})(?![\d\-])/u',
			'<nobr>$1</nobr>',
			$text
		);

		// Слова через дефис: во-первых, из-за.
		$text = preg_replace(
			'/(?<![\p{L}\-])(\p{L}{1,3}-\p{L}{2,}|\p{L}{2,}-\p{L}{1,3})(?![\p{L}\-])/u',
			'<nobr>$1</nobr>',
			$text
		);

		return $text;
	}
}
