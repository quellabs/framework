<?php

	namespace Quellabs\Canvas\Tests\Security;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Routing\Contracts\MethodContextInterface;
	use Quellabs\Canvas\Security\SecureUploadAspect;
	use Quellabs\Support\ComposerUtils;
	use Symfony\Component\HttpFoundation\File\UploadedFile;
	use Symfony\Component\HttpFoundation\Request;

	/**
	 * Unit test for SecureUploadAspect's batch storage-failure rollback.
	 *
	 * Validation-failure atomicity (no file is stored when any file fails
	 * validation) is structural - pass 1 runs before any file is moved. This
	 * covers the gap the review identified: a move failing partway through
	 * pass 2 must not leave earlier files in the same batch permanently stored.
	 */
	class SecureUploadAspectTest extends TestCase {

		private string $uploadRelativePath;
		private string $uploadAbsolutePath;

		protected function setUp(): void {
			$this->uploadRelativePath = 'var/secure_upload_test_' . bin2hex(random_bytes(8));
			$this->uploadAbsolutePath = ComposerUtils::getProjectRoot() . DIRECTORY_SEPARATOR . $this->uploadRelativePath;
		}

		protected function tearDown(): void {
			$this->removeDirectory($this->uploadAbsolutePath);
		}

		private function removeDirectory(string $dir): void {
			if (!is_dir($dir)) {
				return;
			}

			foreach (scandir($dir) as $entry) {
				if ($entry === '.' || $entry === '..') {
					continue;
				}

				$path = $dir . '/' . $entry;

				if (is_dir($path)) {
					$this->removeDirectory($path);
				} else {
					unlink($path);
				}
			}

			rmdir($dir);
		}

		/**
		 * Build a valid 1x1 PNG UploadedFile backed by a real temp file.
		 *
		 * Overrides getMimeType() to avoid a hard dependency on symfony/mime
		 * (optional in this package) being installed just to run this test.
		 *
		 * @param string $originalName
		 * @return UploadedFile
		 */
		private function makeImageUpload(string $originalName): UploadedFile {
			$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
			$path = tempnam(sys_get_temp_dir(), 'upl');
			file_put_contents($path, $png);

			return new class($path, $originalName, 'image/png', null, true) extends UploadedFile {
				public function getMimeType(): ?string {
					return 'image/png';
				}
			};
		}

		private function makeContext(Request $request): MethodContextInterface {
			$context = $this->createStub(MethodContextInterface::class);
			$context->method('getRequest')->willReturn($request);
			return $context;
		}

		public function testStorageFailureRollsBackEarlierFilesInTheSameBatch(): void {
			$ok = $this->makeImageUpload('ok.png');
			$broken = $this->makeImageUpload('broken.png');

			$request = Request::create('/upload', 'POST');
			$request->files->set('ok', $ok);
			$request->files->set('broken', $broken);

			// Force the second file's move to fail: pre-create a directory at the
			// exact path it would be moved to (rename() over an existing path fails).
			mkdir($this->uploadAbsolutePath, 0777, true);
			mkdir($this->uploadAbsolutePath . '/broken.png');

			$aspect = new SecureUploadAspect(
				uploadPath: $this->uploadRelativePath,
				randomizeFilenames: false,
				directoryStructure: ''
			);

			$aspect->before($this->makeContext($request));

			$this->assertFalse($request->attributes->get('upload_successful'));

			$results = $request->attributes->get('uploaded_files');
			$this->assertFalse($results['ok'][0]['success']);
			$this->assertFalse($results['broken'][0]['success']);

			// The file that was moved before the second one failed must have been
			// rolled back - not left on disk despite being reported as failed.
			$this->assertFileDoesNotExist($this->uploadAbsolutePath . '/ok.png');
		}
	}
