<?php

namespace QuadVector\DBAIRewriter;

use League\CLImate\CLImate;
use QuadVector\DBAIRewriter\DataSource\DataSourceInterface;
use QuadVector\DBAIRewriter\LLMGenerator\LLMGeneratorInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

class DBAIRewriter
{
	private CLImate $cli;
	private DBAIRewriterConfig $config;
	private DataSourceInterface $dataSource;
	private LLMGeneratorInterface $llmGenerator;

	private const OUTPUT_DIR = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'output';
	private const MAP_FILE = 'map.jsonl';

	private const BATCH_DIR = 'batch';
	private const BATCH_MAP_FILE = 'map.json';
	private const BATCH_STATUS_FILE = 'status.json';
	private const BATCH_MAX_JSONL_SIZE = 104857600;
	private const BATCH_MAX_REQUESTS_PER_CHUNK = 50000;
	private const BATCH_POLL_INTERVAL_SECONDS = 60;
	private const BATCH_MAX_WAIT_SECONDS = 86400;
	private const PROCESSING_MAP_SAVE_INTERVAL = 100;

	private const BATCH_SKIP_SUBMIT_STATUS_STATES = [
		'submitted',
		'validating',
		'in_progress',
		'finalizing',
		'completed',
		'cancelling',
		'saved',
	];

	private const BATCH_ALLOW_SUBMIT_STATUS_STATES = [
		'pending',
		'failed',
		'expired',
		'cancelled',
	];

	/**
	 * @param DBAIRewriterConfig $config Конфигурация генератора
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
	 * Запуск генератора.
	 */
	public function run(): void
	{
		$this->cli->clear();
		$this->cli->info('Starting DBAIRewriter...')->br();

		$tableName = $this->getRequiredConfigString('table_name');
		$idColumnName = $this->getRequiredConfigString('id_column_name');
		$updateColumnName = $this->getRequiredConfigString('update_column_name');
		$promptTemplate = $this->getRequiredConfigString('update_column_prompt', false);

		$this->printConfiguration();

		$proxies = $this->llmGenerator->getProxy();
		if ($proxies) {
			$this->cli->info('<bold>Proxies:</bold>');
			$this->cli->table($proxies)->br();
		} else {
			$this->cli->br();
		}

		$this->ensureDirectory(self::OUTPUT_DIR);

		$fileMapPath = self::OUTPUT_DIR . DIRECTORY_SEPARATOR . self::MAP_FILE;
		$this->ensureMapFileExists($fileMapPath);

		$this->cli->output('<bold><cyan>Output directory:</cyan></bold> ' . self::OUTPUT_DIR)->br();
		$this->cli->output('<bold><cyan>Map file path:</cyan></bold> ' . $fileMapPath)->br();

		// Берём последнее состояние каждого ID и сразу уплотняем старую карту.
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
	 * Обычная построчная обработка.
	 *
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
		if ($this->config->showLogs) {
			$this->cli->output('Counting rows in the table...');
		}

		$rowsCount = max(0, (int) $this->dataSource->count($tableName));
		$this->cli->output('<bold><cyan>Rows count:</cyan></bold> ' . $rowsCount)->br();

		if ($this->config->showLogs) {
			$this->cli->output('Starting rows processing...');
		}

		$currentProgress = 0;
		$attemptedCount = 0;
		$successCount = 0;
		$failedCount = 0;
		$emptySkippedCount = 0;
		$processedSkippedCount = 0;
		$fatalError = null;

		$progressBar = null;
		if (!$this->config->showLogs && $rowsCount > 0) {
			$progressBar = $this->cli->progress()->total($rowsCount);
		}

		try {
			foreach ($this->dataSource->findAll($tableName) as $row) {
				$currentProgress++;
				$percent = $this->getProgressPercent($currentProgress, $rowsCount);

				$this->printProgress(
					$currentProgress,
					$rowsCount,
					$percent,
					$progressBar,
					'Processing rows...'
				);

				$rowValidation = $this->validateRow($row, $idColumnName, $updateColumnName);
				if (!$rowValidation['valid']) {
					$failedCount++;
					$this->printRowError($rowValidation['id'], $rowValidation['error']);

					if ($rowValidation['id'] !== null) {
						$this->setProcessingMapEntry($mapById, $rowValidation['id'], [
							'status' => 'failed',
							'reason' => 'invalid_row',
							'error' => $rowValidation['error'],
						]);
						$this->saveProcessingMap($fileMapPath, $mapById);
					}

					continue;
				}

				$rowId = $rowValidation['id'];
				$previousMapData = $mapById[(string) $rowId] ?? null;

				if (($previousMapData['status'] ?? null) === 'success') {
					$processedSkippedCount++;

					if ($this->config->showLogs) {
						$this->cli->output("Row {$rowId} has already been processed, skipping...")->br();
					}

					continue;
				}

				if ($this->isEmptyValue($row[$updateColumnName])) {
					$emptySkippedCount++;
					$this->setProcessingMapEntry($mapById, $rowId, [
						'status' => 'skipped',
						'reason' => 'empty_value',
					]);
					$this->saveProcessingMap($fileMapPath, $mapById);

					if ($this->config->showLogs) {
						$this->cli->output("Row {$rowId} has an empty value, skipping...")->br();
					}

					continue;
				}

				try {
					$prompt = $this->generatePrompt($promptTemplate, $row);

					if ($this->config->showLogs) {
						$this->cli->output("Generating update data for row {$rowId} using AI...");
					}

					$attemptedCount++;
					$updateValue = $this->llmGenerator->rewrite(
						$prompt,
						$this->config->inputConfig['ai_model'],
						$this->config->inputConfig['ai_temperature'],
						$this->config->inputConfig['ai_max_output_tokens']
					);

					if ($this->isEmptyValue($updateValue)) {
						throw new RuntimeException('AI returned an empty value.');
					}

					$updateStatus = $this->dataSource->update(
						$tableName,
						[$updateColumnName => $updateValue],
						[$idColumnName => $rowId]
					);

					if (!$updateStatus) {
						throw new RuntimeException('Data source did not update the row.');
					}
				} catch (Throwable $exception) {
					$failedCount++;
					$errorMessage = $this->normalizeErrorMessage($exception->getMessage());

					$this->setProcessingMapEntry($mapById, $rowId, [
						'status' => 'failed',
						'reason' => 'processing_error',
						'error' => $errorMessage,
					]);
					$this->saveProcessingMap($fileMapPath, $mapById);
					$this->printRowError($rowId, $errorMessage);
					continue;
				}

				$successCount++;
				$this->setProcessingMapEntry($mapById, $rowId, [
					'status' => 'success',
					'mode' => 'sync',
				]);
				$this->saveProcessingMap($fileMapPath, $mapById);

				if ($this->config->showLogs) {
					$this->cli->output("Row {$rowId} updated successfully.")->br();
				}
			}
		} catch (Throwable $exception) {
			$fatalError = $exception;
		}

		$this->printSyncSummary(
			$rowsCount,
			$currentProgress,
			$attemptedCount,
			$successCount,
			$failedCount,
			$emptySkippedCount,
			$processedSkippedCount,
			$fatalError
		);

		if ($fatalError !== null) {
			throw $fatalError;
		}
	}

