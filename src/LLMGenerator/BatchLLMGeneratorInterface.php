<?php

namespace QuadVector\DBAIRewriter\LLMGenerator;

/**
 * Дополнительный контракт для стратегий, поддерживающих пакетную обработку.
 *
 * Элемент $requests:
 *   ['id' => int|string, 'input' => string]
 *
 * Аргумент $onResult:
 *   callable(array{id: int|string, status: 'success'|'failed', content?: string, error?: string}): void
 */
interface BatchLLMGeneratorInterface extends LLMGeneratorInterface
{
	/**
	 * @param iterable<array{id: int|string, input: string}> $requests
	 * @param callable(array<string, mixed>): void $onResult
	 * @param int $maxWaitSeconds 0 означает ожидание без ограничения времени.
	 *
	 * @return array<string, mixed> Статистика текущего запуска.
	 */
	public function rewriteBatch(
		iterable $requests,
		callable $onResult,
		string $stateDirectory,
		bool $force = false,
		?string $model = null,
		float $temperature = 1.0,
		int $maxOutputTokens = 8000,
		int $pollIntervalSeconds = 60,
		int $maxWaitSeconds = 0,
		array $options = []
	): array;
}
