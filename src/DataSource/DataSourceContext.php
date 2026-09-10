<?php

namespace QuadVector\DBAIRewriter\DataSource;

/**
 * Контекст для работы с источником данных, реализующим интерфейс DataSourceInterface.
 */
class DataSourceContext
{
	/**
	 * Конструктор
	 * 
	 * @param DataSourceInterface $dataSource Источник данных, который будет использоваться контекстом.
	 */
	public function __construct(
		private DataSourceInterface $dataSource
	) {}

	/**
	 * Получает текущий источник данных.
	 *
	 * @return DataSourceInterface
	 */
	public function getDataSource(): DataSourceInterface
	{
		return $this->dataSource;
	}

	/**
	 * Выполняет поиск всех записей в указанной таблице, соответствующих критериям.
	 *
	 * @param string $tableName Имя таблицы.
	 * @param array $criteria Критерии поиска.
	 * @param array $columns Столбцы для выборки.
	 * @param int|null $limit Ограничение на количество записей.
	 * @param int|null $offset Смещение для выборки.
	 *
	 * @return iterable Результат поиска.
	 */
	public function findAll(
		string $tableName,
		array $criteria = [],
		array $columns = ['*'],
		?int $limit = null,
		?int $offset = null
	): iterable {
		return $this->getDataSource()->findAll($tableName, $criteria, $columns, $limit, $offset);
	}

	/**
	 * Выполняет поиск одной записи в указанной таблице, соответствующей критериям.
	 *
	 * @param string $tableName Имя таблицы.
	 * @param array $criteria Критерии поиска.
	 * @param array $columns Столбцы для выборки.
	 *
	 * @return array|null Результат поиска или null, если запись не найдена.
	 */
	public function findOne(
		string $tableName,
		array $criteria = [],
		array $columns = ['*']
	): ?array {
		return $this->getDataSource()->findOne($tableName, $criteria, $columns);
	}

	/**
	 * Подсчитывает количество записей в указанной таблице, соответствующих критериям.
	 *
	 * @param string $tableName Имя таблицы.
	 * @param array $criteria Критерии поиска.
	 *
	 * @return int Количество записей.
	 */
	public function count(string $tableName, array $criteria = []): int
	{
		return $this->getDataSource()->count($tableName, $criteria);
	}

	/**
	 * Вставляет новую запись в указанную таблицу.
	 *
	 * @param string $tableName Имя таблицы.
	 * @param array $data Данные для вставки.
	 *
	 * @return string|int Идентификатор вставленной записи или количество затронутых строк.
	 */
	public function insert(string $tableName, array $data): string|int
	{
		return $this->getDataSource()->insert($tableName, $data);
	}

	/**
	 * Обновляет записи в указанной таблице, соответствующие критериям.
	 *
	 * @param string $tableName Имя таблицы.
	 * @param array $data Данные для обновления.
	 * @param array $criteria Критерии поиска записей для обновления.
	 *
	 * @return int Количество затронутых строк.
	 */
	public function update(
		string $tableName,
		array $data,
		array $criteria
	): int {
		return $this->getDataSource()->update($tableName, $data, $criteria);
	}

	/**
	 * Удаляет записи из указанной таблицы, соответствующие критериям.
	 *
	 * @param string $tableName Имя таблицы.
	 * @param array $criteria Критерии поиска записей для удаления.
	 *
	 * @return int Количество затронутых строк.
	 */
	public function delete(string $tableName, array $criteria): int
	{
		return $this->getDataSource()->delete($tableName, $criteria);
	}

	/**
	 * Выполняет произвольный SQL-запрос.
	 *
	 * @param string $sql SQL-запрос.
	 * @param array $params Параметры запроса.
	 *
	 * @return iterable Результат выполнения запроса.
	 */
	public function query(string $sql, array $params = []): iterable
	{
		return $this->getDataSource()->query($sql, $params);
	}
}
