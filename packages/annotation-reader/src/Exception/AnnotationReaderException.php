<?php
	
	namespace Quellabs\AnnotationReader\Exception;
	
	/**
	 * Class AnnotationReaderException
	 * @package Quellabs\AnnotationsReader
	 */
	class AnnotationReaderException extends \Exception {
		
		/**
		 * Redefine the exception so message isn't optional
		 * @param string $message
		 * @param int $code
		 * @param \Throwable|null $previous
		 */
		public function __construct(string $message, int $code = 0, ?\Throwable $previous = null) {
			parent::__construct($message, $code, $previous);
		}
	}