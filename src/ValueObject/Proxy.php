<?php

namespace QuadVector\DBAIRewriter\ValueObject;

use InvalidArgumentException;

final class Proxy
{
	/**
	 * Конструктор
	 * @param string $ip IP сервера
	 * @param int $port Порт
	 * @param string|null $login Логин
	 * @param string|null $password Пароль
	 */
	public function __construct(
		public readonly string $ip,
		public readonly int $port,
		public readonly ?string $login = null,
		public readonly ?string $password = null,
	) {
		if ($this->ip === '') {
			throw new InvalidArgumentException('IP прокси не может быть пустым');
		}

		if ($this->port < 1 || $this->port > 65535) {
			throw new InvalidArgumentException('Порт прокси вне допустимого диапазона');
		}

		if (($this->login === null) !== ($this->password === null)) {
			throw new InvalidArgumentException('Логин и пароль должны быть указаны вместе');
		}

		if ($this->login === '' || $this->password === '') {
			throw new InvalidArgumentException('Логин и пароль не могут быть пустыми строками');
		}
	}

	/**
	 * Сгенерировать объект из строки формата:
	 * ip:port
	 * ip:port:login:password
	 *
	 * @param string $proxy
	 * @throws InvalidArgumentException
	 * @return self
	 */
	public static function fromString(string $proxy): self
	{
		$parts = explode(':', trim($proxy));

		if (!in_array(count($parts), [2, 4], true)) {
			throw new InvalidArgumentException("Некорректный формат proxy: {$proxy}");
		}

		[$ip, $port] = $parts;

		if (!ctype_digit($port)) {
			throw new InvalidArgumentException("Некорректный порт proxy: {$proxy}");
		}

		return new self(
			ip: $ip,
			port: (int) $port,
			login: $parts[2] ?? null,
			password: $parts[3] ?? null,
		);
	}

	/**
	 * Сгенерировать строку в формате:
	 * ip:port
	 * ip:port:login:password
	 *
	 * @return string
	 */
	public function toString(): string
	{
		if ($this->login === null && $this->password === null) {
			return $this->ip . ':' . $this->port;
		}

		return implode(':', [
			$this->ip,
			$this->port,
			$this->login,
			$this->password,
		]);
	}
}
