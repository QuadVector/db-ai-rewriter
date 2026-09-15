<?php

namespace QuadVector\DBAIRewriter;

use League\CLImate\CLImate;
use QuadVector\DBAIRewriter\DataSource\DataSourceInterface;
use QuadVector\DBAIRewriter\DBAIRewriterConfig;
use QuadVector\DBAIRewriter\LLMGenerator\LLMGeneratorInterface;

class DBAIRewriter
{
	private CLImate $cli;
	private DBAIRewriterConfig $config;
	private DataSourceInterface $dataSource;
	private LLMGeneratorInterface $llmGenerator;

	private const OUTPUT_DIR = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR .  'output';
	private const MAP_FILE = "map.jsonl";

	/**
	 * Коснтруктор
	 * 
	 * @param DBAIRewriterConfig $config Конфигурация для класса
	 * @param DataSourceInterface $dataSource Источник данных
	 * @param LLMGeneratorInterface $llmGenerator Генератор LLM
	 */
	public function __construct(
		DBAIRewriterConfig $config,
		DataSourceInterface $dataSource,
		LLMGeneratorInterface $llmGenerator,
	) {
		$this->config = $config;
		$this->dataSource = $dataSource;
		$this->llmGenerator = $llmGenerator;
		$this->cli = new CLImate();
	}

	/**
	 * Запуск генератора
	 */
	public function run(): void
	{
		$this->cli->clear();

		// выводим приветственное сообщение
		$this->cli->info("Starting DBAIRewriter...")->br();

		// выводим текущую конфигурацию
		$this->cli->info("Current configuration:");
		$data = [
			[
				'Show logs',
				$this->config->showLogs ? "Yes" : "No",
			],
			[
				'Data source',
				$this->config->inputConfig["data_source"],
			],
			[
				'DB name',
				$this->config->inputConfig["db_name"],
			],
			[
				'Table name',
				$this->config->inputConfig["table_name"],
			],
			[
				'ID column name',
				$this->config->inputConfig["id_column_name"],
			],
			[
				'Changing columns',
				implode(", ", array_map(function ($item) {
					return $item["column_name"];
				}, $this->config->inputConfig["columns"])),
			],
			[
				'AI provider',
				$this->config->inputConfig["ai_provider"],
			],
			[
				'AI model',
				$this->config->inputConfig["ai_model"],
			],
			[
				'AI temperature',
				$this->config->inputConfig["ai_temperature"],
			],
			[
				'AI max output tokens',
				$this->config->inputConfig["ai_max_output_tokens"],
			],
			[
				'Batch mode',
				$this->config->inputConfig["ai_batch"],
			],
			[
				'Force batch',
				$this->config->forceBatch ? "Yes" : "No",
			],
		];

		$this->cli->table($data);

		if ($this->llmGenerator->getProxy()) {
			$this->cli->info('<bold>Proxies:</bold>');
			$this->cli->table($this->llmGenerator->getProxy())->br();
		} else {
			$this->cli->br();
		}

		$this->cli->output("<bold><cyan>Output directory:</cyan></bold> " . self::OUTPUT_DIR)->br();

		$fileMapPath = self::OUTPUT_DIR . DIRECTORY_SEPARATOR . self::MAP_FILE;
		$this->cli->output("<bold><cyan>Map file path:</cyan></bold> " . $fileMapPath)->br();

		// проверяем выходную директорию
		if (!is_dir(self::OUTPUT_DIR)) {
			if ($this->config->showLogs) {
				$this->cli->error("Output directory doesn't exist");
				$this->cli->output("Creating output directory: " . self::OUTPUT_DIR)->br();
			}
			mkdir(self::OUTPUT_DIR, 0777, true);
		}

		// проверяем наличие карты для отслеживания обработанных строк
		if (!is_file($fileMapPath)) {
			if ($this->config->showLogs) {
				$this->cli->error("Map file doesn't exist");
				$this->cli->output("Creating map file: " . $fileMapPath)->br();
			}
			file_put_contents($fileMapPath, '', LOCK_EX);
		}

		// подсчитываем количество данных в таблице
		if ($this->config->showLogs) {
			$this->cli->output("Counting rows in the table...");
		}
		$rowsCount = $this->dataSource->count($this->config->inputConfig["table_name"]);

		$this->cli->output("<bold><cyan>Rows count:</cyan></bold> " . $rowsCount)->br();

		// начинаем обрабатывать строки
		if ($this->config->showLogs) {
			$this->cli->output("Starting rows processing...");
		}

		// счетчики
		$currentProgress = 0; // для подсчета прогресса
		$successCount = 0;
		$failedCount = 0;
		$skippedCount = 0;
		
		$progressBar = $this->cli->progress()->total($rowsCount);

		foreach ($this->dataSource->findAll($this->config->inputConfig["table_name"]) as $row) {
			$currentProgress++;
			$percent = $this->getProgressPercent($currentProgress, $rowsCount);
			if ($this->config->showLogs) {
				$this->cli->output(
					"<bold><green>[{$percent}%]</green></bold> "
						. "<bold><cyan>[{$currentProgress} / {$rowsCount}]</cyan></bold> "
						. "Processing row..."
				);
			} else {
				$progressBar->current(
					$currentProgress,
					"<bold><green>[{$percent}%]</green></bold> "
						. "<bold><cyan>[{$currentProgress} / {$rowsCount}]</cyan></bold> "
						. "Matching input files..."
				);
			}
		}
	}

