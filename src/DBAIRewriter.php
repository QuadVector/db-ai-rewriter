<?php

namespace QuadVector\DBAIRewriter;

use Generator;
use League\CLImate\CLImate;
use QuadVector\DBAIRewriter\DataSource\DataSourceInterface;
use QuadVector\DBAIRewriter\LLMGenerator\LLMGeneratorContext;
use QuadVector\DBAIRewriter\LLMGenerator\LLMGeneratorInterface;
use RuntimeException;
use Throwable;

/**
 * Координатор обработки строк БД.
 *
 * Здесь нет OpenAI API: синхронная и Batch-генерация вызываются через
 * LLMGeneratorContext, а конкретная стратегия сама общается со своим API.
 */
class DBAIRewriter
{
	private const OUTPUT_DIR = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'output';
	private const MAP_FILE = 'map.jsonl';
	private const BATCH_DIR = 'batch';
	private const PROCESSING_MAP_SAVE_INTERVAL = 100;

	private CLImate $cli;
	private LLMGeneratorContext $llm;

	public function __construct(
		private DBAIRewriterConfig $config,
		private DataSourceInterface $dataSource,
		LLMGeneratorInterface $llmGenerator,
	) {
		$this->llm = new LLMGeneratorContext($llmGenerator);
		$this->cli = new CLImate();
	}

	public function run(): void
	{
		$this->cli->clear();
		$this->cli->info('Starting DBAIRewriter...')->br();

		$tableName = $this->getRequiredConfigString('table_name');
		$idColumnName = $this->getRequiredConfigString('id_column_name');
		$updateColumnName = $this->getRequiredConfigString('update_column_name');
		$promptTemplate = $this->getRequiredConfigString('update_column_prompt', false);

		$this->printConfiguration();
		$this->printProxies();

		$this->ensureDirectory(self::OUTPUT_DIR);
		$fileMapPath = self::OUTPUT_DIR . DIRECTORY_SEPARATOR . self::MAP_FILE;
		$this->ensureMapFileExists($fileMapPath);

		$this->cli->output('<bold><cyan>Output directory:</cyan></bold> ' . self::OUTPUT_DIR)->br();
		$this->cli->output('<bold><cyan>Map file path:</cyan></bold> ' . $fileMapPath)->br();

		// Последняя запись ID побеждает; затем файл сразу уплотняется до уникальных строк.
		$mapById = $this->loadLatestMapData($fileMapPath);
		$this->saveProcessingMap($fileMapPath, $mapById);

		if ($this->isBatchModeEnabled()) {
			$this->runBatch(
				$tableName,
				$idColumnName,
				$updateColumnName,
				$promptTemplate,
				$fileMapPath,
				$mapById
			);
			return;
		}

		$this->runSync(
			$tableName,
			$idColumnName,
			$updateColumnName,
			$promptTemplate,
			$fileMapPath,
			$mapById
		);
	}

	/**
	 * @param array<string, array<string, mixed>> $mapById
	 */
	private function runSync(
		string $tableName,
		string $idColumnName,
		string $updateColumnName,
		string $promptTemplate,
		string $fileMapPath,
		array &$mapById
	): void {
		$rowsCount = max(0, (int) $this->dataSource->count($tableName));
		$this->cli->output('<bold><cyan>Rows count:</cyan></bold> ' . $rowsCount)->br();

		$stats = [
			'scanned' => 0,
			'attempted' => 0,
			'success' => 0,
			'failed' => 0,
			'empty' => 0,
			'processed' => 0,
		];
		$fatalError = null;
		$progressBar = !$this->config->showLogs && $rowsCount > 0
			? $this->cli->progress()->total($rowsCount)
			: null;

		try {
			foreach ($this->dataSource->findAll($tableName) as $row) {
				$stats['scanned']++;
				$this->printProgress($stats['scanned'], $rowsCount, $progressBar, 'Processing rows...');

				$validation = $this->validateRow($row, $idColumnName, $updateColumnName);
				if (!$validation['valid']) {
					$stats['failed']++;
					$this->recordInvalidRow($validation, $mapById, $fileMapPath);
					continue;
				}

				$rowId = $validation['id'];
				if (($mapById[(string) $rowId]['status'] ?? null) === 'success') {
					$stats['processed']++;
					continue;
				}

				if ($this->isEmptyValue($row[$updateColumnName])) {
					$stats['empty']++;
					$this->setProcessingMapEntry($mapById, $rowId, [
						'status' => 'skipped',
						'reason' => 'empty_value',
					]);
					$this->saveProcessingMap($fileMapPath, $mapById);
					continue;
				}

				try {
					$stats['attempted']++;

					$updateValue = $this->llm->rewrite(
						$this->generatePrompt($promptTemplate, $row),
						$this->getRequiredConfigString('ai_model'),
						$this->getTemperature(),
						$this->getMaxOutputTokens()
					);

					if ($this->isEmptyValue($updateValue)) {
						throw new RuntimeException('AI returned an empty value.');
					}

					if (!$this->dataSource->update(
						$tableName,
						[$updateColumnName => $updateValue],
						[$idColumnName => $rowId]
					)) {
						throw new RuntimeException('Data source did not update the row.');
					}

					$stats['success']++;
					$this->setProcessingMapEntry($mapById, $rowId, [
						'status' => 'success',
						'mode' => 'sync',
					]);
				} catch (Throwable $exception) {
					$stats['failed']++;
					$error = $this->normalizeErrorMessage($exception->getMessage());
					$this->setProcessingMapEntry($mapById, $rowId, [
						'status' => 'failed',
						'reason' => 'processing_error',
						'error' => $error,
					]);
					$this->printRowError($rowId, $error);
				}

				$this->saveProcessingMap($fileMapPath, $mapById);
			}
		} catch (Throwable $exception) {
			$fatalError = $exception;
		} finally {
			$this->saveProcessingMap($fileMapPath, $mapById);
			$this->printSyncSummary($rowsCount, $stats, $fatalError);
		}

		if ($fatalError !== null) {
			throw $fatalError;
		}
	}

