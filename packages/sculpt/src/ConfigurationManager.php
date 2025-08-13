<?php
	
	/*
	 * ╔═══════════════════════════════════════════════════════════════════════════════════════╗
	 * ║                                                                                       ║
	 * ║  ███████╗ ██████╗██╗   ██╗██╗     ██████╗ ████████╗                                   ║
	 * ║  ██╔════╝██╔════╝██║   ██║██║     ██╔══██╗╚══██╔══╝                                   ║
	 * ║  ███████╗██║     ██║   ██║██║     ██████╔╝   ██║                                      ║
	 * ║  ╚════██║██║     ██║   ██║██║     ██╔═══╝    ██║                                      ║
	 * ║  ███████║╚██████╗╚██████╔╝███████╗██║        ██║                                      ║
	 * ║  ╚══════╝ ╚═════╝ ╚═════╝ ╚══════╝╚═╝        ╚═╝                                      ║
	 * ║                                                                                       ║
	 * ║  Sculpt - Modern CLI framework for the Quellabs ecosystem                             ║
	 * ║                                                                                       ║
	 * ║  Provides an elegant command-line interface for rapid development, code generation,   ║
	 * ║  and project management with seamless ObjectQuel ORM integration.                     ║
	 * ║                                                                                       ║
	 * ╚═══════════════════════════════════════════════════════════════════════════════════════╝
	 */
	
	namespace Quellabs\Sculpt;
	
	/**
	 * Configuration manager for handling command-line parameters
	 */
	class ConfigurationManager {
		/**
		 * Raw array of command-line arguments
		 * @var array
		 */
		protected array $rawParameters = [];
		
		/**
		 * Parsed named parameters (--name=value or --name value)
		 * @var array
		 */
		protected array $namedParameters = [];
		
		/**
		 * Boolean flags (--flag or -f)
		 * @var array
		 */
		protected array $flags = [];
		
		/**
		 * Positional arguments that don't have a name prefix
		 * @var array
		 */
		protected array $positionalParameters = [];
		
		/**
		 * Constructor
		 * @param array $parameters Array of command-line parameters from array_slice($args, 2)
		 */
		public function __construct(array $parameters = []) {
			$this->rawParameters = $parameters;
			$this->parseParameters();
		}
		
		/**
		 * Get all parsed parameters
		 * @return array Associative array of all parameters
		 */
		public function all(): array {
			return [
				'named'      => $this->namedParameters,
				'flags'      => $this->flags,
				'positional' => $this->positionalParameters
			];
		}
		
		/**
		 * Get a named parameter value
		 * @param string $name Parameter name
		 * @param mixed $default Default value if parameter is not set
		 * @return mixed Parameter value or default
		 */
		public function get(string $name, mixed $default = null): mixed {
			return $this->namedParameters[$name] ?? $default;
		}
		
		/**
		 * Check if a named parameter exists
		 * @param string $name Parameter name
		 * @return bool True if parameter exists
		 */
		public function has(string $name): bool {
			return isset($this->namedParameters[$name]);
		}
		
		/**
		 * Check if a flag is set
		 * @param string $name Flag name (without -- or -)
		 * @return bool True if flag is set
		 */
		public function hasFlag(string $name): bool {
			return isset($this->flags[$name]);
		}
		
		/**
		 * Get a positional parameter by index
		 * @param int $index Index of the positional parameter (0-based)
		 * @param mixed $default Default value if parameter doesn't exist
		 * @return mixed Parameter value or default
		 */
		public function getPositional(int $index, mixed $default = null): mixed {
			return $this->positionalParameters[$index] ?? $default;
		}
		
		/**
		 * Get all positional parameters
		 *
		 * @return array
		 */
		public function getPositionalParameters(): array {
			return $this->positionalParameters;
		}
		
		/**
		 * Get all named parameters
		 *
		 * @return array
		 */
		public function getNamedParameters(): array {
			return $this->namedParameters;
		}
		
		/**
		 * Get all flags
		 * @return array
		 */
		public function getFlags(): array {
			return $this->flags;
		}
		
		/**
		 * Require that certain named parameters are present
		 * @param array $requiredParams List of required parameter names
		 * @return bool True if all required parameters are present
		 * @throws \InvalidArgumentException If any required parameter is missing
		 */
		public function requireParameters(array $requiredParams): bool {
			$missing = [];
			
			foreach ($requiredParams as $param) {
				if (!$this->has($param)) {
					$missing[] = $param;
				}
			}
			
			if (!empty($missing)) {
				throw new \InvalidArgumentException(
					'Missing required parameters: ' . implode(', ', $missing)
				);
			}
			
			return true;
		}
		
		/**
		 * Get the value of a parameter and validate that it matches a given pattern
		 * @param string $name Parameter name
		 * @param string $pattern Regular expression pattern
		 * @param mixed $default Default value if parameter doesn't exist
		 * @return mixed Parameter value or default
		 * @throws \InvalidArgumentException If parameter doesn't match pattern
		 */
		public function getValidated(string $name, string $pattern, mixed $default = null): mixed {
			$value = $this->get($name, $default);
			
			if ($value !== $default && !preg_match($pattern, $value)) {
				throw new \InvalidArgumentException(
					"Parameter '$name' with value '$value' doesn't match required pattern"
				);
			}
			
			return $value;
		}
		
		/**
		 * Check if parameter matches one of the allowed values
		 * @param string $name Parameter name
		 * @param array $allowedValues Array of allowed values
		 * @param mixed $default Default value if parameter doesn't exist
		 * @return mixed Parameter value or default
		 * @throws \InvalidArgumentException If parameter isn't in allowed values
		 */
		public function getEnum(string $name, array $allowedValues, mixed $default = null): mixed {
			$value = $this->get($name, $default);
			
			if ($value !== $default && !in_array($value, $allowedValues)) {
				throw new \InvalidArgumentException(
					"Parameter '$name' must be one of: " . implode(', ', $allowedValues)
				);
			}
			
			return $value;
		}
		
		/**
		 * Get raw parameters array
		 * @return array
		 */
		public function getRawParameters(): array {
			return $this->rawParameters;
		}
		
		/**
		 * Parse and categorize the raw command-line parameters into structured collections:
		 * - Named parameters (--name=value or --name value)
		 * - Boolean flags (--flag or -f)
		 * - Positional parameters (arguments without prefixes)
		 * @return void
		 */
		protected function parseParameters(): void {
			// If there are no parameters, we can return early
			if (empty($this->rawParameters)) {
				return;
			}
			
			$i = 0;
			
			while ($i < count($this->rawParameters)) {
				$param = $this->rawParameters[$i];
				
				// CASE 1: Parameter has the format "--name=value"
				if ($this->isNamedParameterWithValue($param)) {
					$this->parseNamedParameterWithValue($param);
					++$i;
					continue;
				}
				
				// CASE 2: Parameter starts with "--" (either a flag or named parameter with separate value)
				if ($this->isLongOption($param)) {
					$name = $this->getLongOptionName($param);
					
					// Check if the next item is a value
					if ($this->hasValueAfter($i)) {
						$this->namedParameters[$name] = $this->rawParameters[$i + 1];
						$i += 2; // Skip both the parameter name and its value
						continue;
					}
					
					// It's a boolean flag (--flag)
					$this->flags[$name] = true;
					++$i;
					continue;
				}
				
				// CASE 3: Parameter starts with a single dash (short flags like -f or -abc)
				if ($this->isShortOption($param)) {
					$this->parseShortOptions($param);
					++$i;
					continue;
				}
				
				// CASE 4: Positional parameter (no dashes)
				$this->positionalParameters[] = $param;
				++$i;
			}
		}
		
		/**
		 * Check if parameter is a named parameter with embedded value (--name=value)
		 * @param string $param
		 * @return bool
		 */
		private function isNamedParameterWithValue(string $param): bool {
			return preg_match('/^--([^=]+)=(.+)$/', $param) === 1;
		}
		
		/**
		 * Parse a named parameter with value (--name=value)
		 * @param string $param
		 * @return void
		 */
		private function parseNamedParameterWithValue(string $param): void {
			preg_match('/^--([^=]+)=(.+)$/', $param, $matches);
			$this->namedParameters[$matches[1]] = $matches[2];
		}
		
		/**
		 * Check if parameter is a long option (starts with --)
		 * @param string $param
		 * @return bool
		 */
		private function isLongOption(string $param): bool {
			return str_starts_with($param, '--');
		}
		
		/**
		 * Extract the name from a long option
		 * @param string $param
		 * @return string
		 */
		private function getLongOptionName(string $param): string {
			return substr($param, 2);
		}
		
		/**
		 * Check if parameter is a short option (starts with single -)
		 * @param string $param
		 * @return bool
		 */
		private function isShortOption(string $param): bool {
			return str_starts_with($param, '-') && strlen($param) > 1;
		}
		
		/**
		 * Parse short options (-a, -b, -abc, etc.)
		 * @param string $param
		 * @return void
		 */
		private function parseShortOptions(string $param): void {
			$shortFlags = substr($param, 1);
			
			// Each character is a separate flag
			for ($j = 0; $j < strlen($shortFlags); $j++) {
				$this->flags[$shortFlags[$j]] = true;
			}
		}
		
		/**
		 * Check if the next parameter exists and is a value (doesn't start with -)
		 * @param int $currentIndex
		 * @return bool
		 */
		private function hasValueAfter(int $currentIndex): bool {
			return isset($this->rawParameters[$currentIndex + 1]) &&
				!str_starts_with($this->rawParameters[$currentIndex + 1], '-');
		}
	}