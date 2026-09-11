<?php

require_once("vendor/autoload.php");

// use QuadVector\DBAIRewriter\DataSource\SQLiteDataSource;

// $test = new SQLiteDataSource("input/db.sqlite3");
// $data = $test->findOne("tasks", [
// 	"id" => 1
// ]);

// var_dump($data);

use QuadVector\DBAIRewriter\LLMGenerator\OpenAILLMGenerator;

$test = new OpenAILLMGenerator("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", "proj_LLtQylyDOVaEhQd6Db98vcH8");
$data = $test->rewrite("Привет, как дела?");
var_dump($data);