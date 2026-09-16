<?php

namespace QuadVector\DBAIRewriter;

/**
 * Конфигурация запуска DBAIRewriter.
 *
 * Batch-режим читается из input/config.json, а принудительный сброс
 * Batch-состояния включается консольным параметром --force-batch.
 */
final class DBAIRewriterConfig
{
	public function __construct(
		public readonly array $inputConfig = [],
		public readonly bool $showLogs = false,
		public readonly bool $forceBatch = false,
	) {}
}