	/**
	 * Полный цикл OpenAI Batch API: построение JSONL, отправка, ожидание и сохранение.
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
		$this->cli->output('<green><bold>Starting DBAIRewriter in BATCH mode...</bold></green>')->br();

		$batchFolderPath = $this->getBatchFolderPath($tableName);
		$this->cli->output("<bold><cyan>Batch folder:</cyan></bold> {$batchFolderPath}");

		$fatalError = null;
		$buildStats = $this->emptyBatchBuildStats();
		$submitStats = ['submitted' => 0, 'skipped' => 0, 'failed' => 0];
		$collectStats = ['saved' => 0, 'skipped' => 0, 'failed' => 0];

		try {
			$this->assertOpenAIBatchProvider();

			if ($this->config->forceBatch) {
				$this->cli->output(
					"<bold><red>Force Batch reset:</red></bold> {$batchFolderPath}"
				);

				if (is_dir($batchFolderPath)) {
					$this->removeDirectorySafely($batchFolderPath);
				}
			}

			$this->ensureDirectory($batchFolderPath);

			$status = $this->loadBatchStatus($batchFolderPath);
			$batchMap = $this->loadBatchMap($batchFolderPath);
			$chunkFiles = $this->getSortedBatchChunkFiles($batchFolderPath);
			$hasRemoteState = count($status['batches']) > 0;

			if ($hasRemoteState && (count($batchMap) === 0 || count($chunkFiles) === 0)) {
				throw new RuntimeException(
					'Batch state is inconsistent: status.json contains submitted batches, '
						. 'but map.json or chunk files are missing. Use Force batch only if '
						. 'you intentionally want to discard the local Batch state.'
				);
			}

			$reusableSession = count($batchMap) > 0 && count($chunkFiles) > 0;

			if ($reusableSession && $this->areAllBatchesSaved($status)) {
				$this->cli->output(
					'<yellow>The previous Batch session is fully saved. '
						. 'Building a new session for rows that still need processing.</yellow>'
				);

				$this->removeDirectorySafely($batchFolderPath);
				$this->ensureDirectory($batchFolderPath);
				$status = ['batches' => []];
				$batchMap = [];
				$chunkFiles = [];
				$reusableSession = false;
				$hasRemoteState = false;
			}

			if (!$reusableSession) {
				if (!$hasRemoteState && (count($batchMap) > 0 || count($chunkFiles) > 0)) {
					$this->removeDirectorySafely($batchFolderPath);
					$this->ensureDirectory($batchFolderPath);
				}

				$buildResult = $this->buildBatchChunks(
					$tableName,
					$idColumnName,
					$updateColumnName,
					$promptTemplate,
					$fileMapPath,
					$batchFolderPath,
					$mapById
				);

				$buildStats = $buildResult['stats'];
				$batchMap = $buildResult['map'];
				$chunkFiles = $this->getSortedBatchChunkFiles($batchFolderPath);

				if (count($chunkFiles) === 0) {
					$this->printBatchSummary(
						$batchFolderPath,
						$buildStats,
						$submitStats,
						$collectStats,
						null
					);
					return;
				}
			} else {
				$this->cli->output(
					'<yellow>Existing unfinished Batch session found. '
						. 'Reusing its chunks, map and status.</yellow>'
				);
				$buildStats['queued'] = count($batchMap);
				$buildStats['chunks'] = count($chunkFiles);
			}

			$submitStats = $this->submitBatchChunks($batchFolderPath);
			$collectStats = $this->collectBatches(
				$tableName,
				$idColumnName,
				$updateColumnName,
				$fileMapPath,
				$batchFolderPath,
				$mapById,
				$this->getBatchPollInterval(),
				$this->getBatchMaxWait()
			);
		} catch (Throwable $exception) {
			$fatalError = $exception;
		}

		$this->printBatchSummary(
			$batchFolderPath,
			$buildStats,
			$submitStats,
			$collectStats,
			$fatalError
		);

		if ($fatalError !== null) {
			throw $fatalError;
		}
	}

	/**
	 * Построить JSONL-файлы и карту custom_id -> ID строки.
	 *
	 * @param array<string, array<string, mixed>> $mapById
	 * @return array{stats: array<string, int>, map: array<string, array<string, mixed>>}
	 */
	private function buildBatchChunks(
		string $tableName,
		string $idColumnName,
		string $updateColumnName,
		string $promptTemplate,
		string $fileMapPath,
		string $batchFolderPath,
		array &$mapById
	): array {
		$this->cli->output('<bold><yellow>Building Batch JSONL chunks...</yellow></bold>');

		$stats = $this->emptyBatchBuildStats();
		$stats['rows'] = max(0, (int) $this->dataSource->count($tableName));

		$progressBar = null;
		if (!$this->config->showLogs && $stats['rows'] > 0) {
			$progressBar = $this->cli->progress()->total($stats['rows']);
		}

		$batchMap = [];
		$chunkItems = [];
		$chunkKey = 1;
		$chunkSize = 0;
		$mapChangesSinceSave = 0;
		$startedAt = microtime(true);

		foreach ($this->dataSource->findAll($tableName) as $row) {
			$stats['checked']++;
			$percent = $this->getProgressPercent($stats['checked'], $stats['rows']);

			$this->printProgress(
				$stats['checked'],
				$stats['rows'],
				$percent,
				$progressBar,
				"Building Batch chunks: chunk {$chunkKey}"
			);

			$rowValidation = $this->validateRow($row, $idColumnName, $updateColumnName);
			if (!$rowValidation['valid']) {
				$stats['failed']++;
				$this->printRowError($rowValidation['id'], $rowValidation['error']);

				if ($rowValidation['id'] !== null) {
					$this->setProcessingMapEntry($mapById, $rowValidation['id'], [
						'status' => 'failed',
						'reason' => 'invalid_row',
						'error' => $rowValidation['error'],
					]);
					$mapChangesSinceSave++;
				}

				$this->saveProcessingMapPeriodically(
					$fileMapPath,
					$mapById,
					$mapChangesSinceSave
				);
				continue;
			}

			$rowId = $rowValidation['id'];
			$previousMapData = $mapById[(string) $rowId] ?? null;

			if (($previousMapData['status'] ?? null) === 'success') {
				$stats['already_processed']++;
				continue;
			}

			if ($this->isEmptyValue($row[$updateColumnName])) {
				$stats['empty']++;
				$this->setProcessingMapEntry($mapById, $rowId, [
					'status' => 'skipped',
					'reason' => 'empty_value',
				]);
				$mapChangesSinceSave++;
				$this->saveProcessingMapPeriodically(
					$fileMapPath,
					$mapById,
					$mapChangesSinceSave
				);
				continue;
			}

			try {
				$prompt = $this->generatePrompt($promptTemplate, $row);
				$customId = $this->makeBatchCustomId($tableName, $rowId);

				if (isset($batchMap[$customId])) {
					throw new RuntimeException("Duplicate row ID in data source: {$rowId}");
				}

				$request = $this->buildBatchRequestLine($customId, $prompt);
				$line = json_encode(
					$request,
					JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
				);

				$lineSize = strlen($line) + 1;
				if ($lineSize > self::BATCH_MAX_JSONL_SIZE) {
					$stats['too_big']++;
					$this->setProcessingMapEntry($mapById, $rowId, [
						'status' => 'failed',
						'reason' => 'batch_line_too_large',
					]);
					$mapChangesSinceSave++;
					continue;
				}

				$chunkIsFull = count($chunkItems) >= self::BATCH_MAX_REQUESTS_PER_CHUNK;
				$chunkIsTooLarge = $chunkSize + $lineSize > self::BATCH_MAX_JSONL_SIZE;

				if (count($chunkItems) > 0 && ($chunkIsFull || $chunkIsTooLarge)) {
					$this->saveBatchChunkFile($batchFolderPath, $chunkKey, $chunkItems);
					$stats['chunks']++;
					$chunkKey++;
					$chunkItems = [];
					$chunkSize = 0;
				}

				$chunkItems[] = $line;
				$chunkSize += $lineSize;
				$batchMap[$customId] = [
					'row_id' => $rowId,
					'chunk_key' => $chunkKey,
				];

				$this->setProcessingMapEntry($mapById, $rowId, [
					'status' => 'batch_pending',
					'mode' => 'batch',
					'batch_custom_id' => $customId,
					'chunk_key' => $chunkKey,
				]);

				$stats['queued']++;
				$mapChangesSinceSave++;
			} catch (Throwable $exception) {
				$stats['failed']++;
				$errorMessage = $this->normalizeErrorMessage($exception->getMessage());
				$this->setProcessingMapEntry($mapById, $rowId, [
					'status' => 'failed',
					'reason' => 'batch_build_error',
					'error' => $errorMessage,
				]);
				$mapChangesSinceSave++;
				$this->printRowError($rowId, $errorMessage);
			}

			$this->saveProcessingMapPeriodically(
				$fileMapPath,
				$mapById,
				$mapChangesSinceSave
			);
		}

		if (count($chunkItems) > 0) {
			$this->saveBatchChunkFile($batchFolderPath, $chunkKey, $chunkItems);
			$stats['chunks']++;
		}

		$this->saveProcessingMap($fileMapPath, $mapById);
		$this->saveBatchMap($batchFolderPath, $batchMap);

		$stats['build_seconds'] = (int) round(microtime(true) - $startedAt);

		$this->cli->br();
		$this->cli->output('<bold><green>Finished building Batch chunks.</green></bold>');
		$this->cli->table([
			['Rows checked', $stats['checked']],
			['Queued requests', $stats['queued']],
			['Chunks', $stats['chunks']],
			['Failed while building', $stats['failed']],
			['Skipped: empty value', $stats['empty']],
			['Skipped: already processed', $stats['already_processed']],
			['Skipped: request too large', $stats['too_big']],
		]);

		return ['stats' => $stats, 'map' => $batchMap];
	}

