<?php

namespace QuadVector\DBAIRewriter\LLMGenerator;

use GuzzleHttp\Client as GuzzleClient;
use OpenAI;
use OpenAI\Client as OpenAIClient;
use QuadVector\DBAIRewriter\ValueObject\Proxy;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * Стратегия OpenAI для обычной и Batch-генерации.
 */
class OpenAILLMGenerator implements BatchLLMGeneratorInterface
{
	private const BATCH_ENDPOINT = '/v1/chat/completions';
	private const BATCH_COMPLETION_WINDOW = '24h';
	private const BATCH_MAP_FILE = 'map.json';
	private const BATCH_STATUS_FILE = 'status.json';
	private const BATCH_MAX_JSONL_SIZE = 104857600;
	private const BATCH_MAX_REQUESTS_PER_CHUNK = 50000;

	private ?OpenAIClient $client = null;
	private int $httpTimeout = 300;
	private ?string $batchMaxTokensParameter = null;
	private mixed $batchEventHandler = null;

	/**
	 * @param Proxy[]|null $proxy
	 */
	public function __construct(
		private string $apiKey,
		private string $projectID,
		private ?array $proxy = null
	) {}

	/**
	 * @return Proxy[]|null
	 */
	public function getProxy(): ?array
	{
		return $this->proxy;
	}

	public function rewrite(
		string $input,
		?string $model = null,
		float $temperature = 1.0,
		int $maxOutputTokens = 8000
	): string {
		$response = $this->getClient()->responses()->create([
			'model' => $model ?? 'gpt-4o-mini',
			'input' => $input,
			'temperature' => $temperature,
			'max_output_tokens' => $maxOutputTokens,
			'tool_choice' => 'auto',
			'store' => false,
		]);

		return $this->extractGeneratedText($response->toArray());
	}