	/**
	 * DBAIRewriter формирует задания и сохраняет результаты в БД.
	 * JSONL, OpenAI Files/Batch API и polling полностью находятся в Batch-стратегии.
	 *
	 * @param array<string, array<string, mixed>> $mapById
	 */
	private function runBatch(
		string $tableName,
		string $idColumnName,
		string $updateColumnName,
		string $promptTemplate,
		string $fileMapPath,
		array &$mapById
	): void {
		if (!$this->llm->supportsBatch()) {
			throw new RuntimeException(
				'The selected LLM strategy does not implement BatchLLMGeneratorInterface.'
			);
		}

		$rowsCount = max(0, (int) $this->dataSource->count($tableName));
		$batchDirectory = $this->getBatchDirectory($tableName, $idColumnName, $updateColumnName);
		$this->cli->output('<bold><cyan>Rows count:</cyan></bold> ' . $rowsCount)->br();
		$this->cli->output('<bold><cyan>Batch state:</cyan></bold> ' . $batchDirectory)->br();

		$scanStats = [
			'scanned' => 0,
			'queued' => 0,
			'invalid' => 0,
			'empty' => 0,
			'processed' => 0,
		];
		$resultStats = ['success' => 0, 'failed' => 0, 'skipped' => 0];
		$changesSinceSave = 0;
		$fatalError = null;
		$batchStats = [];
		$progressBar = !$this->config->showLogs && $rowsCount > 0
			? $this->cli->progress()->total($rowsCount)
			: null;

		$requests = $this->createBatchRequests(
			$tableName,
			$idColumnName,
			$updateColumnName,
			$promptTemplate,
			$fileMapPath,
			$mapById,
			$scanStats,
			$changesSinceSave,
			$rowsCount,
			$progressBar
		);

		$onResult = function (array $result) use (
			$tableName,
			$idColumnName,
			$updateColumnName,
			$fileMapPath,
			&$mapById,
			&$resultStats,
			&$changesSinceSave
		): void {
			$rowId = $result['id'] ?? null;
			if (!is_int($rowId) && !is_string($rowId)) {
				$resultStats['failed']++;
				return;
			}

			if (($mapById[(string) $rowId]['status'] ?? null) === 'success') {
				$resultStats['skipped']++;
				return;
			}

			try {
				if (($result['status'] ?? null) !== 'success') {
					throw new RuntimeException(
						is_string($result['error'] ?? null)
							? $result['error']
							: 'Batch request failed.'
					);
				}

				$content = $result['content'] ?? null;
				if (!is_string($content) || trim($content) === '') {
					throw new RuntimeException('AI returned an empty Batch value.');
				}

				if (!$this->dataSource->update(
					$tableName,
					[$updateColumnName => $content],
					[$idColumnName => $rowId]
				)) {
					throw new RuntimeException('Data source did not update the row.');
				}

				$resultStats['success']++;
				$this->setProcessingMapEntry($mapById, $rowId, [
					'status' => 'success',
					'mode' => 'batch',
				]);
			} catch (Throwable $exception) {
				$resultStats['failed']++;
				$error = $this->normalizeErrorMessage($exception->getMessage());
				$this->setProcessingMapEntry($mapById, $rowId, [
					'status' => 'failed',
					'reason' => 'batch_error',
					'error' => $error,
				]);
				$this->printRowError($rowId, $error);
			}

			$changesSinceSave++;
			$this->saveProcessingMapPeriodically($fileMapPath, $mapById, $changesSinceSave);
		};

		try {
			$batchStats = $this->llm->rewriteBatch(
				$requests,
				$onResult,
				$batchDirectory,
				$this->isForceBatchEnabled(),
				$this->getRequiredConfigString('ai_model'),
				$this->getTemperature(),
				$this->getMaxOutputTokens(),
				$this->getPositiveIntConfig('ai_batch_poll_interval_seconds', 60),
				0, // без ограничения времени ожидания
				[
					'http_timeout' => $this->getPositiveIntConfig('ai_http_timeout', 300),
					'max_tokens_parameter' => $this->config->inputConfig['ai_batch_max_tokens_parameter'] ?? null,
					'event_handler' => function (string $event, array $context): void {
						$this->printBatchEvent($event, $context);
					},
				]
			);
		} catch (Throwable $exception) {
			$fatalError = $exception;
		} finally {
			$this->saveProcessingMap($fileMapPath, $mapById);
			$this->printBatchSummary($rowsCount, $scanStats, $resultStats, $batchStats, $fatalError);
		}

		if ($fatalError !== null) {
			throw $fatalError;
		}
	}

