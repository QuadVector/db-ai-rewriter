<?php

namespace QuadVector\DBAIRewriter;

/**
 * Конструктор
 *
 * @param array $inputConfig Входная json конфигурация
 * @param bool $showLogs Выводить логи
 * @param bool $forceBatch Принудительная массовая обработка (удаление предыдущих данных)
 */
final class DBAIRewriterConfig
{
	public function __construct(
		public readonly array $inputConfig = [],
		public readonly bool $showLogs = false,
		public readonly bool $forceBatch = false,
	) {}
}
