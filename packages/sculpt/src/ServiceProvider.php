<?php
	
	namespace Quellabs\Sculpt;
	
	use Quellabs\Discover\Provider\AbstractProvider;
	use Quellabs\Sculpt\Contracts\SculptProviderInterface;

	/**
	 * Base implementation of the ServiceProviderInterface that provides
	 * common functionality for service providers in the Sculpt framework.
	 *
	 * A package whose "sculpt" category provider needs to extend its own "di"
	 * category provider instead (to inherit that provider's configuration and
	 * methods) implements SculptProviderInterface and uses RegistersCommandsTrait
	 * directly rather than extending this class — see SculptProviderInterface.
	 */
	abstract class ServiceProvider extends AbstractProvider implements SculptProviderInterface {

		use RegistersCommandsTrait;
	}