	/**
	 * @param array<string, array<string, mixed>> $mapById
	 * @param array<string, int> $scanStats
	 * @return Generator<int, array{id: int|string, input: string}>
	 */
	private function createBatchRequests(
		string $tableName,
		string $idColumnName,
		string $updateColumnName,
		string $promptTemplate,
		string $fileMapPath,
		array &$mapById,
		array &$scanStats,
		int &$changesSinceSave,
		int $rowsCount,
		mixed $progressBar
	): Generator {
		foreach ($this->dataSource->findAll($tableName) as $row) {
			$scanStats['scanned']++;
			$this->printProgress(
				$scanStats['scanned'],
				$rowsCount,
				$progressBar,
				'Building Batch requests...'
			);

			$validation = $this->validateRow($row, $idColumnName, $updateColumnName);
			if (!$validation['valid']) {
				$scanStats['invalid']++;
				$this->recordInvalidRow($validation, $mapById, $fileMapPath);
				continue;
			}

			$rowId = $validation['id'];
			if (($mapById[(string) $rowId]['status'] ?? null) === 'success') {
				$scanStats['processed']++;
				continue;
			}

			if ($this->isEmptyValue($row[$updateColumnName])) {
				$scanStats['empty']++;
				$this->setProcessingMapEntry($mapById, $rowId, [
					'status' => 'skipped',
					'reason' => 'empty_value',
				]);
				$changesSinceSave++;
				$this->saveProcessingMapPeriodically($fileMapPath, $mapById, $changesSinceSave);
				continue;
			}

			try {
				$prompt = $this->generatePrompt($promptTemplate, $row);
			} catch (Throwable $exception) {
				$scanStats['invalid']++;
				$this->setProcessingMapEntry($mapById, $rowId, [
					'status' => 'failed',
					'reason' => 'prompt_error',
					'error' => $this->normalizeErrorMessage($exception->getMessage()),
				]);
				$changesSinceSave++;
				$this->saveProcessingMapPeriodically($fileMapPath, $mapById, $changesSinceSave);
				continue;
			}

			$scanStats['queued']++;
			yield ['id' => $rowId, 'input' => $prompt];
		}

		$this->saveProcessingMap($fileMapPath, $mapById);
		$changesSinceSave = 0;
	}

	/**
	 * @param array{valid: bool, id: int|string|null, error: string} $validation
	 * @param array<string, array<string, mixed>> $mapById
	 */
	private function recordInvalidRow(array $validation, array &$mapById, string $fileMapPath): void
	{
		$this->printRowError($validation['id'], $validation['error']);

		if ($validation['id'] !== null) {
			$this->setProcessingMapEntry($mapById, $validation['id'], [
				'status' => 'failed',
				'reason' => 'invalid_row',
				'error' => $validation['error'],
			]);
			$this->saveProcessingMap($fileMapPath, $mapById);
		}
	}

	private function getBatchDirectory(
		string $tableName,
		string $idColumnName,
		string $updateColumnName
	): string {
		$identity = implode('|', [
			(string) ($this->config->inputConfig['data_source'] ?? ''),
			(string) ($this->config->inputConfig['db_name'] ?? ''),
			$tableName,
			$idColumnName,
			$updateColumnName,
			$this->getRequiredConfigString('ai_model'),
		]);

		return self::OUTPUT_DIR
			. DIRECTORY_SEPARATOR
			. self::BATCH_DIR
			. DIRECTORY_SEPARATOR
			. substr(hash('sha256', $identity), 0, 24);
	}

