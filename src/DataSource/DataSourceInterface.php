<?php

namespace QuadVector\DBAIRewriter\DataSource;

/**
 * Интерфейс для работы с источником данных, обеспечивающим стандартные операции CRUD и выполнение SQL-запросов.
 */
interface DataSourceInterface
{
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
	): iterable;

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
	): ?array;

	/**
	 * Получить количество записей в таблице, соответствующих критериям.
	 *
	 * @param string $tableName Имя таблицы
	 * @param array $criteria Критерии фильтрации
	 * @return int
	 */
	public function count(string $tableName, array $criteria = []): int;

	/**
	 * Вставить новую запись в таблицу.
	 *
	 * @param string $tableName Имя таблицы
	 * @param array $data Данные для вставки
	 * @return string|int Идентификатор вставленной записи
	 */
	public function insert(string $tableName, array $data): string|int;

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
	): int;

	/**
	 * Удалить записи из таблицы, соответствующие критериям.
	 *
	 * @param string $tableName Имя таблицы
	 * @param array $criteria Критерии фильтрации
	 * @return int Количество удаленных записей
	 */
	public function delete(string $tableName, array $criteria): int;

	/**
	 * Выполнить произвольный SQL-запрос.
	 *
	 * @param string $sql SQL-запрос
	 * @param array $params Параметры запроса
	 * @return iterable Результат выполнения запроса
	 */
	public function query(string $sql, array $params = []): iterable;
}
