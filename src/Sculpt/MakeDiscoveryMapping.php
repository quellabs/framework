<?php
	
	namespace App\Sculpt;
	
	use App\Monorepo\PackageExtraExtractor;
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Contracts\CommandBase;
	
	class MakeDiscoveryMapping extends CommandBase {
		
		public function getSignature(): string {
			return "make:discovery-mapping";
		}
		
		public function getDescription(): string {
			return "Manually created a discovery mapping from the monorepo";
		}
		
		public function execute(ConfigurationManager $config): int {
			try {
				$x = new PackageExtraExtractor(dirname(__FILE__) . '/../../packages');
				$map = $x->getMap();
				$x->writeExtraMapFile(dirname(__FILE__) . '/../../config/discovery-mapping.php', $map);
				
				$this->output->success("Mapping file created");
				
				return 0;
			} catch (\Exception $e) {
				$this->output->error($e->getMessage());
				return 1;
			}
		}
	}