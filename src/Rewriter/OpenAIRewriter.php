<?php

namespace QuadVector\DBAIRewriter\Rewriter;

use QuadVector\DBAIRewriter\Rewriter\RewriterInterface;

/**
 * Реализация интерфейса RewriterInterface с использованием OpenAI для переписывания текста.
 */
class OpenAIRewriter implements RewriterInterface
{
	/**
	 * Отправляет исходный текст на переписывание в LLM и возвращает переписанный текст.
	 * @param string $input Исходный текст для переписывания.
	 * 
	 * @return string
	 */
	public function rewrite(string $input): string
	{
		return "OPENAI RESULT";
	}
}
