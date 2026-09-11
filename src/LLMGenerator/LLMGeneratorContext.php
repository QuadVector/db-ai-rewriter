<?php

namespace QuadVector\DBAIRewriter\LLMGenerator;

/**
 * Контекст для работы с объектом, реализующим интерфейс LLMGeneratorInterface.
 */
class LLMGeneratorContext
{
	/**
	 * Конструктор
	 * @param \QuadVector\DBAIRewriter\LLMGenerator\LLMGeneratorInterface $generator Экземпляр генератора текста.
	 */
	public function __construct(
		private \QuadVector\DBAIRewriter\LLMGenerator\LLMGeneratorInterface $generator
	) {}

	/**
	 * Получает экземпляр генератора текста.
	 * @return \QuadVector\DBAIRewriter\LLMGenerator\LLMGeneratorInterface
	 */
	public function getGenerator(): \QuadVector\DBAIRewriter\LLMGenerator\LLMGeneratorInterface
	{
		return $this->generator;
	}

	/**
	 * Отправляет исходный текст в LLM и возвращает сгенерированный текст.
	 * 
	 * @param string $input Исходный текст для генерации.
	 * @param ?string $model Модель LLM для использования.
	 * @param float $temperature Температура генерации текста.
	 * @param int $maxOutputTokens Максимальное количество токенов в выходном тексте.
	 *
	 * @return string
	 */
	public function rewrite(string $input, ?string $model = null, float $temperature = 1.0, int $maxOutputTokens = 8000): string
	{
		return $this->getGenerator()->rewrite($input, $model, $temperature, $maxOutputTokens);
	}
}