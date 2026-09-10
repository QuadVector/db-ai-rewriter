<?php

namespace QuadVector\DBAIRewriter\Helper;

use InvalidArgumentException;

/**
 * Вспомогательный класс для работы с файлами и папками.
 */
final class File
{
	/**
	 * Рекурсивное удаление папки со всеми файлами
	 *
	 * @param string $dirPath Путь к папке
	 * @throws InvalidArgumentException
	 * @return void
	 */
	public static function removeDir(string $dirPath): void
	{
		if (!is_dir($dirPath)) {
			throw new InvalidArgumentException("$dirPath must be a directory");
		}

		if (substr($dirPath, strlen($dirPath) - 1, 1) != '/') {
			$dirPath .= '/';
		}

		$files = glob($dirPath . '*', GLOB_MARK);

		foreach ($files as $file) {
			if (is_dir($file)) {
				self::removeDir($file);
			} else {
				unlink($file);
			}
		}

		rmdir($dirPath);
	}
}
