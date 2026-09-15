<?php

namespace QuadVector\DBAIRewriter\LLMGenerator;

use QuadVector\DBAIRewriter\ValueObject\Proxy;

/**
 * Интерфейс для объектов, способных генерировать текст с использованием LLM.
 */
interface LLMGeneratorInterface
{
	/**
	 * Конструктор
	 * 
	 * @param string $apiKey Ключ API для доступа к OpenAI.
	 * @param string $projectID Идентификатор проекта OpenAI.
	 * @param Proxy[]|null $proxy Настройки прокси (необязательно).
	 */
	public function __construct(
		string $apiKey,
		string $projectID,
		?array $proxy = null
	);

	/**
	 * Возвращает настройки прокси.
	 *
	 * @return Proxy[]|null
	 */
	public function getProxy(): ?array;

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
	public function rewrite(string $input, ?string $model = null, float $temperature = 1.0, int $maxOutputTokens = 8000): string;
}