	/**
	 * @return array{submitted: int, skipped: int, failed: int}
	 */
	private function submitBatchChunks(string $batchFolderPath): array
	{
		$this->cli->output('<bold><yellow>Submitting JSONL chunks to OpenAI Batch API...</yellow></bold>');

		$status = $this->loadBatchStatus($batchFolderPath);
		$knownChunks = [];

		foreach ($status['batches'] as $batchIndex => $batchInfo) {
			if (isset($batchInfo['chunk_key'])) {
				$knownChunks[(int) $batchInfo['chunk_key']] = $batchIndex;
			}
		}

		$chunkFiles = $this->getSortedBatchChunkFiles($batchFolderPath);
		$stats = ['submitted' => 0, 'skipped' => 0, 'failed' => 0];
		$progressBar = null;

		if (!$this->config->showLogs && count($chunkFiles) > 0) {
			$progressBar = $this->cli->progress()->total(count($chunkFiles));
		}

		foreach ($chunkFiles as $position => $chunkFile) {
			$chunkKey = $this->extractChunkKey($chunkFile, $position + 1);
			$statusIndex = $knownChunks[$chunkKey] ?? null;
			$currentStatus = $statusIndex === null
				? 'pending'
				: (string) ($status['batches'][$statusIndex]['status'] ?? 'pending');

			if (in_array($currentStatus, self::BATCH_SKIP_SUBMIT_STATUS_STATES, true)) {
				$stats['skipped']++;
				$this->updateSubmitProgress($progressBar, $position + 1, count($chunkFiles));
				continue;
			}

			if (!in_array($currentStatus, self::BATCH_ALLOW_SUBMIT_STATUS_STATES, true)) {
				$stats['skipped']++;
				$this->updateSubmitProgress($progressBar, $position + 1, count($chunkFiles));
				continue;
			}

			try {
				$uploadedFile = $this->uploadBatchFile($chunkFile);
				$inputFileId = $uploadedFile['id'] ?? null;

				if (!is_string($inputFileId) || $inputFileId === '') {
					throw new RuntimeException('OpenAI file upload returned no file ID.');
				}

				$batch = $this->createOpenAIBatch($inputFileId, [
					'chunk_key' => (string) $chunkKey,
					'chunk_file' => basename($chunkFile),
					'source' => 'db-ai-rewriter',
				]);

				$batchId = $batch['id'] ?? null;
				if (!is_string($batchId) || $batchId === '') {
					throw new RuntimeException('OpenAI Batch API returned no batch ID.');
				}

				$batchData = [
					'chunk_key' => $chunkKey,
					'chunk_file' => basename($chunkFile),
					'status' => (string) ($batch['status'] ?? 'submitted'),
					'batch_id' => $batchId,
					'file_id' => $inputFileId,
					'output_file_id' => $batch['output_file_id'] ?? null,
					'error_file_id' => $batch['error_file_id'] ?? null,
					'submitted_at' => time(),
				];

				if ($statusIndex === null) {
					$status['batches'][] = $batchData;
					$knownChunks[$chunkKey] = count($status['batches']) - 1;
				} else {
					$status['batches'][$statusIndex] = array_merge(
						$status['batches'][$statusIndex],
						$batchData
					);
				}

				$stats['submitted']++;
			} catch (Throwable $exception) {
				$stats['failed']++;
				$errorData = [
					'chunk_key' => $chunkKey,
					'chunk_file' => basename($chunkFile),
					'status' => $currentStatus,
					'last_submit_error' => $this->normalizeErrorMessage($exception->getMessage()),
					'last_submit_attempt_at' => time(),
				];

				if ($statusIndex === null) {
					$status['batches'][] = $errorData;
					$knownChunks[$chunkKey] = count($status['batches']) - 1;
				} else {
					$status['batches'][$statusIndex] = array_merge(
						$status['batches'][$statusIndex],
						$errorData
					);
				}

				if ($this->config->showLogs) {
					$this->cli->error('Submit failed: ' . $exception->getMessage());
				}
			}

			$this->saveBatchStatus($batchFolderPath, $status);
			$this->updateSubmitProgress($progressBar, $position + 1, count($chunkFiles));
		}

		$this->cli->br();
		$this->cli->table([
			['Submitted batches', $stats['submitted']],
			['Skipped existing batches', $stats['skipped']],
			['Failed submissions', $stats['failed']],
		]);

		return $stats;
	}