	private function isBatchModeEnabled(): bool
	{
		return $this->isForceBatchEnabled()
			|| $this->toBoolean($this->config->inputConfig['ai_batch'] ?? false);
	}

	private function isForceBatchEnabled(): bool
	{
		return $this->config->forceBatch;
	}

	private function getTemperature(): float
	{
		$value = $this->config->inputConfig['ai_temperature'] ?? 1.0;
		if (!is_int($value) && !is_float($value) && !is_numeric($value)) {
			throw new RuntimeException("Configuration option 'ai_temperature' must be numeric.");
		}

		return (float) $value;
	}

	private function getMaxOutputTokens(): int
	{
		$value = (int) ($this->config->inputConfig['ai_max_output_tokens'] ?? 8000);
		if ($value <= 0) {
			throw new RuntimeException("Configuration option 'ai_max_output_tokens' must be positive.");
		}

		return $value;
	}

	private function getPositiveIntConfig(string $key, int $default): int
	{
		$value = (int) ($this->config->inputConfig[$key] ?? $default);
		return max(1, $value);
	}

	private function printConfiguration(): void
	{
		if ($this->isBatchModeEnabled()) {
			$this->cli->output('<green><bold>Start DBAIRewriter (BATCH mode)...</bold></green>');
			$this->cli->output('<bold>Show logs:</bold>\t ' . ($this->config->showLogs ? 'Yes' : 'No'));
			$this->cli->output('<bold>Data source:</bold>\t ' . ($this->config->inputConfig['data_source'] ?? ''));
			$this->cli->output('<bold>DB name:</bold>\t ' . ($this->config->inputConfig['db_name'] ?? ''));
			$this->cli->output('<bold>Table name:</bold>\t ' . ($this->config->inputConfig['table_name'] ?? ''));
			$this->cli->output('<bold>AI provider:</bold>\t ' . ($this->config->inputConfig['ai_provider'] ?? ''));
			$this->cli->output('<bold>AI model:</bold>\t ' . ($this->config->inputConfig['ai_model'] ?? ''));
			$this->cli->output('<bold>Force batch:</bold>\t ' . ($this->isForceBatchEnabled() ? 'Yes' : 'No'));
			$this->cli->output(
				'<bold>Poll interval:</bold>\t '
					. $this->getPositiveIntConfig('ai_batch_poll_interval_seconds', 60)
					. 's'
			);
			$this->cli->br();
			return;
		}

		$this->cli->info('Current configuration:');
		$this->cli->table([
			['Show logs', $this->config->showLogs ? 'Yes' : 'No'],
			['Data source', $this->config->inputConfig['data_source'] ?? ''],
			['DB name', $this->config->inputConfig['db_name'] ?? ''],
			['Table name', $this->config->inputConfig['table_name'] ?? ''],
			['ID column name', $this->config->inputConfig['id_column_name'] ?? ''],
			['Update column name', $this->config->inputConfig['update_column_name'] ?? ''],
			['AI provider', $this->config->inputConfig['ai_provider'] ?? ''],
			['AI model', $this->config->inputConfig['ai_model'] ?? ''],
			['AI temperature', $this->config->inputConfig['ai_temperature'] ?? 1.0],
			['AI max output tokens', $this->config->inputConfig['ai_max_output_tokens'] ?? 8000],
			['Batch mode', $this->isBatchModeEnabled() ? 'Yes' : 'No'],
			['Force batch', $this->isForceBatchEnabled() ? 'Yes' : 'No'],
		]);
	}

	private function printProxies(): void
	{
		$proxies = $this->llm->getProxy();
		if ($proxies) {
			$this->cli->info('<bold>Proxies:</bold>');
			$this->cli->table($proxies)->br();
			return;
		}

		$this->cli->br();
	}

	/**
	 * @param array<string, int> $stats
	 */
	private function printSyncSummary(int $rowsCount, array $stats, ?Throwable $fatalError): void
	{
		$this->cli->br()->info('Processing summary:');
		$this->cli->table([
			['Rows in table', $rowsCount],
			['Rows scanned', $stats['scanned']],
			['Generation attempts', $stats['attempted']],
			['Successful updates', $stats['success']],
			['Failed rows', $stats['failed']],
			['Empty rows skipped', $stats['empty']],
			['Already processed', $stats['processed']],
		]);

		if ($fatalError !== null) {
			$this->cli->error('Fatal error: ' . $fatalError->getMessage());
		}
	}

