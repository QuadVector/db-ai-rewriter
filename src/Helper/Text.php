<?php

namespace QuadVector\DBAIRewriter\Helper;

/**
 * Вспомогательный класс для работы с текстом.
 */
final class Text
{
	/**
	 * Сгенерировать ЧПУ транслит текста
	 * @param string $value Исходный текст
	 * @return string
	 */
	public static function translitRef(string $value): string
	{
		$converter = array(
			'а' => 'a',
			'б' => 'b',
			'в' => 'v',
			'г' => 'g',
			'д' => 'd',
			'е' => 'e',
			'ё' => 'e',
			'ж' => 'zh',
			'з' => 'z',
			'и' => 'i',
			'й' => 'y',
			'к' => 'k',
			'л' => 'l',
			'м' => 'm',
			'н' => 'n',
			'о' => 'o',
			'п' => 'p',
			'р' => 'r',
			'с' => 's',
			'т' => 't',
			'у' => 'u',
			'ф' => 'f',
			'х' => 'h',
			'ц' => 'c',
			'ч' => 'ch',
			'ш' => 'sh',
			'щ' => 'sch',
			'ь' => '',
			'ы' => 'y',
			'ъ' => '',
			'э' => 'e',
			'ю' => 'yu',
			'я' => 'ya',
		);

		$value = mb_strtolower($value);
		$value = strtr($value, $converter);
		$value = mb_ereg_replace('[^-0-9a-z]', '-', $value);
		$value = mb_ereg_replace('[-]+', '-', $value);
		$value = trim($value, '-');

		return $value;
	}

	/**
	 * Форматировать секунды в читаемый вид
	 * @param float|int|null $seconds
	 * @return string
	 */
	public static function formatDuration(float|int|null $seconds): string
	{
		if ($seconds === null) {
			return 'unknown';
		}

		$seconds = (float)$seconds;

		if (!is_finite($seconds) || $seconds < 0) {
			return 'unknown';
		}

		if ($seconds > 0 && $seconds < 1) {
			return round($seconds * 1000) . 'ms';
		}

		$totalSeconds = (int)round($seconds);

		$hours = intdiv($totalSeconds, 3600);
		$totalSeconds %= 3600;

		$minutes = intdiv($totalSeconds, 60);
		$totalSeconds %= 60;

		$parts = [];

		if ($hours > 0) {
			$parts[] = "{$hours}h";
		}

		if ($minutes > 0) {
			$parts[] = "{$minutes}m";
		}

		if ($totalSeconds > 0 || count($parts) === 0) {
			$parts[] = "{$totalSeconds}s";
		}

		return implode(' ', $parts);
	}

	/**
	 * Введен ли входной параметр в консоли
	 * @param array $argv Массив с входными параметрами CLI
	 * @param string $optionName Название параметра
	 * @return bool
	 */
	public static function cliOptionPassed(array $argv, string $optionName): bool
	{
		$option = '--' . $optionName;

		foreach ($argv as $arg) {
			if ($arg === $option) {
				return true;
			}

			if (str_starts_with($arg, $option . '=')) {
				return true;
			}
		}

		return false;
	}
}
