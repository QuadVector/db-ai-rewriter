<?php

set_time_limit(0);
error_reporting(E_ERROR | E_PARSE);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

use QuadVector\DBAIRewriter\DBAIRewriter;
use QuadVector\DBAIRewriter\DBAIRewriterConfig;
use QuadVector\DBAIRewriter\DataSource\SQLiteDataSource;
use QuadVector\DBAIRewriter\LLMGenerator\OpenAILLMGenerator;
use QuadVector\DBAIRewriter\ValueObject\Proxy;

/**
 * Разбирает список прокси из --proxy или PROXY.
 * Поддерживаются оба разделителя: запятая и точка с запятой.
 *
 * @return string[]
 */
function parseProxyList(mixed $value): array
{
	if ($value === false || $value === null || trim((string) $value) === '') {
		return [];
	}

	$items = preg_split('/[;,]+/', trim((string) $value, " \t\n\r\0\x0B;,")) ?: [];
	$items = array_map('trim', $items);

	return array_values(array_filter(
		$items,
		static fn(string $url): bool => $url !== ''
	));
}

try {
	$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
	$dotenv->safeLoad();

	$inputOptions = getopt('', [
		'proxy:',
		'logs',
		'force-batch',
	]);

	if ($inputOptions === false) {
		throw new RuntimeException('Failed to parse command-line options.');
	}

	$showLogs = isset($inputOptions['logs']);
	$forceBatch = isset($inputOptions['force-batch']);

	// Переданный --proxy имеет приоритет над PROXY из .env.
	$proxyValues = array_key_exists('proxy', $inputOptions)
		? parseProxyList($inputOptions['proxy'])
		: parseProxyList($_ENV['PROXY'] ?? null);

	/** @var Proxy[] $proxies */
	$proxies = array_map(
		static fn(string $value): Proxy => Proxy::fromString($value),
		$proxyValues
	);

	$inputDir = __DIR__ . DIRECTORY_SEPARATOR . 'input';
	if (!is_dir($inputDir)) {
		throw new RuntimeException('Input directory not found.');
	}

	$inputConfigFile = $inputDir . DIRECTORY_SEPARATOR . 'config.json';
	if (!is_file($inputConfigFile)) {
		throw new RuntimeException('Input config file not found.');
	}

	$inputConfigJson = file_get_contents($inputConfigFile);
	if ($inputConfigJson === false) {
		throw new RuntimeException('Failed to read input config file.');
	}

	$inputConfig = json_decode(
		$inputConfigJson,
		true,
		512,
		JSON_THROW_ON_ERROR
	);

	if (!is_array($inputConfig)) {
		throw new RuntimeException('Input config must contain a JSON object.');
	}

	$dataSourceName = $inputConfig['data_source'] ?? null;
	$dataSource = match ($dataSourceName) {
		'sqlite' => new SQLiteDataSource(
			$inputDir
				. DIRECTORY_SEPARATOR
				. (string) ($inputConfig['db_name'] ?? '')
		),
		default => throw new InvalidArgumentException(
			"Unsupported data source: " . (is_scalar($dataSourceName) ? (string) $dataSourceName : 'unknown')
		),
	};

	$aiProvider = $inputConfig['ai_provider'] ?? null;
	$openAIKey = trim((string) ($_ENV['OPENAI_KEY'] ?? ''));
	$openAIProjectId = trim((string) ($_ENV['OPENAI_PROJECT_ID'] ?? ''));

	if ($aiProvider === 'openai' && $openAIKey === '') {
		throw new RuntimeException('OPENAI_KEY is not set in .env.');
	}

	$llmGenerator = match ($aiProvider) {
		'openai' => new OpenAILLMGenerator(
			apiKey: $openAIKey,
			projectID: $openAIProjectId,
			proxy: $proxies,
		),
		default => throw new InvalidArgumentException(
			"Unsupported AI provider: " . (is_scalar($aiProvider) ? (string) $aiProvider : 'unknown')
		),
	};

	$dbAIRewriter = new DBAIRewriter(
		new DBAIRewriterConfig(
			inputConfig: $inputConfig,
			showLogs: $showLogs,
			forceBatch: $forceBatch,
		),
		$dataSource,
		$llmGenerator
	);

	$dbAIRewriter->run();
} catch (Throwable $exception) {
	fwrite(STDERR, 'Error: ' . $exception->getMessage() . PHP_EOL);
	exit(1);
}