	/**
	 * @param array<string, int> $scanStats
	 * @param array<string, int> $resultStats
	 * @param array<string, mixed> $batchStats
	 */
	private function printBatchSummary(
		int $rowsCount,
		array $scanStats,
		array $resultStats,
		array $batchStats,
		?Throwable $fatalError
	): void {
		$batchStatuses = $batchStats['batch_statuses'] ?? [];
		$this->cli->br();
		$this->cli->output('<bold><cyan>Batch processing summary:</cyan></bold>');
		$this->cli->output("<bold><cyan>Rows in table:</cyan></bold> {$rowsCount}");
		$this->cli->output("<bold><cyan>Rows scanned this run:</cyan></bold> {$scanStats['scanned']}");
		$this->cli->output("<bold><green>Rows queued this run:</green></bold> {$scanStats['queued']}");
		$this->cli->output("<bold><red>Invalid rows:</red></bold> {$scanStats['invalid']}");
		$this->cli->output("<bold><yellow>Empty rows skipped:</yellow></bold> {$scanStats['empty']}");
		$this->cli->output("<bold><yellow>Already processed:</yellow></bold> {$scanStats['processed']}");
		$this->cli->output(
			'<bold><cyan>Batch session resumed:</cyan></bold> '
				. (!empty($batchStats['resumed']) ? 'Yes' : 'No')
		);
		$this->cli->output('<bold><cyan>Requests in Batch map:</cyan></bold> ' . ($batchStats['queued'] ?? 0));
		$this->cli->output('<bold><cyan>Batch chunks:</cyan></bold> ' . ($batchStats['chunks'] ?? 0));
		$this->cli->output('<bold><green>Batches submitted:</green></bold> ' . ($batchStats['submitted'] ?? 0));
		$this->cli->output('<bold><yellow>Batches skipped on submit:</yellow></bold> ' . ($batchStats['submit_skipped'] ?? 0));
		$this->cli->output('<bold><yellow>Batches still waiting:</yellow></bold> ' . ($batchStats['waiting'] ?? 0));
		$this->cli->output("<bold><green>Successful DB updates:</green></bold> {$resultStats['success']}");
		$this->cli->output("<bold><red>Failed results:</red></bold> {$resultStats['failed']}");
		$this->cli->output("<bold><yellow>Results already applied:</yellow></bold> {$resultStats['skipped']}");

		foreach ($batchStatuses as $status => $count) {
			$this->cli->output(
				'<bold>Batch status ' . $this->formatBatchStatus((string) $status) . ':</bold> ' . (int) $count
			);
		}

		if ($fatalError !== null) {
			$this->cli->error('Fatal error: ' . $fatalError->getMessage());
		}

		$this->cli->br();
	}

