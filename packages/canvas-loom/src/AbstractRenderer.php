<?php
	
	declare(strict_types=1);
	
	namespace Quellabs\Canvas\Loom;
	
	/**
	 * Base class for all Loom node renderers.
	 * Provides access to the Loom engine, HTML/JS escaping helpers, and
	 * implements RendererInterface so the engine can dispatch to subclasses.
	 *
	 * Extend this class when creating custom or theme renderers.
	 */
	abstract class AbstractRenderer implements RendererInterface {
		use EscapesTrait;
	}