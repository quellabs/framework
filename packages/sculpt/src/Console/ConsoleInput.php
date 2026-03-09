<?php
	
	namespace Quellabs\Sculpt\Console;
	
	/**
	 * Console Input Handler
	 *
	 * Provides methods for interactive console input including questions,
	 * confirmations, and multiple choice prompts. Handles user input with
	 * proper validation, default values, and EOF detection.
	 */
	class ConsoleInput implements \Quellabs\Contracts\IO\ConsoleInput {
		
		/**
		 * @var resource The input stream (usually STDIN)
		 */
		protected $input;
		
		/**
		 * @var ConsoleOutput Output object
		 */
		protected ConsoleOutput $output;
		
		/**
		 * ConsoleInput Constructor
		 * @param ConsoleOutput $output
		 * @param resource|null $input Input stream (defaults to STDIN)
		 */
		public function __construct(ConsoleOutput $output, $input = null) {
			$this->input = $input ?? STDIN;
			$this->output = $output;
		}
		
		/**
		 * Ask a question and return the answer
		 * @param string $question
		 * @param string|null $default
		 * @return string|null
		 */
		public function ask(string $question, ?string $default = null): ?string {
			$this->output->write($question);
			
			if ($default !== null) {
				$this->output->write(" (default: $default):\n> ");
			} else {
				$this->output->write(":\n> ");
			}
			
			$input = fgets($this->input);
			
			// Handle EOF (Ctrl+D)
			if ($input === false) {
				return null;
			}
			
			$answer = trim($input);
			return $answer !== '' ? $answer : $default;
		}
		
		/**
		 * Ask for confirmation
		 * @param string $question
		 * @param bool $default
		 * @return bool
		 */
		public function confirm(string $question, bool $default = true): bool {
			$response = $this->ask($question . ' (y/n)', $default ? 'y' : 'n');
			
			// Handle EOF or null response
			if ($response === null) {
				return $default;
			}
			
			return strtolower($response[0] ?? '') === 'y';
		}
		
		/**
		 * Multiple choice question
		 * @param string $question
		 * @param array $choices
		 * @param int|null $default Default choice index (1-based, matching display)
		 * @return string
		 */
		public function choice(string $question, array $choices, ?int $default = null): string {
			// Reindex array to ensure sequential numeric keys starting from 0
			$choices = array_values($choices);
			
			// Validate default is within valid range
			if ($default !== null && ($default < 1 || $default > count($choices))) {
				throw new \InvalidArgumentException(
					"Default choice must be between 1 and " . count($choices)
				);
			}
			
			// Display the main question to the user
			$this->output->writeLn($question);
			
			// Loop through all available choices and display them with numbered options
			foreach ($choices as $key => $choice) {
				// Format each choice with a number (starting from 1) for user selection
				$defaultMarker = ($default === $key + 1) ? ' (default)' : '';
				$this->output->writeLn(sprintf("  [%d] %s%s", $key + 1, $choice, $defaultMarker));
			}
			
			do {
				// Prompt user to enter their choice
				$answer = $this->ask('Enter your choice', $default !== null ? (string)$default : null);
				
				// Handle EOF
				if ($answer === null) {
					return $default !== null ? $choices[$default - 1] : $choices[0];
				}
				
				// Validate input is numeric
				if (!ctype_digit($answer)) {
					$this->output->error("Please enter a number between 1 and " . count($choices));
					continue;
				}
				
				// Convert user input to zero-based array index (subtract 1 since display starts at 1)
				$index = (int)$answer - 1;
				
				// Validate range
				if (!isset($choices[$index])) {
					$this->output->error("Please enter a number between 1 and " . count($choices));
				}
				
				// Continue looping while the selected index doesn't exist in the choices array
			} while (!isset($choices[$index]));
			
			// Return the selected choice text from the choices array
			return $choices[$index];
		}
	}