	/**
	 * Цветной построчный вывод событий Batch-стратегии.
	 *
	 * @param array<string, mixed> $context
	 */
	private function printBatchEvent(string $event, array $context): void
	{
		$chunk = (string) ($context['chunk'] ?? '-');

		switch ($event) {
			case 'batch_started':
				$this->cli->output('<bold><green>Batch processing started.</green></bold>');
				$this->cli->output(
					'<bold><cyan>Batch poll interval:</cyan></bold> '
						. (int) ($context['poll_interval_seconds'] ?? 60)
						. 's'
				);
				break;

			case 'force_reset':
				$this->cli->output(
					'<bold><red>Force Batch state reset:</red></bold> '
						. (string) ($context['state_directory'] ?? '')
				);
				break;

			case 'previous_session_saved':
				$this->cli->output('<yellow>Previous Batch session is fully saved. Building a new session.</yellow>');
				break;

			case 'session_resumed':
				$this->cli->output('<bold><yellow>Existing unfinished Batch session found.</yellow></bold>');
				$this->cli->output('<bold><cyan>Existing requests:</cyan></bold> ' . (int) ($context['queued'] ?? 0));
				$this->cli->output('<bold><cyan>Existing chunks:</cyan></bold> ' . (int) ($context['chunks'] ?? 0));
				$this->cli->br();
				break;

			case 'build_started':
				$this->cli->output('<bold><yellow>Building JSONL chunks...</yellow></bold>');
				break;

			case 'chunk_started':
				if ($this->config->showLogs) {
					$this->cli->output("<dim>Started {$chunk}.</dim>");
				}
				break;

			case 'chunk_saved':
				$this->cli->output(
					"<green>Saved {$chunk}:</green> "
						. (int) ($context['requests'] ?? 0)
						. ' requests, '
						. (int) ($context['bytes'] ?? 0)
						. ' bytes'
				);
				break;

			case 'build_finished':
				$this->cli->output('<bold><green>Finished building JSONL chunks.</green></bold>');
				$this->cli->output('<bold><green>Queued requests:</green></bold> ' . (int) ($context['queued'] ?? 0));
				$this->cli->output('<bold><cyan>Total chunks:</cyan></bold> ' . (int) ($context['chunks'] ?? 0));
				$this->cli->br();
				break;

			case 'nothing_to_submit':
				$this->cli->output('<yellow>No Batch requests found for submit.</yellow>');
				break;

			case 'submit_started':
				$this->cli->output('<bold><yellow>Submitting JSONL chunks to OpenAI Batch API...</yellow></bold>');
				$this->cli->output('<bold><cyan>Total chunks:</cyan></bold> ' . (int) ($context['total'] ?? 0));
				break;

			case 'submit_skipped':
				if ($this->config->showLogs) {
					$this->cli->output(
						"<yellow>Skipping {$chunk}; local status:</yellow> "
							. $this->formatBatchStatus((string) ($context['status'] ?? 'unknown'))
					);
				}
				break;

			case 'chunk_submitting':
				$this->cli->output("Submitting <cyan>{$chunk}</cyan>...");
				break;

			case 'chunk_submitted':
				$this->cli->output(
					"<green>Submitted {$chunk}:</green> "
						. '<cyan>' . (string) ($context['batch_id'] ?? '') . '</cyan>'
						. ', status '
						. $this->formatBatchStatus((string) ($context['status'] ?? 'submitted'))
				);
				break;

			case 'chunk_submit_failed':
				$this->cli->output(
					"<red>Submit failed for {$chunk}:</red> " . (string) ($context['error'] ?? 'Unknown error')
				);
				break;

			case 'submit_finished':
				$this->cli->output('<bold><green>Finished submitting JSONL chunks.</green></bold>');
				$this->cli->output('<bold><green>Submitted batches:</green></bold> ' . (int) ($context['submitted'] ?? 0));
				$this->cli->output('<bold><yellow>Skipped batches:</yellow></bold> ' . (int) ($context['skipped'] ?? 0));
				$this->cli->output('<bold><red>Failed batches:</red></bold> ' . (int) ($context['failed'] ?? 0));
				$this->cli->br();
				break;

			case 'submit_retry_sleep':
				$this->cli->output(
					'<yellow>Pending chunks were not accepted: '
						. (int) ($context['pending'] ?? 0)
						. '. Retrying submission in '
						. (int) ($context['seconds'] ?? 60)
						. 's...</yellow>'
				);
				$this->cli->br();
				break;

			case 'collect_started':
				$this->cli->output('<bold><yellow>Collecting OpenAI Batch results...</yellow></bold>');
				$this->cli->output('<bold><cyan>Total batches:</cyan></bold> ' . (int) ($context['total'] ?? 0));
				$this->cli->br();
				break;

			case 'poll_iteration_started':
				$this->cli->output(
					'<bold><cyan>Collect iteration:</cyan></bold> ' . (int) ($context['iteration'] ?? 0)
				);
				break;

			case 'chunk_status':
				if ($this->config->showLogs) {
					$this->cli->output(
						"<dim>[{$chunk}]</dim> Current local status: "
							. $this->formatBatchStatus((string) ($context['status'] ?? 'unknown'))
					);
				}
				break;

			case 'chunk_already_saved':
				if ($this->config->showLogs) {
					$this->cli->output("<green>{$chunk} already saved. Skipping...</green>");
				}
				break;

			case 'chunk_pending_submit':
				$this->cli->output("<yellow>{$chunk} is pending and will be submitted on the next run.</yellow>");
				break;

			case 'chunk_retrieving':
				if ($this->config->showLogs) {
					$this->cli->output(
						"Retrieving <cyan>{$chunk}</cyan>, batch <cyan>"
							. (string) ($context['batch_id'] ?? '')
							. '</cyan>...'
					);
				}
				break;

			case 'chunk_remote_status':
				$this->cli->output(
					"<dim>[{$chunk}]</dim> OpenAI status: "
						. $this->formatBatchStatus((string) ($context['status'] ?? 'unknown'))
				);
				break;

			case 'chunk_completed':
				$this->cli->output("<bold><green>{$chunk} completed. Saving results...</green></bold>");
				break;

			case 'chunk_results_saved':
				$this->cli->output(
					"<green>{$chunk} saved:</green> "
						. '<green>' . (int) ($context['success'] ?? 0) . ' successful</green>, '
						. '<red>' . (int) ($context['failed'] ?? 0) . ' failed</red>'
				);
				break;

			case 'chunk_terminal_failure':
				$this->cli->output(
					"<red>{$chunk} ended with status "
						. (string) ($context['status'] ?? 'failed')
						. '. It will be retried on the next run.</red>'
				);
				break;

			case 'chunk_collect_failed':
				$this->cli->output(
					"<red>Collect error for {$chunk}:</red> " . (string) ($context['error'] ?? 'Unknown error')
				);
				break;

			case 'error_file_saved':
				$this->cli->output(
					"Error file for <yellow>{$chunk}</yellow> saved: <yellow>"
						. (string) ($context['path'] ?? '')
						. '</yellow>'
				);
				break;

			case 'error_file_save_failed':
				$this->cli->output(
					"<red>Failed to save error file for {$chunk}:</red> "
						. (string) ($context['error'] ?? 'Unknown error')
				);
				break;

			case 'poll_iteration_finished':
				$this->cli->output('<bold><cyan>Collect status:</cyan></bold>');
				foreach (($context['statuses'] ?? []) as $status => $count) {
					$this->cli->output(
						'<bold>' . $this->formatBatchStatus((string) $status) . ' batches:</bold> ' . (int) $count
					);
				}
				$this->cli->output('<bold><green>Total saved results:</green></bold> ' . (int) ($context['saved_results'] ?? 0));
				$this->cli->output('<bold><red>Total failed results:</red></bold> ' . (int) ($context['failed_results'] ?? 0));
				$this->cli->br();
				break;

			case 'poll_sleep':
				$this->cli->output(
					'<dim>Sleeping ' . (int) ($context['seconds'] ?? 0) . 's before next check...</dim>'
				);
				$this->cli->br();
				break;

			case 'all_batches_collected':
				$this->cli->output('<bold><green>All available batches collected.</green></bold>');
				break;

			case 'max_wait_reached':
				$this->cli->output('<yellow>Maximum Batch wait time reached. Stopping collect.</yellow>');
				break;

			case 'batch_finished':
				$this->cli->output('<bold><green>Batch mode finished.</green></bold>');
				break;
		}
	}

