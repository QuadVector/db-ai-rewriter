<?php

set_time_limit(0);
error_reporting(E_ERROR | E_PARSE);

require_once("vendor/autoload.php");

use QuadVector\DBAIRewriter\ValueObject\Proxy;
use QuadVector\DBAIRewriter\Helper\Text;
use QuadVector\DBAIRewriter\DBAIRewriter;
use QuadVector\DBAIRewriter\DBAIRewriterConfig;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// обработка входных параметров
$inputOptions = getopt("", [
	"proxy:",
	"logs",
	"batch",
	"force-batch",
]);

$showLogs = isset($inputOptions["logs"]); // выводить логи
$batchMode = isset($inputOptions["batch"]); // массовая обработка
$forceBatch = isset($inputOptions["force-batch"]); // принудительная массовая обработка (удаление предыдущих данных)

// прокси
$proxy = [];

if (Text::cliOptionPassed($argv, "proxy")) {
	$proxyRaw = $inputOptions["proxy"] ?? "";

	if (
		$proxyRaw !== false
		&& trim((string)$proxyRaw) !== ""
	) {
		$proxy = explode(',', (string)$proxyRaw);
		$proxy = array_map('trim', $proxy);

		$proxy = array_filter(
			$proxy,
			function (string $url): bool {
				return $url !== '';
			}
		);

		$proxy = array_values($proxy);
	}
} elseif (isset($_ENV["PROXY"])) {
	$proxyRaw = $_ENV["PROXY"];

	if (
		$proxyRaw !== false
		&& trim((string)$proxyRaw) !== ""
	) {
		$proxy = explode(
			";",
			trim((string)$proxyRaw, ';')
		);

		$proxy = array_map('trim', $proxy);

		$proxy = array_filter(
			$proxy,
			function (string $url): bool {
				return $url !== '';
			}
		);

		$proxy = array_values($proxy);
	}
}

// инициализируем объекты прокси для дальнейшего использования в приложении
$proxy = array_map(
	function (string $item) {
		return Proxy::fromString($item);
	},
	$proxy
);

// открываем папку input для получения входных данных
$inputDir = __DIR__ .  DIRECTORY_SEPARATOR .  "input";
if (!is_dir($inputDir)) {
	die("Input directory not found.");
}

$inputConfigFile = $inputDir .  DIRECTORY_SEPARATOR .  "config.json";
if (file_exists($inputConfigFile) === false) {
	die("Input config file not found.");
}

// читаем конфигурацию из файла JSON
$inputConfig = json_decode(file_get_contents($inputConfigFile), true);
if ($inputConfig === null) {
	die("Failed to parse input config file.");
}

// определяем источник данных и генератор LLM на основе конфигурации
$dataSource = match ($inputConfig['data_source']) {
	'sqlite' => new QuadVector\DBAIRewriter\DataSource\SQLiteDataSource(
		$inputDir . DIRECTORY_SEPARATOR . $inputConfig['db_name']
	),
	default => throw new InvalidArgumentException('Unsupported data source'),
};

$llmGenerator = match ($inputConfig['ai_provider']) {
	'openai' => new QuadVector\DBAIRewriter\LLMGenerator\OpenAILLMGenerator(
		$_ENV['OPENAI_KEY'],
		$_ENV['OPENAI_PROJECT_ID'] ?? '',
		$proxy,
	),
	default => throw new InvalidArgumentException('Unsupported AI provider'),
};

// инициализация главного объекта генератора
$DBAIRewriter = new DBAIRewriter(
	new DBAIRewriterConfig(
		inputConfig: $inputConfig,
		showLogs: $showLogs,
		forceBatch: $forceBatch
	),
	$dataSource,
	$llmGenerator
);

$DBAIRewriter->run();