	/**
	 * Получение процента выполнения
	 *
	 * @param int $current Текущее значение
	 * @param int $total Общее значение
	 * 
	 * @return int Процент выполнения
	 */
	private function getProgressPercent(int $current, int $total): int
	{
		return $total > 0 ? (int)round($current / $total * 100) : 0;
	}

	/**
	 * Сгенерировать промпт на основе шаблона и данных
	 * Переменные в промпте находятся в скобках - {{Переменная}}
	 *
	 * @param string $prompt Шаблон промпта
	 * @param array $data Данные для подстановки в шаблон
	 * 
	 * @return string Сгенерированный промпт
	 */
	private function generatePrompt(string $prompt, array $data): string
	{
		foreach ($data as $key => $value) {
			$prompt = str_replace("{{{$key}}}", $value, $prompt);
		}
		return $prompt;
	}

	/**
	 * Получить данные из карты по ключу
	 *
	 * @param string $fileMapPath Путь к файлу карты
	 * @param string $key Ключ для поиска
	 * 
	 * @return mixed Значение, соответствующее ключу, или null, если не найдено
	 */
	private function getMapData(string $fileMapPath, string $key): mixed
	{
		if (!is_file($fileMapPath)) {
			return null;
		}

		$handle = fopen($fileMapPath, 'rb');

		if ($handle === false) {
			return null;
		}

		$value = null;

		while (($line = fgets($handle)) !== false) {
			$entry = json_decode($line, true);

			if (is_array($entry) && ($entry['key'] ?? null) === $key) {
				$value = $entry['value'] ?? null;
			}
		}

		fclose($handle);

		return $value;
	}

	/**
	 * Добавить данные в карту по ключу
	 *
	 * @param string $fileMapPath Путь к файлу карты
	 * @param string $key Ключ для добавления
	 * @param mixed $value Значение для добавки
	 * 
	 * @return void
	 */
	private function addMapData(
		string $fileMapPath,
		int|string $id,
		string $key,
		mixed $value
	): void {
		$line = json_encode(
			['id' => $id, 'key' => $key, 'value' => $value],
			JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
		) . PHP_EOL;

		file_put_contents($fileMapPath, $line, FILE_APPEND | LOCK_EX);
	}

	/**
	 * Обновить данные в карте по идентификатору
	 *
	 * @param string $fileMapPath Путь к файлу карты
	 * @param int|string $id Идентификатор для обновления
	 * @param mixed $value Новое значение
	 * 
	 * @return bool true, если обновление прошло успешно, иначе false
	 */
	private function updateMapDataById(string $fileMapPath, int|string $id, mixed $value): bool
	{
		if (!is_file($fileMapPath)) {
			return false;
		}

		$temporaryPath = $fileMapPath . '.tmp';
		$input = fopen($fileMapPath, 'rb');
		$output = fopen($temporaryPath, 'wb');

		if ($input === false || $output === false) {
			if (is_resource($input)) {
				fclose($input);
			}

			if (is_resource($output)) {
				fclose($output);
			}

			return false;
		}

		$updated = false;

		while (($line = fgets($input)) !== false) {
			$entry = json_decode($line, true);

			if (is_array($entry) && ($entry['id'] ?? null) === $id) {
				$entry['value'] = $value;
				$line = json_encode(
					$entry,
					JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
				) . PHP_EOL;

				$updated = true;
			}

			fwrite($output, $line);
		}

		fclose($input);
		fclose($output);

		if (!$updated) {
			unlink($temporaryPath);
			return false;
		}

		rename($temporaryPath, $fileMapPath);

		return true;
	}
}