	private function formatBatchStatus(string $status): string
	{
		$color = match ($status) {
			'saved', 'completed' => 'green',
			'failed', 'expired', 'cancelled' => 'red',
			default => 'yellow',
		};

		return "<{$color}>{$status}</{$color}>";
	}

	private function printProgress(int $current, int $total, mixed $progressBar, string $message): void
	{
		$percent = $total > 0 ? min(100, (int) round($current / $total * 100)) : 0;

		if ($this->config->showLogs) {
			$this->cli->output(
				"<dim>[{$percent}%]</dim> <dim>[{$current} / {$total}]</dim> {$message}"
			);
			return;
		}

		if ($progressBar !== null) {
			$progressBar->current(
				min($current, max(1, $total)),
				"{$message} [{$current} / {$total}] [{$percent}%]"
			);
		}
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function generatePrompt(string $prompt, array $data): string
	{
		foreach ($data as $key => $value) {
			$prompt = str_replace(
				'{{' . $key . '}}',
				$this->stringifyPromptValue($value),
				$prompt
			);
		}

		return $prompt;
	}

	private function stringifyPromptValue(mixed $value): string
	{
		if ($value === null) {
			return '';
		}

		if (is_string($value) || is_int($value) || is_float($value)) {
			return (string) $value;
		}

		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}

		return json_encode(
			$value,
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
		);
	}

	private function isEmptyValue(mixed $value): bool
	{
		return $value === null || (is_string($value) && trim($value) === '');
	}

