<?php

namespace QuadVector\DBAIRewriter\LLMGenerator;

use GuzzleHttp\Client as GuzzleClient;
use OpenAI;
use QuadVector\DBAIRewriter\ValueObject\Proxy;

/**
 * Реализация интерфейса LLMGeneratorInterface с использованием OpenAI для генерации текста.
 */
class OpenAILLMGenerator implements LLMGeneratorInterface
{
	/**
	 * Конструктор
	 * 
	 * @param string $apiKey Ключ API для доступа к OpenAI.
	 * @param string $projectID Идентификатор проекта OpenAI.
	 * @param Proxy[]|null $proxy Настройки прокси (необязательно).
	 */
	public function __construct(
		private string $apiKey,
		private string $projectID,
		private ?array $proxy = null
	) {}

	/**
	 * Возвращает настройки прокси.
	 *
	 * @return Proxy[]|null
	 */
	public function getProxy(): ?array
	{
		return $this->proxy;
	}

	/**
	 * Отправляет исходный текст в LLM и возвращает сгенерированный текст.
	 * 
	 * @param string $input Исходный текст для генерации.
	 * @param ?string $model Модель LLM для использования.
	 * @param ?float $temperature Температура генерации текста.
	 * @param int $maxOutputTokens Максимальное количество токенов в выходном тексте.
	 *
	 * @return string
	 */
	public function rewrite(string $input, ?string $model = null, ?float $temperature = 1.0, int $maxOutputTokens = 8000): string
	{
		// получаем прокси
		$randomProxy = null;
		if (is_array($this->proxy) && count($this->proxy) > 0) {
			$randomProxy = $this->proxy[array_rand($this->proxy)];
		}

		$httpClientConfig = [];

		if (!is_null($randomProxy)) {
			$httpClientConfig["proxy"] = [
				"https" => "http://" . urlencode($randomProxy->login) . ":" .
					urlencode($randomProxy->password) . "@" . $randomProxy->ip . ":" . $randomProxy->port,
			];
		}

		// инициализируем клиент OpenAI
		$client = OpenAI::factory()
			->withApiKey($this->apiKey)
			->withProject($this->projectID)
			->withHttpClient(new GuzzleClient($httpClientConfig))
			->make();

		// делаем необходимый запрос
		$response = $client->responses()->create([
			'model' => $model ?? 'gpt-4o-mini',
			'input' => $input,
			'temperature' => $temperature ?? 1.0,
			'max_output_tokens' => $maxOutputTokens,
			'tool_choice' => 'auto',
			'store' => false,
		]);

		return $response['output'][0]['content'][0]['text'] ?? '';
	}
}
