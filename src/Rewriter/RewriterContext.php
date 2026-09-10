<?php

namespace QuadVector\DBAIRewriter\Rewriter;

/**
 * Контекст для работы с объектом, реализующим интерфейс RewriterInterface.
 */
class RewriterContext
{
	/**
	 * Конструктор
	 * @param RewriterInterface $rewriter Экземпляр объекта Rewriter, который будет использоваться для переписывания текста.
	 */
	public function __construct(
		private RewriterInterface $rewriter
	) {}

	/**
	 * Получает экземпляр объекта Rewriter.
	 * @return RewriterInterface
	 */
	public function getRewriter(): RewriterInterface
	{
		return $this->rewriter;
	}

	/**
	 * Отправляет исходный текст на переписывание в LLM и возвращает переписанный текст.
	 * @param string $input Исходный текст для переписывания.
	 * 
	 * @return string
	 */
	public function rewrite(string $input): string
	{
		return $this->getRewriter()->rewrite($input);
	}
}