	/**
	 * @return array{valid: bool, id: int|string|null, error: string}
	 */
	private function validateRow(mixed $row, string $idColumnName, string $updateColumnName): array
	{
		if (!is_array($row)) {
			return ['valid' => false, 'id' => null, 'error' => 'Data source returned a row that is not an array.'];
		}

		if (!array_key_exists($idColumnName, $row)) {
			return ['valid' => false, 'id' => null, 'error' => "ID column '{$idColumnName}' is missing in the row."];
		}

		$rowId = $row[$idColumnName];
		if (!is_int($rowId) && !is_string($rowId)) {
			return ['valid' => false, 'id' => null, 'error' => "ID column '{$idColumnName}' must contain an integer or string."];
		}

		if (is_string($rowId) && trim($rowId) === '') {
			return ['valid' => false, 'id' => null, 'error' => "ID column '{$idColumnName}' cannot be empty."];
		}

		if (!array_key_exists($updateColumnName, $row)) {
			return [
				'valid' => false,
				'id' => $rowId,
				'error' => "Update column '{$updateColumnName}' is missing in the row.",
			];
		}

		return ['valid' => true, 'id' => $rowId, 'error' => ''];
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function loadLatestMapData(string $fileMapPath): array
	{
		$handle = fopen($fileMapPath, 'rb');
		if ($handle === false) {
			throw new RuntimeException("Unable to open map file: {$fileMapPath}");
		}

		$mapById = [];
		$lineNumber = 0;

		try {
			while (($line = fgets($handle)) !== false) {
				$lineNumber++;
				$line = trim($line);
				if ($line === '') {
					continue;
				}

				try {
					$entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
				} catch (Throwable $exception) {
					throw new RuntimeException(
						"Invalid JSON in map file on line {$lineNumber}.",
						0,
						$exception
					);
				}

				$id = is_array($entry) ? ($entry['id'] ?? null) : null;
				if (!is_array($entry) || (!is_int($id) && !is_string($id))) {
					throw new RuntimeException("Invalid map entry on line {$lineNumber}.");
				}

				$mapById[(string) $id] = $entry;
			}
		} finally {
			fclose($handle);
		}

		return $mapById;
	}

	/**
	 * Перезаписывает карту целиком: одна JSONL-строка на один ID.
	 *
	 * @param array<string, array<string, mixed>> $mapById
	 */
	private function saveProcessingMap(string $fileMapPath, array $mapById): void
	{
		$lines = [];
		foreach ($mapById as $entry) {
			$lines[] = json_encode(
				$entry,
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
			);
		}

		$content = $lines === [] ? '' : implode(PHP_EOL, $lines) . PHP_EOL;
		$written = file_put_contents($fileMapPath, $content, LOCK_EX);
		if ($written === false || $written !== strlen($content)) {
			throw new RuntimeException("Unable to write map file: {$fileMapPath}");
		}
	}

	/**
	 * @param array<string, array<string, mixed>> $mapById
	 * @param array<string, mixed> $data
	 */
	private function setProcessingMapEntry(array &$mapById, int|string $id, array $data): void
	{
		unset($data['id'], $data['updated_at']);
		$mapById[(string) $id] = [
			'id' => $id,
			'updated_at' => date(DATE_ATOM),
		] + $data;
	}

	/**
	 * @param array<string, array<string, mixed>> $mapById
	 */
	private function saveProcessingMapPeriodically(
		string $fileMapPath,
		array $mapById,
		int &$changesSinceSave
	): void {
		if ($changesSinceSave < self::PROCESSING_MAP_SAVE_INTERVAL) {
			return;
		}

		$this->saveProcessingMap($fileMapPath, $mapById);
		$changesSinceSave = 0;
	}

	private function ensureMapFileExists(string $fileMapPath): void
	{
		if (!is_file($fileMapPath)) {
			$written = file_put_contents($fileMapPath, '');
			if ($written === false) {
				throw new RuntimeException("Unable to create map file: {$fileMapPath}");
			}
		}
	}

	private function ensureDirectory(string $path): void
	{
		if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
			throw new RuntimeException("Unable to create directory: {$path}");
		}
	}

	private function getRequiredConfigString(string $key, bool $trim = true): string
	{
		if (!array_key_exists($key, $this->config->inputConfig)) {
			throw new RuntimeException("Required configuration option '{$key}' is missing.");
		}

		$value = $this->config->inputConfig[$key];
		if (!is_string($value) && !is_int($value) && !is_float($value)) {
			throw new RuntimeException("Configuration option '{$key}' must be a string.");
		}

		$value = (string) $value;
		$value = $trim ? trim($value) : $value;
		if ($value === '') {
			throw new RuntimeException("Configuration option '{$key}' cannot be empty.");
		}

		return $value;
	}

	private function toBoolean(mixed $value): bool
	{
		if (is_bool($value)) {
			return $value;
		}

		if (is_int($value) || is_float($value)) {
			return (int) $value !== 0;
		}

		if (is_string($value)) {
			$parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
			return $parsed ?? false;
		}

		return false;
	}

	private function printRowError(int|string|null $rowId, string $message): void
	{
		if (!$this->config->showLogs) {
			return;
		}

		$prefix = $rowId === null ? 'Row' : "Row {$rowId}";
		$this->cli->error("{$prefix}: {$message}")->br();
	}

	private function normalizeErrorMessage(string $message): string
	{
		$message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);
		if ($message === '') {
			return 'Unknown error.';
		}

		return function_exists('mb_substr')
			? mb_substr($message, 0, 2000)
			: substr($message, 0, 2000);
	}
}
