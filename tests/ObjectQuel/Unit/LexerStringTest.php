<?php

namespace Quellabs\ObjectQuel\Tests\Unit;

use ErrorException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quellabs\ObjectQuel\ObjectQuel\Lexer;
use Quellabs\ObjectQuel\ObjectQuel\LexerException;

class LexerStringTest extends TestCase {

	/**
	 * Lists string literals that end without a closing quote.
	 * @return array<string, array{string}>
	 */
	public static function unterminatedStrings(): array {
		return [
			'empty double quote' => ['"'],
			'nonempty double quote' => ['"abc'],
			'empty single quote' => ["'"],
			'nonempty single quote' => ["'abc"],
		];
	}

	/**
	 * Reports end of input as a lexer error without accessing an invalid offset.
	 * @param string $source
	 * @return void
	 */
	#[DataProvider('unterminatedStrings')]
	public function testUnterminatedStringAtEndOfInput(string $source): void {
		set_error_handler(static function (int $severity, string $message): never {
			throw new ErrorException($message, 0, $severity);
		});

		try {
			$this->expectException(LexerException::class);
			$this->expectExceptionMessage('Unexpected end of data');
			new Lexer($source);
		} finally {
			restore_error_handler();
		}
	}
}