	/**
	 * @param array<string, array<string, mixed>> $mapById
	 * @return array{saved: int, skipped: int, failed: int}
	 */
	private function collectBatches(
		string $tableName,
		string $idColumnName,
		string $updateColumnName,
		string $fileMapPath,
		string $batchFolderPath,
		array &$mapById,
		int $pollIntervalSec,
		int $maxWaitSec
	): array {
		$this->cli->output('<bold><yellow>Collecting OpenAI Batch results...</yellow></bold>');

		$status = $this->loadBatchStatus($batchFolderPath);
		$batchMap = $this->loadBatchMap($batchFolderPath);
		$totals = ['saved' => 0, 'skipped' => 0, 'failed' => 0];

		if (count($status['batches']) === 0) {
			$this->cli->output('<yellow>No submitted batches found.</yellow>')->br();
			return $totals;
		}

		if (count($batchMap) === 0) {
			throw new RuntimeException('Batch map is empty. Results cannot be matched to source rows.');
		}

		$startedAt = time();
		$iteration = 1;

		while (true) {
			$allDoneForThisRun = true;
			$iterationStats = [
				'saved' => 0,
				'pending' => 0,
				'waiting' => 0,
				'completed' => 0,
				'failed' => 0,
			];

			if ($this->config->showLogs) {
				$this->cli->output("<bold><cyan>Collect iteration:</cyan></bold> {$iteration}");
			}

			foreach ($status['batches'] as $batchIndex => $batchInfo) {
				$currentStatus = (string) ($batchInfo['status'] ?? 'pending');
				$batchId = $batchInfo['batch_id'] ?? null;
				$chunkKey = (int) ($batchInfo['chunk_key'] ?? ($batchIndex + 1));

				if ($currentStatus === 'saved') {
					$iterationStats['saved']++;
					continue;
				}

				if ($currentStatus === 'pending') {
					$iterationStats['pending']++;
					continue;
				}

				if (in_array($currentStatus, ['failed', 'expired', 'cancelled'], true)) {
					$iterationStats['failed']++;
					continue;
				}

				if (!is_string($batchId) || $batchId === '') {
					$status['batches'][$batchIndex]['status'] = 'pending';
					$status['batches'][$batchIndex]['last_error'] = 'Missing batch_id.';
					$iterationStats['pending']++;
					$this->saveBatchStatus($batchFolderPath, $status);
					continue;
				}

				try {
					$batch = $this->retrieveOpenAIBatch($batchId);
					$apiStatus = (string) ($batch['status'] ?? '');

					if ($apiStatus === '') {
						throw new RuntimeException('OpenAI returned an empty batch status.');
					}

					$status['batches'][$batchIndex]['status'] = $apiStatus;
					$status['batches'][$batchIndex]['checked_at'] = time();
					$status['batches'][$batchIndex]['output_file_id'] = $batch['output_file_id'] ?? null;
					$status['batches'][$batchIndex]['error_file_id'] = $batch['error_file_id'] ?? null;
					$status['batches'][$batchIndex]['request_counts'] = $batch['request_counts'] ?? null;

					if ($apiStatus === 'completed') {
						$iterationStats['completed']++;
						$saveStats = $this->saveBatchResults(
							$tableName,
							$idColumnName,
							$updateColumnName,
							$fileMapPath,
							$batchFolderPath,
							$status['batches'][$batchIndex],
							$batchMap,
							$mapById
						);

						foreach ($totals as $key => $unused) {
							$totals[$key] += $saveStats[$key];
						}

						$status['batches'][$batchIndex]['status'] = 'saved';
						$status['batches'][$batchIndex]['saved_at'] = time();
						$status['batches'][$batchIndex]['saved_results_count'] = $saveStats['saved'];
						$status['batches'][$batchIndex]['skipped_results_count'] = $saveStats['skipped'];
						$status['batches'][$batchIndex]['failed_results_count'] = $saveStats['failed'];
						$iterationStats['saved']++;
					} elseif (in_array($apiStatus, ['failed', 'expired', 'cancelled'], true)) {
						$iterationStats['failed']++;
						$this->markChunkRowsFailed(
							$chunkKey,
							$batchMap,
							$mapById,
							"batch_{$apiStatus}"
						);
						$this->saveProcessingMap($fileMapPath, $mapById);

						$errorFileId = $batch['error_file_id'] ?? null;
						if (is_string($errorFileId) && $errorFileId !== '') {
							$errorContent = $this->downloadOpenAIFile($errorFileId);
							$errorPath = $batchFolderPath
								. DIRECTORY_SEPARATOR
								. "errors_chunk_{$chunkKey}.jsonl";
							$this->writeFile($errorPath, $errorContent);
						}
					} else {
						$iterationStats['waiting']++;
						$allDoneForThisRun = false;
					}
				} catch (Throwable $exception) {
					$iterationStats['failed']++;
					$allDoneForThisRun = false;
					$status['batches'][$batchIndex]['last_collect_error'] =
						$this->normalizeErrorMessage($exception->getMessage());
					$status['batches'][$batchIndex]['last_collect_error_at'] = time();

					if ($this->config->showLogs) {
						$this->cli->error('Collect error: ' . $exception->getMessage());
					}
				}

				$this->saveBatchStatus($batchFolderPath, $status);
			}

			$this->cli->br();
			$this->cli->table([
				['Saved batches', $iterationStats['saved']],
				['Pending submission', $iterationStats['pending']],
				['Waiting batches', $iterationStats['waiting']],
				['Completed this iteration', $iterationStats['completed']],
				['Failed/expired/cancelled', $iterationStats['failed']],
			]);

			if ($allDoneForThisRun) {
				break;
			}

			if ((time() - $startedAt) >= $maxWaitSec) {
				$this->cli->output('<yellow>Maximum Batch wait time reached.</yellow>')->br();
				break;
			}

			$this->cli->output("<dim>Sleeping {$pollIntervalSec}s before the next check...</dim>")->br();
			$iteration++;
			sleep($pollIntervalSec);
		}

		return $totals;
	}

