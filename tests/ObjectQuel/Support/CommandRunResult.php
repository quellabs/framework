<?php

	namespace Quellabs\ObjectQuel\Tests\Support;

	/**
	 * Exit code and captured output of a Sculpt command run in a test.
	 */
	final class CommandRunResult {

		private int $exitCode;
		private string $output;

		/**
		 * CommandRunResult constructor
		 * @param int $exitCode Command exit code
		 * @param string $output Captured console output
		 */
		public function __construct(int $exitCode, string $output) {
			$this->exitCode = $exitCode;
			$this->output = $output;
		}

		/**
		 * Returns the command exit code
		 * @return int
		 */
		public function getExitCode(): int {
			return $this->exitCode;
		}

		/**
		 * Returns the captured console output
		 * @return string
		 */
		public function getOutput(): string {
			return $this->output;
		}
	}
