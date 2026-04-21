<?php
	
	namespace Quellabs\Canvas\Loom\Validation\Rules;
	
	use Quellabs\Canvas\Loom\Validation\RuleInterface;
	
	/**
	 * Abstract base for Loom validation rules.
	 * Handles the optional custom message pattern shared by all rules.
	 */
	abstract class RuleBase implements RuleInterface {
		
		/**
		 * Optional custom error message supplied by the caller.
		 * When null, getError() falls back to the rule's default message.
		 * @var string|null
		 */
		protected ?string $message;
		
		/**
		 * @param string|null $message Optional custom error message
		 */
		public function __construct(?string $message = null) {
			$this->message = $message;
		}
		
		/**
		 * Replaces {{ variable }} placeholders in an error string.
		 * @param string $template Error string containing {{ name }} placeholders
		 * @param array  $vars     Associative array of variable names to values
		 * @return string
		 */
		protected function interpolate(string $template, array $vars): string {
			return preg_replace_callback('/\{\{\s*([a-zA-Z_]\w*)\s*\}\}/', function ($m) use ($vars) {
				return array_key_exists($m[1], $vars) ? $vars[$m[1]] : $m[0];
			}, $template);
		}

		/**
		 * @inheritDoc
		 * Defaults to false — override in rules that have a WakaForm JS equivalent.
		 */
		public function wakaFormSupported(): bool {
			return false;
		}
	}