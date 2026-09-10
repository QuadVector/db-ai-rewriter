<?php

namespace QuadVector\DBAIRewriter\Rewriter;

/**
 * Интерфейс для объектов, способных переписывать текст с использованием LLM.
 */
interface RewriterInterface
{
	/**
	 * Отправляет исходный текст на переписывание в LLM и возвращает переписанный текст.
	 * @param string $input Исходный текст для переписывания.
	 * 
	 * @return string
	 */
	public function rewrite(string $input): string;
}
