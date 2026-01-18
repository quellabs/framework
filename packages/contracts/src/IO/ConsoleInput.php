<?php
	
	namespace Quellabs\Contracts\IO;
	
	interface ConsoleInput {
		
		/**
		 * Ask a question and return the answer
		 * @param string $question
		 * @param string|null $default
		 * @return string|null
		 */
		public function ask(string $question, ?string $default = null): ?string;
		
		/**
		 * Ask for confirmation
		 * @param string $question
		 * @param bool $default
		 * @return bool
		 */
		public function confirm(string $question, bool $default = true): bool;
		
		/**
		 * Multiple choice question
		 * @param string $question
		 * @param array $choices
		 * @param ?int $default
		 * @return string
		 */
		public function choice(string $question, array $choices, ?int $default = null): string;
	}