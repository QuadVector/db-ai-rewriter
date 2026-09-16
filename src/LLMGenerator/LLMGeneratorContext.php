<?php

namespace QuadVector\DBAIRewriter\LLMGenerator;

use LogicException;
use QuadVector\DBAIRewriter\ValueObject\Proxy;

/**
 * Контекст выбранной стратегии генерации.
 */
final class LLMGeneratorContext
{
	public function __construct(
		private LLMGeneratorInterface $generator
	) {}

	public function getGenerator(): LLMGeneratorInterface
	{
		return $this->generator;
	}

	/**
	 * @return Proxy[]|null
	 */
	public function getProxy(): ?array
	{
		return $this->generator->getProxy();
	}

	public function rewrite(
		string $input,
		?string $model = null,
		float $temperature = 1.0,
		int $maxOutputTokens = 8000
	): string {
		return $this->generator->rewrite(
			$input,
			$model,
			$temperature,
			$maxOutputTokens
		);
	}

	public function supportsBatch(): bool
	{
		return $this->generator instanceof BatchLLMGeneratorInterface;
	}

	/**
	 * @param iterable<array{id: int|string, input: string}> $requests
	 * @param callable(array<string, mixed>): void $onResult
	 * @param int $maxWaitSeconds 0 означает ожидание без ограничения времени.
	 *
	 * @return array<string, mixed>
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
	): array {
		if (!$this->generator instanceof BatchLLMGeneratorInterface) {
			throw new LogicException(
				'The selected LLM strategy does not support Batch processing.'
			);
		}

		return $this->generator->rewriteBatch(
			$requests,
			$onResult,
			$stateDirectory,
			$force,
			$model,
			$temperature,
			$maxOutputTokens,
			$pollIntervalSeconds,
			$maxWaitSeconds,
			$options
		);
	}
}
