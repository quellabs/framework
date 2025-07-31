<?php
	
	namespace Quellabs\Support\Debugger\Renderers;
	
	class RendererFactory {
		
		static public function create(string $type): RendererInterface {
			return match ($type) {
				'cli' => new CliRenderer(),
				default => new HtmlRenderer(),
			};
		}
	}