	/**
	 * Весь OpenAI Batch lifecycle находится в стратегии:
	 * JSONL -> Files API -> Batches API -> polling -> результаты.
	 *
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
		$this->configureBatchOptions($options);
		$this->ensureDirectory($stateDirectory);
		$this->emitBatchEvent('batch_started', [
			'state_directory' => $stateDirectory,
			'force' => $force,
			'poll_interval_seconds' => max(1, $pollIntervalSeconds),
			'max_wait_seconds' => max(0, $maxWaitSeconds),
		]);

		if ($force) {
			$this->emitBatchEvent('force_reset', ['state_directory' => $stateDirectory]);
			$this->clearStateDirectory($stateDirectory);
		}

		$status = $this->loadJsonFile(
			$stateDirectory . DIRECTORY_SEPARATOR . self::BATCH_STATUS_FILE,
			$this->emptyBatchStatus()
		);
		$requestMap = $this->loadJsonFile(
			$stateDirectory . DIRECTORY_SEPARATOR . self::BATCH_MAP_FILE,
			[]
		);

		if (!isset($status['batches']) || !is_array($status['batches'])) {
			throw new RuntimeException(
				'Batch state is inconsistent: status.json does not contain a valid batches map. '
				. 'Run the command with --force-batch to discard the broken local Batch state.'
			);
		}

		$hasStoredBatches = $status['batches'] !== [];
		if ($hasStoredBatches && $this->areAllBatchesSaved($status)) {
			$this->emitBatchEvent('previous_session_saved');
			$this->clearStateDirectory($stateDirectory);
			$status = $this->emptyBatchStatus();
			$requestMap = [];
			$hasStoredBatches = false;
		} elseif ($hasStoredBatches) {
			$this->assertReusableBatchState($stateDirectory, $status, $requestMap);
		}

		$stats = [
			'resumed' => $hasStoredBatches,
			'queued' => count($requestMap),
			'chunks' => count($status['batches'] ?? []),
			'submitted' => 0,
			'submit_skipped' => 0,
			'upload_failed' => 0,
			'success' => 0,
			'failed' => 0,
			'waiting' => 0,
			'batch_statuses' => [],
		];

		if (!$hasStoredBatches) {
			$this->emitBatchEvent('build_started');
			$build = $this->buildBatchState(
				$requests,
				$stateDirectory,
				$model ?? 'gpt-4o-mini',
				$temperature,
				$maxOutputTokens
			);
			$status = $build['status'];
			$requestMap = $build['map'];
			$stats['queued'] = count($requestMap);
			$stats['chunks'] = count($status['batches']);
			$this->emitBatchEvent('build_finished', [
				'queued' => $stats['queued'],
				'chunks' => $stats['chunks'],
			]);
		} else {
			$this->emitBatchEvent('session_resumed', [
				'queued' => $stats['queued'],
				'chunks' => $stats['chunks'],
			]);
		}

		if (empty($status['batches'])) {
			$this->emitBatchEvent('nothing_to_submit');
			return $stats;
		}

		$submitStats = $this->submitBatchesUntilAccepted(
			$stateDirectory,
			$status,
			max(1, $pollIntervalSeconds)
		);
		$stats['submitted'] = $submitStats['submitted'];
		$stats['submit_skipped'] = $submitStats['skipped'];
		$stats['upload_failed'] = $submitStats['failed'];

		$collectStats = $this->collectBatchResults(
			$stateDirectory,
			$status,
			$requestMap,
			$onResult,
			max(1, $pollIntervalSeconds),
			max(0, $maxWaitSeconds)
		);

		$stats = array_replace($stats, $collectStats);
		$this->emitBatchEvent('batch_finished', $stats);

		return $stats;
	}

	private function getClient(): OpenAIClient
	{
		if ($this->client !== null) {
			return $this->client;
		}

		$apiKey = trim($this->apiKey);
		if ($apiKey === '') {
			throw new RuntimeException('OpenAI API key cannot be empty.');
		}

		$httpClientConfig = [
			'timeout' => $this->httpTimeout,
			'connect_timeout' => min(30, $this->httpTimeout),
		];
		$randomProxy = $this->getRandomProxy();

		if ($randomProxy !== null) {
			$credentials = '';
			if ($randomProxy->login !== '' || $randomProxy->password !== '') {
				$credentials = rawurlencode($randomProxy->login)
					. ':'
					. rawurlencode($randomProxy->password)
					. '@';
			}

			$proxyUrl = 'http://'
				. $credentials
				. $randomProxy->ip
				. ':'
				. $randomProxy->port;

			$httpClientConfig['proxy'] = [
				'http' => $proxyUrl,
				'https' => $proxyUrl,
			];
		}

		$factory = OpenAI::factory()
			->withApiKey($apiKey)
			->withHttpClient(new GuzzleClient($httpClientConfig));

		if (trim($this->projectID) !== '') {
			$factory = $factory->withProject(trim($this->projectID));
		}

		$this->client = $factory->make();
		return $this->client;
	}

	private function getRandomProxy(): ?Proxy
	{
		if (!is_array($this->proxy) || count($this->proxy) === 0) {
			return null;
		}

		$proxy = $this->proxy[array_rand($this->proxy)];
		if (!$proxy instanceof Proxy) {
			throw new RuntimeException('Proxy list must contain only Proxy objects.');
		}

		return $proxy;
	}

	/**
	 * @param iterable<array{id: int|string, input: string}> $requests
	 *
	 * @return array{status: array<string, mixed>, map: array<string, array<string, mixed>>}
	 */
	private function buildBatchState(
		iterable $requests,
		string $stateDirectory,
		string $model,
		float $temperature,
		int $maxOutputTokens
	): array {
		$this->clearStateDirectory($stateDirectory);
		$this->ensureDirectory($stateDirectory);

		$status = $this->emptyBatchStatus();
		$requestMap = [];
		$seenIds = [];
		$chunkIndex = 0;
		$chunkCount = 0;
		$chunkSize = 0;
		$chunkKey = null;
		$chunkPath = null;
		$handle = null;

		try {
			foreach ($requests as $request) {
				if (!is_array($request)) {
					throw new RuntimeException('Batch request must be an array.');
				}

				$id = $request['id'] ?? null;
				$input = $request['input'] ?? null;

				if ((!is_int($id) && !is_string($id)) || (is_string($id) && trim($id) === '')) {
					throw new RuntimeException('Batch request ID must be a non-empty string or an integer.');
				}

				if (!is_string($input) || trim($input) === '') {
					throw new RuntimeException("Batch request '{$id}' contains an empty input.");
				}

				$idKey = (string) $id;
				if (isset($seenIds[$idKey])) {
					continue;
				}
				$seenIds[$idKey] = true;

				$customId = $this->makeCustomId($id);
				$line = json_encode(
					$this->makeBatchRequest($customId, $input, $model, $temperature, $maxOutputTokens),
					JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
				) . "\n";
				$lineSize = strlen($line);

				if ($lineSize > self::BATCH_MAX_JSONL_SIZE) {
					throw new RuntimeException("Batch request '{$id}' exceeds the JSONL file size limit.");
				}

				$mustRotate = $handle === null
					|| $chunkCount >= self::BATCH_MAX_REQUESTS_PER_CHUNK
					|| ($chunkCount > 0 && $chunkSize + $lineSize > self::BATCH_MAX_JSONL_SIZE);

				if ($mustRotate) {
					if (is_resource($handle)) {
						fclose($handle);
						$this->emitBatchEvent('chunk_saved', [
							'chunk' => $chunkKey,
							'file' => $chunkPath !== null ? basename($chunkPath) : null,
							'requests' => $chunkCount,
							'bytes' => $chunkSize,
						]);
					}

					$chunkIndex++;
					$chunkKey = sprintf('chunk-%05d', $chunkIndex);
					$chunkPath = $stateDirectory . DIRECTORY_SEPARATOR . $chunkKey . '.jsonl';
					$handle = fopen($chunkPath, 'wb');

					if ($handle === false) {
						throw new RuntimeException("Unable to create Batch chunk: {$chunkPath}");
					}

					$chunkCount = 0;
					$chunkSize = 0;
					$status['batches'][$chunkKey] = [
						'chunk' => $chunkKey,
						'file' => basename($chunkPath),
						'request_count' => 0,
						'input_file_id' => null,
						'batch_id' => null,
						'status' => 'pending',
						'output_file_id' => null,
						'error_file_id' => null,
						'updated_at' => date(DATE_ATOM),
					];
					$this->emitBatchEvent('chunk_started', [
						'chunk' => $chunkKey,
						'file' => basename($chunkPath),
					]);
				}

				if (!is_resource($handle) || $chunkKey === null || $chunkPath === null) {
					throw new RuntimeException('Unable to initialize Batch chunk.');
				}

				$written = fwrite($handle, $line);
				if ($written === false || $written !== $lineSize) {
					throw new RuntimeException("Unable to write Batch chunk: {$chunkPath}");
				}

				$chunkCount++;
				$chunkSize += $lineSize;
				$status['batches'][$chunkKey]['request_count'] = $chunkCount;
				$status['batches'][$chunkKey]['updated_at'] = date(DATE_ATOM);
				$requestMap[$customId] = [
					'id' => $id,
					'chunk' => $chunkKey,
				];
			}

			if (is_resource($handle)) {
				fclose($handle);
				$handle = null;
				$this->emitBatchEvent('chunk_saved', [
					'chunk' => $chunkKey,
					'file' => $chunkPath !== null ? basename($chunkPath) : null,
					'requests' => $chunkCount,
					'bytes' => $chunkSize,
				]);
			}
		} finally {
			if (is_resource($handle)) {
				fclose($handle);
			}
		}

		$this->saveJsonFile(
			$stateDirectory . DIRECTORY_SEPARATOR . self::BATCH_MAP_FILE,
			$requestMap
		);
		$this->saveBatchStatus($stateDirectory, $status);

		return ['status' => $status, 'map' => $requestMap];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function makeBatchRequest(
		string $customId,
		string $input,
		string $model,
		float $temperature,
		int $maxOutputTokens
	): array {
		$body = [
			'model' => $model,
			'messages' => [
				['role' => 'user', 'content' => $input],
			],
			'temperature' => $temperature,
			'store' => false,
		];

		if ($maxOutputTokens > 0) {
			$body[$this->getBatchMaxTokensParameter($model)] = $maxOutputTokens;
		}

		return [
			'custom_id' => $customId,
			'method' => 'POST',
			'url' => self::BATCH_ENDPOINT,
			'body' => $body,
		];
	}

	private function makeCustomId(int|string $id): string
	{
		return 'request-' . substr(hash('sha256', gettype($id) . ':' . (string) $id), 0, 48);
	}

	private function getBatchMaxTokensParameter(string $model): string
	{
		if ($this->batchMaxTokensParameter !== null) {
			return $this->batchMaxTokensParameter;
		}

		$model = strtolower($model);

		return preg_match('/^(?:gpt-5|o1|o3|o4)(?:[-_.]|$)/', $model) === 1
			? 'max_completion_tokens'
			: 'max_tokens';
	}

	/**
	 * @param array<string, mixed> $options
	 */
	private function configureBatchOptions(array $options): void
	{
		$this->httpTimeout = max(30, (int) ($options['http_timeout'] ?? 300));
		$eventHandler = $options['event_handler'] ?? null;

		if ($eventHandler !== null && !is_callable($eventHandler)) {
			throw new RuntimeException("Batch option 'event_handler' must be callable.");
		}

		$this->batchEventHandler = $eventHandler;

		$maxTokensParameter = $options['max_tokens_parameter'] ?? null;
		if ($maxTokensParameter === null || trim((string) $maxTokensParameter) === '') {
			$this->batchMaxTokensParameter = null;
		} else {
			$maxTokensParameter = trim((string) $maxTokensParameter);
			if (!in_array($maxTokensParameter, ['max_tokens', 'max_completion_tokens'], true)) {
				throw new RuntimeException(
					"Batch option 'max_tokens_parameter' must be max_tokens or max_completion_tokens."
				);
			}

			$this->batchMaxTokensParameter = $maxTokensParameter;
		}

		// Batch может запускаться после обычных запросов тем же объектом.
		// Пересоздаём клиент, чтобы гарантированно применить новый timeout.
		$this->client = null;
	}

	/**
	 * Повторяет отправку локальных pending-чанков, пока каждый из них не будет
	 * принят Batch API. Пауза между попытками совпадает с интервалом polling.
	 *
	 * @param array<string, mixed> $status
	 * @return array{submitted: int, failed: int, skipped: int}
	 */
	private function submitBatchesUntilAccepted(
		string $stateDirectory,
		array &$status,
		int $retryIntervalSeconds
	): array {
		$submitted = 0;
		$failed = 0;
		$skipped = 0;

		foreach (($status['batches'] ?? []) as $batchData) {
			$currentStatus = is_array($batchData)
				? (string) ($batchData['status'] ?? 'pending')
				: 'pending';

			if (!in_array($currentStatus, ['pending', 'failed', 'expired', 'cancelled'], true)) {
				$skipped++;
			}
		}

		while (true) {
			$round = $this->submitPendingBatches($stateDirectory, $status);
			$submitted += $round['submitted'];
			$failed += $round['failed'];

			$pending = 0;
			foreach (($status['batches'] ?? []) as $batchData) {
				if (is_array($batchData) && ($batchData['status'] ?? 'pending') === 'pending') {
					$pending++;
				}
			}

			if ($pending === 0) {
				break;
			}

			$this->emitBatchEvent('submit_retry_sleep', [
				'pending' => $pending,
				'seconds' => $retryIntervalSeconds,
			]);
			sleep($retryIntervalSeconds);

			$status = $this->loadJsonFile(
				$stateDirectory . DIRECTORY_SEPARATOR . self::BATCH_STATUS_FILE,
				$status
			);
		}

		return [
			'submitted' => $submitted,
			'failed' => $failed,
			'skipped' => $skipped,
		];
	}

	/**
	 * @param array<string, mixed> $status
	 * @return array{submitted: int, failed: int, skipped: int}
	 */
	private function submitPendingBatches(string $stateDirectory, array &$status): array
	{
		$submitted = 0;
		$failed = 0;
		$skipped = 0;
		$total = count($status['batches'] ?? []);
		$retryableStatuses = ['pending', 'failed', 'expired', 'cancelled'];

		$this->emitBatchEvent('submit_started', ['total' => $total]);

		foreach ($status['batches'] as $chunkKey => &$batchData) {
			$currentStatus = (string) ($batchData['status'] ?? 'pending');

			if (!in_array($currentStatus, $retryableStatuses, true)) {
				$skipped++;
				$this->emitBatchEvent('submit_skipped', [
					'chunk' => (string) $chunkKey,
					'status' => $currentStatus,
				]);
				continue;
			}

			try {
				$this->emitBatchEvent('chunk_submitting', [
					'chunk' => (string) $chunkKey,
					'previous_status' => $currentStatus,
				]);

				if ($currentStatus !== 'pending' && is_string($batchData['batch_id'] ?? null)) {
					$batchData['previous_batch_ids'] ??= [];
					$batchData['previous_batch_ids'][] = $batchData['batch_id'];
				}

				$inputFileId = $batchData['input_file_id'] ?? null;

				if (!is_string($inputFileId) || $inputFileId === '') {
					$chunkPath = $stateDirectory . DIRECTORY_SEPARATOR . (string) $batchData['file'];
					$uploadedFile = $this->uploadBatchFile($chunkPath);
					$inputFileId = $uploadedFile['id'] ?? null;

					if (!is_string($inputFileId) || $inputFileId === '') {
						throw new RuntimeException('OpenAI Files API returned no file ID.');
					}

					$batchData['input_file_id'] = $inputFileId;
					$batchData['updated_at'] = date(DATE_ATOM);
					$this->saveBatchStatus($stateDirectory, $status);
				}

				$batch = $this->createOpenAIBatch($inputFileId, [
					'session_id' => (string) $status['session_id'],
					'chunk' => (string) $chunkKey,
				]);
				$batchId = $batch['id'] ?? null;

				if (!is_string($batchId) || $batchId === '') {
					throw new RuntimeException('OpenAI Batch API returned no batch ID.');
				}

				$batchData['batch_id'] = $batchId;
				$batchData['status'] = (string) ($batch['status'] ?? 'submitted');
				$batchData['output_file_id'] = null;
				$batchData['error_file_id'] = null;
				$batchData['last_error'] = null;
				$batchData['updated_at'] = date(DATE_ATOM);
				$submitted++;
				$this->emitBatchEvent('chunk_submitted', [
					'chunk' => (string) $chunkKey,
					'batch_id' => $batchId,
					'status' => $batchData['status'],
				]);
			} catch (Throwable $exception) {
				$batchData['status'] = 'pending';
				$batchData['last_error'] = $this->normalizeError($exception->getMessage());
				$batchData['updated_at'] = date(DATE_ATOM);
				$failed++;
				$this->emitBatchEvent('chunk_submit_failed', [
					'chunk' => (string) $chunkKey,
					'error' => $batchData['last_error'],
				]);
			}

			$this->saveBatchStatus($stateDirectory, $status);
		}
		unset($batchData);

		$this->emitBatchEvent('submit_finished', [
			'total' => $total,
			'submitted' => $submitted,
			'skipped' => $skipped,
			'failed' => $failed,
		]);

		return ['submitted' => $submitted, 'failed' => $failed, 'skipped' => $skipped];
	}

	/**
	 * @param array<string, mixed> $status
	 * @param array<string, array<string, mixed>> $requestMap
	 * @param callable(array<string, mixed>): void $onResult
	 * @return array<string, mixed>
	 */
	private function collectBatchResults(
		string $stateDirectory,
		array &$status,
		array $requestMap,
		callable $onResult,
		int $pollIntervalSeconds,
		int $maxWaitSeconds
	): array {
		$deadline = $maxWaitSeconds > 0
			? time() + $maxWaitSeconds
			: null;
		$iteration = 0;
		$stats = [
			'success' => 0,
			'failed' => 0,
			'failed_batches' => 0,
			'waiting' => 0,
			'iterations' => 0,
			'batch_statuses' => [],
		];
		$this->emitBatchEvent('collect_started', [
			'total' => count($status['batches'] ?? []),
			'poll_interval_seconds' => $pollIntervalSeconds,
		]);

		do {
			$iteration++;
			$stats['iterations'] = $iteration;
			$waiting = 0;
			$this->emitBatchEvent('poll_iteration_started', ['iteration' => $iteration]);

			foreach ($status['batches'] as $chunkKey => &$batchData) {
				$currentStatus = (string) ($batchData['status'] ?? 'pending');
				$this->emitBatchEvent('chunk_status', [
					'chunk' => (string) $chunkKey,
					'status' => $currentStatus,
					'batch_id' => $batchData['batch_id'] ?? null,
				]);

				if ($currentStatus === 'saved') {
					$this->emitBatchEvent('chunk_already_saved', [
						'chunk' => (string) $chunkKey,
					]);
					continue;
				}

				$batchId = $batchData['batch_id'] ?? null;
				if (!is_string($batchId) || $batchId === '') {
					throw new RuntimeException(
						"Batch chunk '{$chunkKey}' has no batch_id after the submit stage."
					);
				}

				try {
					$this->emitBatchEvent('chunk_retrieving', [
						'chunk' => (string) $chunkKey,
						'batch_id' => $batchId,
					]);
					$remoteBatch = $this->retrieveOpenAIBatch($batchId);
					$currentStatus = (string) ($remoteBatch['status'] ?? $currentStatus);
					$batchData['status'] = $currentStatus;
					$batchData['output_file_id'] = $this->getArrayValue(
						$remoteBatch,
						'output_file_id',
						'outputFileId'
					);
					$batchData['error_file_id'] = $this->getArrayValue(
						$remoteBatch,
						'error_file_id',
						'errorFileId'
					);
					$batchData['request_counts'] = $this->getArrayValue(
						$remoteBatch,
						'request_counts',
						'requestCounts'
					);
					$batchData['updated_at'] = date(DATE_ATOM);
					$this->emitBatchEvent('chunk_remote_status', [
						'chunk' => (string) $chunkKey,
						'batch_id' => $batchId,
						'status' => $currentStatus,
					]);

					if ($currentStatus === 'completed') {
						$this->emitBatchEvent('chunk_completed', [
							'chunk' => (string) $chunkKey,
							'batch_id' => $batchId,
						]);
						$resultStats = $this->deliverBatchResults(
							(string) $chunkKey,
							$batchData,
							$requestMap,
							$onResult
						);

						$stats['success'] += $resultStats['success'];
						$stats['failed'] += $resultStats['failed'];
						$batchData['status'] = 'saved';
						$batchData['saved_at'] = date(DATE_ATOM);
						$batchData['saved_results_count'] = $resultStats['success'];
						$batchData['failed_results_count'] = $resultStats['failed'];
						$this->emitBatchEvent('chunk_results_saved', [
							'chunk' => (string) $chunkKey,
							'success' => $resultStats['success'],
							'failed' => $resultStats['failed'],
						]);
					} elseif (in_array($currentStatus, ['failed', 'expired', 'cancelled'], true)) {
						$stats['failed_batches']++;
						$this->saveBatchErrorFile($stateDirectory, (string) $chunkKey, $batchData);
						$this->emitBatchEvent('chunk_terminal_failure', [
							'chunk' => (string) $chunkKey,
							'batch_id' => $batchId,
							'status' => $currentStatus,
						]);
					} else {
						$waiting++;
					}
				} catch (Throwable $exception) {
					$batchData['last_error'] = $this->normalizeError($exception->getMessage());
					$batchData['updated_at'] = date(DATE_ATOM);
					$waiting++;
					$this->emitBatchEvent('chunk_collect_failed', [
						'chunk' => (string) $chunkKey,
						'error' => $batchData['last_error'],
					]);
				}

				$this->saveBatchStatus($stateDirectory, $status);
			}
			unset($batchData);

			$statusCounts = $this->countBatchStatuses($status);
			$this->emitBatchEvent('poll_iteration_finished', [
				'iteration' => $iteration,
				'statuses' => $statusCounts,
				'saved_results' => $stats['success'],
				'failed_results' => $stats['failed'],
			]);

			if ($waiting === 0) {
				$stats['waiting'] = $waiting;
				$this->emitBatchEvent('all_batches_collected', [
					'statuses' => $statusCounts,
				]);
				break;
			}

			if ($deadline !== null && time() >= $deadline) {
				$stats['waiting'] = $waiting;
				$this->emitBatchEvent('max_wait_reached', [
					'waiting' => $waiting,
					'max_wait_seconds' => $maxWaitSeconds,
				]);
				break;
			}

			$sleepSeconds = $deadline === null
				? $pollIntervalSeconds
				: min($pollIntervalSeconds, max(1, $deadline - time()));
			$this->emitBatchEvent('poll_sleep', ['seconds' => $sleepSeconds]);
			sleep($sleepSeconds);

			$status = $this->loadJsonFile(
				$stateDirectory . DIRECTORY_SEPARATOR . self::BATCH_STATUS_FILE,
				$status
			);
		} while (true);

		$stats['batch_statuses'] = $this->countBatchStatuses($status);

		return $stats;
	}

	/**
	 * @param array<string, mixed> $batchData
	 * @param array<string, array<string, mixed>> $requestMap
	 * @param callable(array<string, mixed>): void $onResult
	 * @return array{success: int, failed: int}
	 */
	private function deliverBatchResults(
		string $chunkKey,
		array $batchData,
		array $requestMap,
		callable $onResult
	): array {
		$expected = [];
		foreach ($requestMap as $customId => $mapData) {
			if (($mapData['chunk'] ?? null) === $chunkKey) {
				$expected[$customId] = $mapData;
			}
		}

		$processed = [];
		$success = 0;
		$failed = 0;
		$fileIds = [];

		foreach (['output_file_id', 'error_file_id'] as $fileIdKey) {
			$fileId = $batchData[$fileIdKey] ?? null;
			if (is_string($fileId) && $fileId !== '') {
				$fileIds[$fileId] = true;
			}
		}

		foreach (array_keys($fileIds) as $fileId) {
			$content = $this->downloadOpenAIFile($fileId);
			$lines = preg_split('/\r?\n/', trim($content)) ?: [];

			foreach ($lines as $line) {
				if (trim($line) === '') {
					continue;
				}

				$result = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
				$customId = is_array($result) ? ($result['custom_id'] ?? null) : null;

				if (!is_string($customId) || !isset($expected[$customId]) || isset($processed[$customId])) {
					continue;
				}

				$processed[$customId] = true;
				$rowId = $expected[$customId]['id'];
				$statusCode = (int) ($result['response']['status_code'] ?? 0);
				$body = $result['response']['body'] ?? [];
				$error = $result['error'] ?? ($body['error'] ?? null);

				if ($statusCode >= 200 && $statusCode < 300 && is_array($body)) {
					$text = $this->extractGeneratedText($body);

					if (trim($text) !== '') {
						$onResult([
							'id' => $rowId,
							'status' => 'success',
							'content' => $text,
						]);
						$success++;
						continue;
					}

					$error = 'OpenAI returned an empty Batch result.';
				}

				$onResult([
					'id' => $rowId,
					'status' => 'failed',
					'error' => $this->normalizeError($error ?: $body),
				]);
				$failed++;
			}
		}

		$terminalStatus = (string) ($batchData['status'] ?? 'failed');
		foreach ($expected as $customId => $mapData) {
			if (isset($processed[$customId])) {
				continue;
			}

			$onResult([
				'id' => $mapData['id'],
				'status' => 'failed',
				'error' => "No result was returned for the request. Batch status: {$terminalStatus}.",
			]);
			$failed++;
		}

		return ['success' => $success, 'failed' => $failed];
	}

	/**
	 * Эти методы protected, чтобы сетевую часть можно было подменить в тестах.
	 *
	 * @return array<string, mixed>
	 */
	protected function uploadBatchFile(string $filePath): array
	{
		$handle = fopen($filePath, 'rb');
		if ($handle === false) {
			throw new RuntimeException("Unable to open Batch file: {$filePath}");
		}

		try {
			return $this->getClient()->files()->upload([
				'purpose' => 'batch',
				'file' => $handle,
			])->toArray();
		} finally {
			// Некоторые версии SDK закрывают переданный поток самостоятельно.
			if (is_resource($handle)) {
				fclose($handle);
			}
		}
	}

	/**
	 * @param array<string, string> $metadata
	 * @return array<string, mixed>
	 */
	protected function createOpenAIBatch(string $inputFileId, array $metadata): array
	{
		return $this->getClient()->batches()->create([
			'input_file_id' => $inputFileId,
			'endpoint' => self::BATCH_ENDPOINT,
			'completion_window' => self::BATCH_COMPLETION_WINDOW,
			'metadata' => $metadata,
		])->toArray();
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function retrieveOpenAIBatch(string $batchId): array
	{
		return $this->getClient()->batches()->retrieve($batchId)->toArray();
	}

	protected function downloadOpenAIFile(string $fileId): string
	{
		return $this->getClient()->files()->download($fileId);
	}

	/**
	 * @param array<string, mixed> $response
	 */
	private function extractGeneratedText(array $response): string
	{
		$outputText = $response['output_text'] ?? null;
		if (is_string($outputText) && trim($outputText) !== '') {
			return $outputText;
		}

		$chatContent = $response['choices'][0]['message']['content'] ?? null;
		if (is_string($chatContent)) {
			return $chatContent;
		}

		if (is_array($chatContent)) {
			$parts = [];
			foreach ($chatContent as $part) {
				if (is_array($part) && is_string($part['text'] ?? null)) {
					$parts[] = $part['text'];
				}
			}
			if ($parts !== []) {
				return implode('', $parts);
			}
		}

		foreach (($response['output'] ?? []) as $output) {
			if (!is_array($output)) {
				continue;
			}

			foreach (($output['content'] ?? []) as $content) {
				if (is_array($content) && is_string($content['text'] ?? null)) {
					return $content['text'];
				}
			}
		}

		return '';
	}

	/**
	 * @param array<string, mixed> $status
	 * @return array<string, int>
	 */
	private function countBatchStatuses(array $status): array
	{
		$counts = [];

		foreach (($status['batches'] ?? []) as $batchData) {
			$currentStatus = is_array($batchData)
				? (string) ($batchData['status'] ?? 'unknown')
				: 'unknown';
			$counts[$currentStatus] = ($counts[$currentStatus] ?? 0) + 1;
		}

		return $counts;
	}

	/**
	 * @param array<string, mixed> $batchData
	 */
	private function saveBatchErrorFile(
		string $stateDirectory,
		string $chunkKey,
		array $batchData
	): void {
		$errorFileId = $batchData['error_file_id'] ?? null;
		if (!is_string($errorFileId) || $errorFileId === '') {
			return;
		}

		try {
			$content = $this->downloadOpenAIFile($errorFileId);
			$fileName = 'errors-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', $chunkKey) . '.jsonl';
			$path = $stateDirectory . DIRECTORY_SEPARATOR . $fileName;
			$written = file_put_contents($path, $content, LOCK_EX);

			if ($written === false || $written !== strlen($content)) {
				throw new RuntimeException("Unable to write Batch error file: {$path}");
			}

			$this->emitBatchEvent('error_file_saved', [
				'chunk' => $chunkKey,
				'path' => $path,
			]);
		} catch (Throwable $exception) {
			$this->emitBatchEvent('error_file_save_failed', [
				'chunk' => $chunkKey,
				'error' => $this->normalizeError($exception->getMessage()),
			]);
		}
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function emitBatchEvent(string $event, array $context = []): void
	{
		if ($this->batchEventHandler === null) {
			return;
		}

		($this->batchEventHandler)($event, $context);
	}

	/**
	 * Не даёт продолжить незавершённую сессию без карты запросов или JSONL-чанков.
	 * Иначе ответы OpenAI невозможно безопасно сопоставить со строками БД.
	 *
	 * @param array<string, mixed> $status
	 * @param array<string, array<string, mixed>> $requestMap
	 */
	private function assertReusableBatchState(
		string $stateDirectory,
		array $status,
		array $requestMap
	): void {
		if ($requestMap === []) {
			throw new RuntimeException(
				'Batch state is inconsistent: an unfinished session exists, but map.json is empty. '
				. 'Run the command with --force-batch to discard the broken local Batch state.'
			);
		}

		$chunkKeys = [];
		foreach ($status['batches'] as $chunkKey => $batchData) {
			if (!is_array($batchData)) {
				throw new RuntimeException(
					"Batch state is inconsistent: invalid status for chunk '{$chunkKey}'. "
					. 'Run the command with --force-batch to discard the broken local Batch state.'
				);
			}

			$fileName = $batchData['file'] ?? null;
			if (!is_string($fileName) || $fileName === '') {
				throw new RuntimeException(
					"Batch state is inconsistent: chunk '{$chunkKey}' has no JSONL file name. "
					. 'Run the command with --force-batch to discard the broken local Batch state.'
				);
			}

			$chunkPath = $stateDirectory . DIRECTORY_SEPARATOR . $fileName;
			if (!is_file($chunkPath)) {
				throw new RuntimeException(
					"Batch state is inconsistent: JSONL chunk is missing: {$chunkPath}. "
					. 'Run the command with --force-batch to discard the broken local Batch state.'
				);
			}

			$chunkKeys[(string) $chunkKey] = true;
		}

		foreach ($requestMap as $customId => $mapData) {
			$chunkKey = is_array($mapData) ? ($mapData['chunk'] ?? null) : null;
			if (!is_string($customId) || !is_string($chunkKey) || !isset($chunkKeys[$chunkKey])) {
				throw new RuntimeException(
					'Batch state is inconsistent: map.json contains an invalid request mapping. '
					. 'Run the command with --force-batch to discard the broken local Batch state.'
				);
			}
		}
	}

	/**
	 * @param array<string, mixed> $status
	 */
	private function areAllBatchesSaved(array $status): bool
	{
		$batches = $status['batches'] ?? [];
		if (!is_array($batches) || $batches === []) {
			return false;
		}

		foreach ($batches as $batchData) {
			if (!is_array($batchData) || ($batchData['status'] ?? null) !== 'saved') {
				return false;
			}
		}

		return true;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function emptyBatchStatus(): array
	{
		try {
			$random = bin2hex(random_bytes(8));
		} catch (Throwable) {
			$random = str_replace('.', '', uniqid('', true));
		}

		return [
			'version' => 1,
			'session_id' => date('YmdHis') . '-' . $random,
			'endpoint' => self::BATCH_ENDPOINT,
			'created_at' => date(DATE_ATOM),
			'batches' => [],
		];
	}

	/**
	 * @param array<string, mixed> $status
	 */
	private function saveBatchStatus(string $stateDirectory, array $status): void
	{
		$status['updated_at'] = date(DATE_ATOM);
		$this->saveJsonFile(
			$stateDirectory . DIRECTORY_SEPARATOR . self::BATCH_STATUS_FILE,
			$status
		);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function saveJsonFile(string $path, array $data): void
	{
		$content = json_encode(
			$data,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
		) . "\n";

		$written = file_put_contents($path, $content, LOCK_EX);
		if ($written === false || $written !== strlen($content)) {
			throw new RuntimeException("Unable to write file: {$path}");
		}
	}

	/**
	 * @param array<string, mixed> $default
	 * @return array<string, mixed>
	 */
	private function loadJsonFile(string $path, array $default): array
	{
		if (!is_file($path)) {
			return $default;
		}

		$content = file_get_contents($path);
		if ($content === false) {
			throw new RuntimeException("Unable to read file: {$path}");
		}

		$data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($data)) {
			throw new RuntimeException("File contains invalid JSON object: {$path}");
		}

		return $data;
	}

	private function ensureDirectory(string $path): void
	{
		if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
			throw new RuntimeException("Unable to create directory: {$path}");
		}
	}

	/**
	 * Force Batch очищает только содержимое отдельной папки состояния.
	 */
	private function clearStateDirectory(string $path): void
	{
		if (!is_dir($path)) {
			return;
		}

		if (is_link($path)) {
			throw new RuntimeException("Refusing to clear a symbolic-link directory: {$path}");
		}

		$target = realpath($path);
		if ($target === false) {
			throw new RuntimeException("Unable to resolve Batch state directory: {$path}");
		}

		$normalized = rtrim(str_replace('\\', '/', $target), '/');
		if ($normalized === '' || preg_match('#^[A-Za-z]:$#', $normalized) === 1) {
			throw new RuntimeException("Refusing to clear unsafe directory: {$target}");
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($target, RecursiveDirectoryIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($iterator as $item) {
			$itemPath = $item->getPathname();
			if ($item->isDir() && !$item->isLink()) {
				if (!rmdir($itemPath)) {
					throw new RuntimeException("Unable to remove directory: {$itemPath}");
				}
			} elseif (!unlink($itemPath)) {
				throw new RuntimeException("Unable to remove file: {$itemPath}");
			}
		}
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function getArrayValue(array $data, string $snakeCase, string $camelCase): mixed
	{
		return $data[$snakeCase] ?? $data[$camelCase] ?? null;
	}

	private function normalizeError(mixed $error): string
	{
		if (is_string($error)) {
			$error = trim(preg_replace('/\s+/', ' ', $error) ?? $error);
			if ($error === '') {
				return 'Unknown Batch error.';
			}

			return function_exists('mb_substr')
				? mb_substr($error, 0, 2000)
				: substr($error, 0, 2000);
		}

		try {
			$message = json_encode(
				$error,
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
			);

			return function_exists('mb_substr')
				? mb_substr($message, 0, 2000)
				: substr($message, 0, 2000);
		} catch (Throwable) {
			return 'Unknown Batch error.';
		}
	}
}
