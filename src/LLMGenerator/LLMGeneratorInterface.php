<?php

namespace QuadVector\DBAIRewriter\LLMGenerator;

use QuadVector\DBAIRewriter\ValueObject\Proxy;

/**
 * Общий контракт стратегии генерации текста.
 */
interface LLMGeneratorInterface
{
	/**
	 * @return Proxy[]|null
	 */
	public function getProxy(): ?array;

	public function rewrite(
		string $input,
		?string $model = null,
		float $temperature = 1.0,
		int $maxOutputTokens = 8000
	): string;
}
