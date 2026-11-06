<?php

	namespace Quellabs\CanvasAuthorization\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\CanvasAuthorization\SharedAuthStubsInstaller;

	class SharedAuthStubsInstallerTest extends TestCase {

		public function testGetStubsMapsEveryEntryToAnExistingStubFile(): void {
			$stubs = SharedAuthStubsInstaller::getStubs('smarty', 'tpl');
			$root = SharedAuthStubsInstaller::getStubRoot();

			$this->assertNotEmpty($stubs);

			foreach ($stubs as $stubRelative => $targetRelative) {
				$this->assertFileExists($root . $stubRelative, "Missing shared stub: {$stubRelative}");
				$this->assertMatchesRegularExpression('#^(src|templates)/#', $targetRelative);
			}
		}

		public function testGetStubsSubstitutesTheGivenTemplateEngineAndExtension(): void {
			$stubs = SharedAuthStubsInstaller::getStubs('blade', 'blade.php');

			$this->assertArrayHasKey('templates/blade/login.blade.php', $stubs);
			$this->assertSame('templates/login.blade.php', $stubs['templates/blade/login.blade.php']);
		}
	}
