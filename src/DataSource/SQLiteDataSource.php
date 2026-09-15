<?php

namespace QuadVector\DBAIRewriter\DataSource;

use SQLite3;

/**
 * Реализация интерфейса DataSourceInterface для работы с SQLite.
 */
class SQLiteDataSource implements DataSourceInterface
{
	private SQLite3 $connection;

	/**
	 * Конструктор
	 * 
	 * @param string $dbPath Путь к файлу базы данных SQLite
	 */
	public function __construct(
		string $dbPath
	) {
		$this->connection = new SQLite3($dbPath);
	}

	/**
	 * Получить все записи из таблицы, соответствующие критериям.
	 *
	 * @param string $tableName Имя таблицы
	 * @param array $criteria Критерии фильтрации
	 * @param array $columns Список столбцов для выборки
	 * @param int|null $limit Лимит на количество записей
	 * @param int|null $offset Смещение для выборки
	 * @return iterable
	 */
	public function findAll(
		string $tableName,
		array $criteria = [],
		array $columns = ['*'],
		?int $limit = null,
		?int $offset = null
	): iterable {
		$query = "SELECT " . implode(", ", $columns) . " FROM " . $tableName;
		if (!empty($criteria)) {
			$query .= " WHERE " . implode(" AND ", array_map(fn($k) => "$k = :$k", array_keys($criteria)));
		}
		if ($limit !== null) {
			$query .= " LIMIT " . $limit;
		}
		if ($offset !== null) {
			$query .= " OFFSET " . $offset;
		}

		$stmt = $this->connection->prepare($query);

		foreach ($criteria as $key => $value) {
			$stmt->bindValue(":$key", $value);
		}

		$queryResult = $stmt->execute();

		while ($row = $queryResult->fetchArray(SQLITE3_ASSOC)) {
			yield $row;
		}
	}

	/**
	 * Получить одну запись из таблицы, соответствующую критериям.
	 *
	 * @param string $tableName Имя таблицы
	 * @param array $criteria Критерии фильтрации
	 * @param array $columns Список столбцов для выборки
	 * @return array|null
	 */
	public function findOne(
		string $tableName,
		array $criteria = [],
		array $columns = ['*']
	): ?array {
		$query = "SELECT " . implode(", ", $columns) . " FROM " . $tableName;

		if (!empty($criteria)) {
			$query .= " WHERE " . implode(" AND ", array_map(fn($k) => "$k = :$k", array_keys($criteria)));
		}

		$stmt = $this->connection->prepare($query);

		foreach ($criteria as $key => $value) {
			$stmt->bindValue(":$key", $value);
		}

		$queryResult = $stmt->execute();

		$row = $queryResult->fetchArray(SQLITE3_ASSOC);
		return $row ?: null;
	}

	/**
	 * Получить количество записей в таблице, соответствующих критериям.
	 *
	 * @param string $tableName Имя таблицы
	 * @param array $criteria Критерии фильтрации
	 * @return int
	 */
	public function count(string $tableName, array $criteria = []): int
	{
		$query = "SELECT COUNT(*) as count FROM " . $tableName;

		if (!empty($criteria)) {
			$query .= " WHERE " . implode(" AND ", array_map(fn($k) => "$k = :$k", array_keys($criteria)));
		}

		$stmt = $this->connection->prepare($query);

		foreach ($criteria as $key => $value) {
			$stmt->bindValue(":$key", $value);
		}

		$queryResult = $stmt->execute();

		$row = $queryResult->fetchArray(SQLITE3_ASSOC);
		return $row ? (int)$row['count'] : 0;
	}

	/**
	 * Вставить новую запись в таблицу.
	 *
	 * @param string $tableName Имя таблицы
	 * @param array $data Данные для вставки
	 * @return string|int Идентификатор вставленной записи
	 */
	public function insert(string $tableName, array $data): string|int
	{
		$columns = array_keys($data);

		$query = "INSERT INTO " . $tableName . " (" . implode(", ", $columns) . ") VALUES (" . implode(", ", array_map(fn($c) => ":$c", $columns)) . ")";
		$stmt = $this->connection->prepare($query);

		foreach ($data as $key => $value) {
			$stmt->bindValue(":$key", $value);
		}
		$stmt->execute();

		return $this->connection->lastInsertRowID();
	}

	/**
	 * Обновить записи в таблице, соответствующие критериям.
	 *
	 * @param string $tableName Имя таблицы
	 * @param array $data Данные для обновления
	 * @param array $criteria Критерии фильтрации
	 * @return int Количество обновленных записей
	 */
	public function update(
		string $tableName,
		array $data,
		array $criteria
	): int {
		$query = "UPDATE " . $tableName;
		if (!empty($data)) {
			$query .= " SET " . implode(", ", array_map(fn($k) => "$k = :$k", array_keys($data)));
		}

		if (!empty($criteria)) {
			$query .= " WHERE " . implode(" AND ", array_map(fn($k) => "$k = :$k", array_keys($criteria)));
		}

		$stmt = $this->connection->prepare($query);

		foreach ($data as $key => $value) {
			$stmt->bindValue(":$key", $value);
		}

		foreach ($criteria as $key => $value) {
			$stmt->bindValue(":$key", $value);
		}

		$stmt->execute();
		return $this->connection->changes();
	}

	/**
	 * Удалить записи из таблицы, соответствующие критериям.
	 *
	 * @param string $tableName Имя таблицы
	 * @param array $criteria Критерии фильтрации
	 * @return int Количество удаленных записей
	 */
	public function delete(string $tableName, array $criteria): int
	{
		$query = "DELETE FROM " . $tableName;

		if (!empty($criteria)) {
			$query .= " WHERE " . implode(" AND ", array_map(fn($k) => "$k = :$k", array_keys($criteria)));
		}

		$stmt = $this->connection->prepare($query);
		foreach ($criteria as $key => $value) {
			$stmt->bindValue(":$key", $value);
		}
		$stmt->execute();

		return $this->connection->changes();
	}

	/**
	 * Выполнить произвольный SQL-запрос.
	 *
	 * @param string $sql SQL-запрос
	 * @param array $params Параметры запроса
	 * @return iterable Результат выполнения запроса
	 */
	public function query(string $sql, array $params = []): iterable
	{
		$query = $this->connection->prepare($sql);
		foreach ($params as $key => $value) {
			$query->bindValue(":$key", $value);
		}
		$result = $query->execute();
		while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
			yield $row;
		}
	}
}