	/**
	 * @param array<string, mixed> $batchInfo
	 * @param array<string, array<string, mixed>> $batchMap
	 * @param array<string, array<string, mixed>> $mapById
	 * @return array{saved: int, skipped: int, failed: int}
	 */
	private function saveBatchResults(
		string $tableName,
		string $idColumnName,
		string $updateColumnName,
		string $fileMapPath,
		string $batchFolderPath,
		array $batchInfo,
		array $batchMap,
		array &$mapById
	): array {
		$stats = ['saved' => 0, 'skipped' => 0, 'failed' => 0];
		$chunkKey = (int) ($batchInfo['chunk_key'] ?? 0);
		$outputFileId = $batchInfo['output_file_id'] ?? null;
		$seenCustomIds = [];

		if (is_string($outputFileId) && $outputFileId !== '') {
			$rawContent = $this->downloadOpenAIFile($outputFileId);
			$resultPath = $batchFolderPath
				. DIRECTORY_SEPARATOR
				. "results_chunk_{$chunkKey}.jsonl";
			$this->writeFile($resultPath, $rawContent);

			$lines = preg_split('/\r?\n/', trim($rawContent)) ?: [];

			foreach ($lines as $line) {
				if (trim($line) === '') {
					continue;
				}

				try {
					$item = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
				} catch (Throwable) {
					$stats['failed']++;
					continue;
				}

				$customId = $item['custom_id'] ?? null;
				if (!is_string($customId) || !isset($batchMap[$customId])) {
					$stats['failed']++;
					continue;
				}

				$taskMeta = $batchMap[$customId];
				if ((int) ($taskMeta['chunk_key'] ?? 0) !== $chunkKey) {
					continue;
				}

				$seenCustomIds[$customId] = true;
				$rowId = $taskMeta['row_id'] ?? null;

				if (!is_int($rowId) && !is_string($rowId)) {
					$stats['failed']++;
					continue;
				}

				$error = $item['error'] ?? null;
				$response = $item['response']['body'] ?? null;
				$statusCode = (int) ($item['response']['status_code'] ?? 0);

				if (!empty($error) || !is_array($response) || ($statusCode !== 0 && $statusCode >= 400)) {
					$stats['failed']++;
					$this->setProcessingMapEntry($mapById, $rowId, [
						'status' => 'failed',
						'reason' => 'batch_response_error',
						'error' => $this->normalizeBatchError($error, $response),
					]);
					continue;
				}

				$content = $response['choices'][0]['message']['content'] ?? null;
				$finishReason = $response['choices'][0]['finish_reason'] ?? null;

				if ($this->isEmptyValue($content) || $finishReason === 'length') {
					$stats['failed']++;
					$this->setProcessingMapEntry($mapById, $rowId, [
						'status' => 'failed',
						'reason' => $finishReason === 'length'
							? 'batch_output_truncated'
							: 'batch_output_empty',
					]);
					continue;
				}

				try {
					$updateStatus = $this->dataSource->update(
						$tableName,
						[$updateColumnName => trim((string) $content)],
						[$idColumnName => $rowId]
					);

					if (!$updateStatus) {
						$stats['skipped']++;
						$this->setProcessingMapEntry($mapById, $rowId, [
							'status' => 'failed',
							'reason' => 'batch_database_update_skipped',
						]);
						continue;
					}

					$stats['saved']++;
					$this->setProcessingMapEntry($mapById, $rowId, [
						'status' => 'success',
						'mode' => 'batch',
						'batch_custom_id' => $customId,
						'chunk_key' => $chunkKey,
					]);
				} catch (Throwable $exception) {
					$stats['failed']++;
					$this->setProcessingMapEntry($mapById, $rowId, [
						'status' => 'failed',
						'reason' => 'batch_database_update_error',
						'error' => $this->normalizeErrorMessage($exception->getMessage()),
					]);
				}
			}
		}

		foreach ($batchMap as $customId => $taskMeta) {
			if ((int) ($taskMeta['chunk_key'] ?? 0) !== $chunkKey || isset($seenCustomIds[$customId])) {
				continue;
			}

			$rowId = $taskMeta['row_id'] ?? null;
			if (!is_int($rowId) && !is_string($rowId)) {
				$stats['failed']++;
				continue;
			}

			$stats['failed']++;
			$this->setProcessingMapEntry($mapById, $rowId, [
				'status' => 'failed',
				'reason' => 'batch_result_missing',
			]);
		}

		$this->saveProcessingMap($fileMapPath, $mapById);

		return $stats;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildBatchRequestLine(string $customId, string $prompt): array
	{
		$model = $this->getRequiredConfigString('ai_model');
		$maxTokens = (int) ($this->config->inputConfig['ai_max_output_tokens'] ?? 0);
		$temperature = $this->config->inputConfig['ai_temperature'] ?? null;

		$body = [
			'model' => $model,
			'messages' => [
				[
					'role' => 'user',
					'content' => $prompt,
				],
			],
		];

		if (is_numeric($temperature)) {
			$body['temperature'] = (float) $temperature;
		}

		if ($maxTokens > 0) {
			$body[$this->getBatchMaxTokensParameter($model)] = $maxTokens;
		}

		return [
			'custom_id' => $customId,
			'method' => 'POST',
			'url' => '/v1/chat/completions',
			'body' => $body,
		];
	}

	/**
	 * Методы protected позволяют тестировать Batch без обращения к сети.
	 *
	 * @return array<string, mixed>
	 */
	protected function uploadBatchFile(string $filePath): array
	{
		if (!is_file($filePath) || !is_readable($filePath)) {
			throw new RuntimeException("Batch file is not readable: {$filePath}");
		}

		if (!class_exists('CURLFile')) {
			throw new RuntimeException('PHP cURL extension is required for Batch mode.');
		}

		$response = $this->performOpenAIRequest(
			'POST',
			'/files',
			[
				'purpose' => 'batch',
				'file' => new \CURLFile($filePath, 'application/jsonl', basename($filePath)),
			],
			false
		);

		return $this->decodeJsonResponse($response, 'OpenAI file upload');
	}

	/**
	 * @param array<string, string> $metadata
	 * @return array<string, mixed>
	 */
	protected function createOpenAIBatch(string $inputFileId, array $metadata): array
	{
		$response = $this->performOpenAIRequest(
			'POST',
			'/batches',
			[
				'input_file_id' => $inputFileId,
				'endpoint' => '/v1/chat/completions',
				'completion_window' => '24h',
				'metadata' => $metadata,
			],
			true
		);

		return $this->decodeJsonResponse($response, 'OpenAI batch creation');
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function retrieveOpenAIBatch(string $batchId): array
	{
		$response = $this->performOpenAIRequest(
			'GET',
			'/batches/' . rawurlencode($batchId),
			null,
			true
		);

		return $this->decodeJsonResponse($response, 'OpenAI batch retrieval');
	}

	protected function downloadOpenAIFile(string $fileId): string
	{
		return $this->performOpenAIRequest(
			'GET',
			'/files/' . rawurlencode($fileId) . '/content',
			null,
			false
		);
	}

	/**
	 * @param array<string, mixed>|null $payload
	 */
	private function performOpenAIRequest(
		string $method,
		string $path,
		?array $payload,
		bool $jsonRequest
	): string {
		if (!function_exists('curl_init')) {
			throw new RuntimeException('PHP cURL extension is required for Batch mode.');
		}

		$handle = curl_init();
		if ($handle === false) {
			throw new RuntimeException('Failed to initialize cURL.');
		}

		$url = rtrim($this->getOpenAIBaseUrl(), '/') . '/' . ltrim($path, '/');
		$headers = [
			'Authorization: Bearer ' . $this->resolveOpenAIApiKey(),
			'Accept: application/json',
		];

		$projectId = $this->resolveOptionalOpenAIProjectId();
		if ($projectId !== null) {
			$headers[] = 'OpenAI-Project: ' . $projectId;
		}

		$organizationId = $this->getOptionalConfigOrEnvironment(
			['openai_organization_id', 'ai_organization_id'],
			['OPENAI_ORGANIZATION', 'OPENAI_ORG_ID']
		);
		if ($organizationId !== null) {
			$headers[] = 'OpenAI-Organization: ' . $organizationId;
		}

		curl_setopt($handle, CURLOPT_URL, $url);
		curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($handle, CURLOPT_CUSTOMREQUEST, strtoupper($method));
		curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 30);
		curl_setopt($handle, CURLOPT_TIMEOUT, $this->getHttpTimeout());

		$proxyUrl = $this->resolveProxyUrl();
		if ($proxyUrl !== null) {
			curl_setopt($handle, CURLOPT_PROXY, $proxyUrl);
		}

		if ($payload !== null) {
			if ($jsonRequest) {
				$headers[] = 'Content-Type: application/json';
				curl_setopt(
					$handle,
					CURLOPT_POSTFIELDS,
					json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
				);
			} else {
				curl_setopt($handle, CURLOPT_POSTFIELDS, $payload);
			}
		}

		curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);

		try {
			$response = curl_exec($handle);
			if ($response === false) {
				throw new RuntimeException('OpenAI request failed: ' . curl_error($handle));
			}

			$statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
			if ($statusCode < 200 || $statusCode >= 300) {
				throw new RuntimeException(
					"OpenAI API returned HTTP {$statusCode}: " . $this->extractApiError((string) $response)
				);
			}

			return (string) $response;
		} finally {
			curl_close($handle);
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function decodeJsonResponse(string $response, string $context): array
	{
		try {
			$data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
		} catch (Throwable $exception) {
			throw new RuntimeException("{$context} returned invalid JSON.", 0, $exception);
		}

		if (!is_array($data)) {
			throw new RuntimeException("{$context} returned an invalid response.");
		}

		return $data;
	}

	private function extractApiError(string $response): string
	{
		try {
			$data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
			$message = $data['error']['message'] ?? null;

			if (is_string($message) && $message !== '') {
				return $this->normalizeErrorMessage($message);
			}
		} catch (Throwable) {
			// Ниже вернётся сокращённый необработанный ответ.
		}

		return $this->normalizeErrorMessage($response);
	}

	private function resolveOpenAIApiKey(): string
	{
		$key = $this->getOptionalConfigOrEnvironment(
			['openai_api_key', 'ai_api_key', 'ai_api_token', 'api_key'],
			['OPENAI_API_KEY']
		);

		$key ??= $this->getOptionalPublicProperty(
			$this->config,
			['openAIKey', 'openAIApiKey', 'aiApiKey', 'apiKey']
		);

		$key ??= $this->getOptionalPublicProperty(
			$this->llmGenerator,
			['openAIKey', 'openAIApiKey', 'aiApiKey', 'apiKey']
		);

		if ($key === null) {
			foreach (['getOpenAIApiKey', 'getApiKey'] as $method) {
				if (!is_callable([$this->llmGenerator, $method])) {
					continue;
				}

				$value = $this->llmGenerator->{$method}();
				if (is_string($value) && trim($value) !== '') {
					return trim($value);
				}
			}
		}

		if ($key === null) {
			throw new RuntimeException(
				'OpenAI API key is required for Batch mode. Set OPENAI_API_KEY '
					. 'or inputConfig["openai_api_key"].'
			);
		}

		return $key;
	}

	private function resolveOptionalOpenAIProjectId(): ?string
	{
		$projectId = $this->getOptionalConfigOrEnvironment(
			['openai_project_id', 'ai_project_id'],
			['OPENAI_PROJECT_ID']
		);

		$projectId ??= $this->getOptionalPublicProperty(
			$this->config,
			['openAIProjectID', 'openAIProjectId', 'aiProjectId', 'projectId']
		);

		$projectId ??= $this->getOptionalPublicProperty(
			$this->llmGenerator,
			['openAIProjectID', 'openAIProjectId', 'aiProjectId', 'projectId']
		);

		if ($projectId !== null) {
			return $projectId;
		}

		foreach (['getOpenAIProjectId', 'getProjectId'] as $method) {
			if (!is_callable([$this->llmGenerator, $method])) {
				continue;
			}

			$value = $this->llmGenerator->{$method}();
			if (is_string($value) && trim($value) !== '') {
				return trim($value);
			}
		}

		return null;
	}

	private function getOpenAIBaseUrl(): string
	{
		$url = $this->getOptionalConfigOrEnvironment(
			['openai_base_url', 'ai_base_url'],
			['OPENAI_BASE_URL']
		);

		$url ??= $this->getOptionalPublicProperty(
			$this->config,
			['openAIBaseUrl', 'aiBaseUrl']
		);

		return $url ?? 'https://api.openai.com/v1';
	}

	private function resolveProxyUrl(): ?string
	{
		$proxy = $this->getOptionalConfigOrEnvironment(
			['openai_proxy', 'ai_proxy', 'proxy'],
			['HTTPS_PROXY', 'https_proxy']
		);

		if ($proxy !== null) {
			return $proxy;
		}

		try {
			return $this->extractProxyUrl($this->llmGenerator->getProxy());
		} catch (Throwable) {
			return null;
		}
	}

	private function extractProxyUrl(mixed $value): ?string
	{
		if (is_string($value)) {
			$value = trim($value);

			if (
				preg_match('#^(?:https?|socks[45]h?|socks4a)://\S+$#i', $value) === 1
				|| preg_match('/^[^\s:]+:\d{2,5}$/', $value) === 1
			) {
				return $value;
			}

			return null;
		}

		if (is_object($value) && method_exists($value, '__toString')) {
			return $this->extractProxyUrl((string) $value);
		}

		if (!is_array($value) || count($value) === 0) {
			return null;
		}

		if (isset($value['url']) && is_string($value['url'])) {
			return trim($value['url']) !== '' ? trim($value['url']) : null;
		}

		if (isset($value['proxy']) && is_string($value['proxy'])) {
			return trim($value['proxy']) !== '' ? trim($value['proxy']) : null;
		}

		if (isset($value['host']) && is_string($value['host'])) {
			$scheme = isset($value['scheme']) ? (string) $value['scheme'] : 'http';
			$port = isset($value['port']) ? ':' . (int) $value['port'] : '';
			return "{$scheme}://{$value['host']}{$port}";
		}

		$values = array_values($value);
		shuffle($values);

		foreach ($values as $candidate) {
			$url = $this->extractProxyUrl($candidate);

			if ($url !== null) {
				return $url;
			}
		}

		return null;
	}

	/**
	 * @param list<string> $configKeys
	 * @param list<string> $environmentKeys
	 */
	private function getOptionalConfigOrEnvironment(array $configKeys, array $environmentKeys): ?string
	{
		foreach ($configKeys as $key) {
			$value = $this->config->inputConfig[$key] ?? null;
			if (is_string($value) && trim($value) !== '') {
				return trim($value);
			}
		}

		foreach ($environmentKeys as $key) {
			$value = getenv($key);
			if (is_string($value) && trim($value) !== '') {
				return trim($value);
			}
		}

		return null;
	}

	/**
	 * Получить только публичное строковое свойство без Reflection и обхода инкапсуляции.
	 *
	 * @param list<string> $propertyNames
	 */
	private function getOptionalPublicProperty(object $object, array $propertyNames): ?string
	{
		$properties = get_object_vars($object);

		foreach ($propertyNames as $propertyName) {
			$value = $properties[$propertyName] ?? null;

			if (is_string($value) && trim($value) !== '') {
				return trim($value);
			}
		}

		return null;
	}

	private function getHttpTimeout(): int
	{
		$value = (int) ($this->config->inputConfig['ai_http_timeout'] ?? 300);
		return max(30, $value);
	}

	private function getBatchPollInterval(): int
	{
		$value = (int) (
			$this->config->inputConfig['ai_batch_poll_interval_seconds']
			?? self::BATCH_POLL_INTERVAL_SECONDS
		);

		return max(1, $value);
	}

	private function getBatchMaxWait(): int
	{
		$value = (int) (
			$this->config->inputConfig['ai_batch_max_wait_seconds']
			?? self::BATCH_MAX_WAIT_SECONDS
		);

		return max(1, $value);
	}

	private function getBatchMaxTokensParameter(string $model): string
	{
		$configured = $this->config->inputConfig['ai_batch_max_tokens_parameter'] ?? null;
		if (in_array($configured, ['max_tokens', 'max_completion_tokens'], true)) {
			return $configured;
		}

		return preg_match('/^(gpt-5|o1|o3|o4)/i', $model) === 1
			? 'max_completion_tokens'
			: 'max_tokens';
	}

	private function assertOpenAIBatchProvider(): void
	{
		$provider = strtolower($this->getRequiredConfigString('ai_provider'));

		if (!str_contains(str_replace(['-', '_', ' '], '', $provider), 'openai')) {
			throw new RuntimeException(
				"Batch mode currently supports the OpenAI provider, '{$provider}' configured."
			);
		}
	}

	private function isBatchModeEnabled(): bool
	{
		return $this->config->forceBatch
			|| $this->toBoolean($this->config->inputConfig['ai_batch'] ?? false);
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
			return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
		}

		return false;
	}

	private function getBatchFolderPath(string $tableName): string
	{
		$identity = implode('|', [
			(string) ($this->config->inputConfig['data_source'] ?? ''),
			(string) ($this->config->inputConfig['db_name'] ?? ''),
			$tableName,
		]);

		$name = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $tableName) ?: 'table';
		$folderName = $name . '_' . substr(hash('sha256', $identity), 0, 12);

		return self::OUTPUT_DIR
			. DIRECTORY_SEPARATOR . self::BATCH_DIR
			. DIRECTORY_SEPARATOR . $folderName;
	}

	private function makeBatchCustomId(string $tableName, int|string $rowId): string
	{
		$identity = implode('|', [
			(string) ($this->config->inputConfig['data_source'] ?? ''),
			(string) ($this->config->inputConfig['db_name'] ?? ''),
			$tableName,
			(string) $rowId,
		]);

		return 'row_' . substr(hash('sha256', $identity), 0, 48);
	}

	/**
	 * @return array<string, int>
	 */
	private function emptyBatchBuildStats(): array
	{
		return [
			'rows' => 0,
			'checked' => 0,
			'queued' => 0,
			'chunks' => 0,
			'failed' => 0,
			'empty' => 0,
			'already_processed' => 0,
			'too_big' => 0,
			'build_seconds' => 0,
		];
	}

	private function saveBatchChunkFile(
		string $batchFolderPath,
		int $chunkKey,
		array $chunkItems
	): void {
		if (count($chunkItems) === 0) {
			return;
		}

		$path = $batchFolderPath . DIRECTORY_SEPARATOR . "chunk_{$chunkKey}.jsonl";
		$this->writeFile($path, implode("\n", $chunkItems) . "\n");
	}

	/**
	 * @return list<string>
	 */
	private function getSortedBatchChunkFiles(string $batchFolderPath): array
	{
		$files = glob($batchFolderPath . DIRECTORY_SEPARATOR . 'chunk_*.jsonl');
		if ($files === false) {
			return [];
		}

		usort($files, function (string $left, string $right): int {
			return $this->extractChunkKey($left, 0) <=> $this->extractChunkKey($right, 0);
		});

		return $files;
	}

	private function extractChunkKey(string $path, int $fallback): int
	{
		preg_match('/chunk_(\d+)\.jsonl$/', basename($path), $match);
		return isset($match[1]) ? (int) $match[1] : $fallback;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function loadBatchMap(string $batchFolderPath): array
	{
		$path = $batchFolderPath . DIRECTORY_SEPARATOR . self::BATCH_MAP_FILE;
		if (!is_file($path)) {
			return [];
		}

		return $this->loadJsonFile($path);
	}

	/**
	 * @param array<string, array<string, mixed>> $map
	 */
	private function saveBatchMap(string $batchFolderPath, array $map): void
	{
		$this->saveJsonFile(
			$batchFolderPath . DIRECTORY_SEPARATOR . self::BATCH_MAP_FILE,
			$map
		);
	}

	/**
	 * @return array{batches: array<int, array<string, mixed>>}
	 */
	private function loadBatchStatus(string $batchFolderPath): array
	{
		$path = $batchFolderPath . DIRECTORY_SEPARATOR . self::BATCH_STATUS_FILE;
		if (!is_file($path)) {
			return ['batches' => []];
		}

		$data = $this->loadJsonFile($path);
		if (!isset($data['batches']) || !is_array($data['batches'])) {
			$data['batches'] = [];
		}

		return $data;
	}

	private function saveBatchStatus(string $batchFolderPath, array $status): void
	{
		$this->saveJsonFile(
			$batchFolderPath . DIRECTORY_SEPARATOR . self::BATCH_STATUS_FILE,
			$status
		);
	}

	private function areAllBatchesSaved(array $status): bool
	{
		if (!isset($status['batches']) || !is_array($status['batches']) || count($status['batches']) === 0) {
			return false;
		}

		foreach ($status['batches'] as $batchInfo) {
			if (($batchInfo['status'] ?? null) !== 'saved') {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array<string, array<string, mixed>> $batchMap
	 * @param array<string, array<string, mixed>> $mapById
	 */
	private function markChunkRowsFailed(
		int $chunkKey,
		array $batchMap,
		array &$mapById,
		string $reason
	): void {
		foreach ($batchMap as $taskMeta) {
			if ((int) ($taskMeta['chunk_key'] ?? 0) !== $chunkKey) {
				continue;
			}

			$rowId = $taskMeta['row_id'] ?? null;
			if (!is_int($rowId) && !is_string($rowId)) {
				continue;
			}

			$this->setProcessingMapEntry($mapById, $rowId, [
				'status' => 'failed',
				'reason' => $reason,
			]);
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function loadJsonFile(string $path): array
	{
		$content = file_get_contents($path);
		if ($content === false) {
			throw new RuntimeException("Failed to read JSON file: {$path}");
		}

		try {
			$data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
		} catch (Throwable $exception) {
			throw new RuntimeException("Invalid JSON file: {$path}", 0, $exception);
		}

		if (!is_array($data)) {
			throw new RuntimeException("JSON file must contain an object or array: {$path}");
		}

		return $data;
	}

	private function saveJsonFile(string $path, array $data): void
	{
		$json = json_encode(
			$data,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
		);

		$this->writeFile($path, $json . PHP_EOL);
	}

	private function writeFile(string $path, string $content): void
	{
		$this->ensureDirectory(dirname($path));
		$result = file_put_contents($path, $content, LOCK_EX);

		if ($result === false || $result !== strlen($content)) {
			throw new RuntimeException("Failed to write file: {$path}");
		}
	}

	private function ensureDirectory(string $path): void
	{
		if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
			throw new RuntimeException("Failed to create directory: {$path}");
		}
	}

	private function removeDirectorySafely(string $path): void
	{
		if (!is_dir($path)) {
			return;
		}

		$outputRoot = realpath(self::OUTPUT_DIR);
		$target = realpath($path);

		if ($outputRoot === false || $target === false) {
			throw new RuntimeException("Failed to resolve Batch directory: {$path}");
		}

		$normalizedRoot = rtrim(str_replace('\\', '/', $outputRoot), '/');
		$normalizedTarget = rtrim(str_replace('\\', '/', $target), '/');

		if (
			$normalizedTarget === $normalizedRoot
			|| !str_starts_with(strtolower($normalizedTarget), strtolower($normalizedRoot . '/'))
		) {
			throw new RuntimeException("Refusing to remove unsafe Batch directory: {$target}");
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($target, RecursiveDirectoryIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($iterator as $item) {
			$itemPath = $item->getPathname();

			if ($item->isDir() && !$item->isLink()) {
				if (!rmdir($itemPath)) {
					throw new RuntimeException("Failed to remove directory: {$itemPath}");
				}
			} elseif (!unlink($itemPath)) {
				throw new RuntimeException("Failed to remove file: {$itemPath}");
			}
		}

		if (!rmdir($target)) {
			throw new RuntimeException("Failed to remove Batch directory: {$target}");
		}
	}

	private function updateSubmitProgress(mixed $progressBar, int $current, int $total): void
	{
		if ($this->config->showLogs || $progressBar === null) {
			return;
		}

		$percent = $this->getProgressPercent($current, $total);
		$progressBar->current(
			$current,
			"Submitting batches [{$current} / {$total}] [{$percent}%]"
		);
	}

	private function printConfiguration(): void
	{
		$this->cli->info('Current configuration:');
		$this->cli->table([
			['Show logs', $this->config->showLogs ? 'Yes' : 'No'],
			['Data source', $this->config->inputConfig['data_source']],
			['DB name', $this->config->inputConfig['db_name']],
			['Table name', $this->config->inputConfig['table_name']],
			['ID column name', $this->config->inputConfig['id_column_name']],
			['Update column name', $this->config->inputConfig['update_column_name']],
			['AI provider', $this->config->inputConfig['ai_provider']],
			['AI model', $this->config->inputConfig['ai_model']],
			['AI temperature', $this->config->inputConfig['ai_temperature']],
			['AI max output tokens', $this->config->inputConfig['ai_max_output_tokens']],
			['Batch mode', $this->toBoolean($this->config->inputConfig['ai_batch'] ?? false) ? 'Yes' : 'No'],
			['Force batch', $this->config->forceBatch ? 'Yes' : 'No'],
		]);
	}

	private function printProgress(
		int $current,
		int $total,
		int $percent,
		mixed $progressBar,
		string $message
	): void {
		$output = "<dim>[{$percent}%]</dim> "
			. "<dim>[{$current} / {$total}]</dim> "
			. $message;

		if ($this->config->showLogs) {
			$this->cli->output($output);
			return;
		}

		if ($progressBar !== null) {
			$progressBar->current(min($current, $total), $output);
		}
	}

	private function printSyncSummary(
		int $rowsCount,
		int $checkedCount,
		int $attemptedCount,
		int $successCount,
		int $failedCount,
		int $emptySkippedCount,
		int $processedSkippedCount,
		?Throwable $fatalError
	): void {
		if ($fatalError !== null) {
			$status = 'Interrupted';
		} elseif ($failedCount > 0) {
			$status = 'Completed with errors';
		} elseif ($rowsCount === 0) {
			$status = 'Completed: no rows';
		} else {
			$status = 'Completed successfully';
		}

		$this->cli->br();
		$this->cli->info('Processing summary:');
		$this->cli->table([
			['Status', $status],
			['Rows reported by source', $rowsCount],
			['Rows checked', $checkedCount],
			['AI generation attempts', $attemptedCount],
			['Successfully updated', $successCount],
			['Failed', $failedCount],
			['Skipped: empty value', $emptySkippedCount],
			['Skipped: already processed', $processedSkippedCount],
			['Not checked', max(0, $rowsCount - $checkedCount)],
		]);

		if ($fatalError !== null) {
			$this->cli->error('Fatal error: ' . $fatalError->getMessage());
		}
	}

	private function printBatchSummary(
		string $batchFolderPath,
		array $buildStats,
		array $submitStats,
		array $collectStats,
		?Throwable $fatalError
	): void {
		$statusData = is_file($batchFolderPath . DIRECTORY_SEPARATOR . self::BATCH_STATUS_FILE)
			? $this->loadBatchStatus($batchFolderPath)
			: ['batches' => []];

		$batchCounts = [];
		$sessionSavedResults = 0;
		$sessionSkippedResults = 0;
		$sessionFailedResults = 0;
		foreach ($statusData['batches'] as $batchInfo) {
			$currentStatus = (string) ($batchInfo['status'] ?? 'pending');
			$batchCounts[$currentStatus] = ($batchCounts[$currentStatus] ?? 0) + 1;
			$sessionSavedResults += (int) ($batchInfo['saved_results_count'] ?? 0);
			$sessionSkippedResults += (int) ($batchInfo['skipped_results_count'] ?? 0);
			$sessionFailedResults += (int) ($batchInfo['failed_results_count'] ?? 0);
		}

		if ($fatalError !== null) {
			$status = 'Interrupted';
		} elseif (count($statusData['batches']) === 0 && ($buildStats['queued'] ?? 0) === 0) {
			$status = (($buildStats['failed'] ?? 0) + ($buildStats['too_big'] ?? 0)) > 0
				? 'Completed with errors: nothing submitted'
				: 'Completed: nothing to submit';
		} elseif ($this->areAllBatchesSaved($statusData)) {
			$status = $sessionFailedResults > 0
				? 'Completed with result errors'
				: 'Completed successfully';
		} else {
			$status = 'Stopped with unfinished batches';
		}

		$this->cli->br();
		$this->cli->info('Batch processing summary:');
		$this->cli->table([
			['Status', $status],
			['Rows checked while building', $buildStats['checked'] ?? 0],
			['Requests in Batch map', $buildStats['queued'] ?? 0],
			['JSONL chunks', $buildStats['chunks'] ?? 0],
			['Submitted this run', $submitStats['submitted'] ?? 0],
			['Submit failures this run', $submitStats['failed'] ?? 0],
			['Results saved this run', $collectStats['saved'] ?? 0],
			['Results skipped this run', $collectStats['skipped'] ?? 0],
			['Result failures this run', $collectStats['failed'] ?? 0],
			['Results saved in session', $sessionSavedResults],
			['Results skipped in session', $sessionSkippedResults],
			['Result failures in session', $sessionFailedResults],
			['Saved batches', $batchCounts['saved'] ?? 0],
			['Waiting batches', ($batchCounts['submitted'] ?? 0)
				+ ($batchCounts['validating'] ?? 0)
				+ ($batchCounts['in_progress'] ?? 0)
				+ ($batchCounts['finalizing'] ?? 0)
				+ ($batchCounts['cancelling'] ?? 0)],
			['Pending submission', $batchCounts['pending'] ?? 0],
			['Failed/expired/cancelled batches', ($batchCounts['failed'] ?? 0)
				+ ($batchCounts['expired'] ?? 0)
				+ ($batchCounts['cancelled'] ?? 0)],
		]);

		if ($fatalError !== null) {
			$this->cli->error('Fatal error: ' . $fatalError->getMessage());
		}
	}

	private function getProgressPercent(int $current, int $total): int
	{
		if ($total <= 0) {
			return 0;
		}

		return min(100, (int) round($current / $total * 100));
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
			throw new RuntimeException("Unable to open map file for reading: {$fileMapPath}");
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
						"Invalid JSON in map file {$fileMapPath} on line {$lineNumber}.",
						0,
						$exception
					);
				}

				$id = is_array($entry) ? ($entry['id'] ?? null) : null;
				if (!is_array($entry) || (!is_int($id) && !is_string($id))) {
					throw new RuntimeException(
						"Map file {$fileMapPath} contains an invalid entry on line {$lineNumber}."
					);
				}

				$mapById[(string) $id] = $entry;
			}

			if (!feof($handle)) {
				throw new RuntimeException("Unable to finish reading map file: {$fileMapPath}");
			}
		} finally {
			fclose($handle);
		}

		return $mapById;
	}

	/**
	 * Записать карту целиком: одна JSONL-строка на один ID.
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

		$content = count($lines) > 0 ? implode(PHP_EOL, $lines) . PHP_EOL : '';
		$this->writeFile($fileMapPath, $content);
	}

	/**
	 * @param array<string, array<string, mixed>> $mapById
	 * @param array<string, mixed> $data
	 */
	private function setProcessingMapEntry(
		array &$mapById,
		int|string $id,
		array $data
	): void {
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
			$this->writeFile($fileMapPath, '');
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
		if ($trim) {
			$value = trim($value);
		}

		if ($value === '') {
			throw new RuntimeException("Configuration option '{$key}' cannot be empty.");
		}

		return $value;
	}

	private function printRowError(int|string|null $rowId, string $message): void
	{
		if (!$this->config->showLogs) {
			return;
		}

		$prefix = $rowId === null ? 'Row' : "Row {$rowId}";
		$this->cli->error("{$prefix}: {$message}")->br();
	}

	private function normalizeBatchError(mixed $error, mixed $response): string
	{
		$value = !empty($error) ? $error : $response;

		if (is_string($value)) {
			return $this->normalizeErrorMessage($value);
		}

		try {
			return $this->normalizeErrorMessage(
				json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
			);
		} catch (Throwable) {
			return 'Unknown Batch response error.';
		}
	}

	private function normalizeErrorMessage(string $message): string
	{
		$message = preg_replace('/\s+/u', ' ', trim($message)) ?? trim($message);

		if ($message === '') {
			return 'Unknown processing error.';
		}

		return strlen($message) > 1000 ? substr($message, 0, 1000) : $message;
	}
}
