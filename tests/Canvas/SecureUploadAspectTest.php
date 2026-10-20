<?php

	namespace Quellabs\Canvas\Tests\Security;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Routing\Contracts\MethodContextInterface;
	use Quellabs\Canvas\Security\SecureUploadAspect;
	use Quellabs\Support\ComposerUtils;
	use Symfony\Component\HttpFoundation\File\Exception\FileException;
	use Symfony\Component\HttpFoundation\File\File;
	use Symfony\Component\HttpFoundation\File\UploadedFile;
	use Symfony\Component\HttpFoundation\Request;

	/**
	 * Unit test for SecureUploadAspect's batch storage-failure rollback, pre-flight
	 * destination checks, and the onDuplicateDestination rename/reject policies.
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

		/**
		 * Build a valid 1x1 PNG UploadedFile whose move() always throws, to force a genuine storage-move failure that bypasses pre-flight entirely.
		 * @param string $originalName
		 * @return UploadedFile
		 */
		private function makeFailingImageUpload(string $originalName): UploadedFile {
			$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
			$path = tempnam(sys_get_temp_dir(), 'upl');
			file_put_contents($path, $png);

			return new class($path, $originalName, 'image/png', null, true) extends UploadedFile {
				public function getMimeType(): ?string {
					return 'image/png';
				}

				public function move(string $directory, ?string $name = null): File {
					throw new FileException('Simulated move failure for testing');
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
			$broken = $this->makeFailingImageUpload('broken.png');

			$request = Request::create('/upload', 'POST');
			$request->files->set('ok', $ok);
			$request->files->set('broken', $broken);

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

		public function testDuplicateTargetWithinBatchIsRenamedByDefault(): void {
			$first = $this->makeImageUpload('same.png');
			$second = $this->makeImageUpload('same.png');

			$request = Request::create('/upload', 'POST');
			$request->files->set('a', $first);
			$request->files->set('b', $second);

			$aspect = new SecureUploadAspect(
				uploadPath: $this->uploadRelativePath,
				randomizeFilenames: false,
				directoryStructure: ''
			);

			$aspect->before($this->makeContext($request));

			$this->assertTrue($request->attributes->get('upload_successful'));

			$results = $request->attributes->get('uploaded_files');
			$this->assertTrue($results['a'][0]['success']);
			$this->assertTrue($results['b'][0]['success']);

			// Both files must have actually landed on disk, under different names -
			// neither silently overwrote the other.
			$this->assertNotSame($results['a'][0]['path'], $results['b'][0]['path']);
			$this->assertFileExists($results['a'][0]['path']);
			$this->assertFileExists($results['b'][0]['path']);
		}

		public function testNonCollidingUploadIsStillGivenAUniqueNameByDefault(): void {
			// 'rename' suffixes every deterministic filename unconditionally, not just on collision.
			$upload = $this->makeImageUpload('plain.png');

			$request = Request::create('/upload', 'POST');
			$request->files->set('file', $upload);

			$aspect = new SecureUploadAspect(
				uploadPath: $this->uploadRelativePath,
				randomizeFilenames: false,
				directoryStructure: ''
			);

			$aspect->before($this->makeContext($request));

			$this->assertTrue($request->attributes->get('upload_successful'));

			$results = $request->attributes->get('uploaded_files');
			$this->assertTrue($results['file'][0]['success']);
			$this->assertNotSame('plain.png', $results['file'][0]['filename']);
			$this->assertStringStartsWith('plain-', $results['file'][0]['filename']);
			$this->assertSame('plain.png', $results['file'][0]['original_name']);
		}

		public function testDuplicateTargetWithinBatchIsRejectedWhenPolicyIsReject(): void {
			$first = $this->makeImageUpload('same.png');
			$second = $this->makeImageUpload('same.png');

			$request = Request::create('/upload', 'POST');
			$request->files->set('a', $first);
			$request->files->set('b', $second);

			$aspect = new SecureUploadAspect(
				uploadPath: $this->uploadRelativePath,
				randomizeFilenames: false,
				directoryStructure: '',
				onDuplicateDestination: 'reject'
			);

			$aspect->before($this->makeContext($request));

			$this->assertFalse($request->attributes->get('upload_successful'));

			$results = $request->attributes->get('uploaded_files');
			$this->assertFalse($results['a'][0]['success']);
			$this->assertFalse($results['b'][0]['success']);

			// Neither file may land on disk - not one overwriting the other.
			$this->assertFileDoesNotExist($this->uploadAbsolutePath . '/same.png');
		}

		public function testExistingDestinationFileIsRenamedByDefault(): void {
			$upload = $this->makeImageUpload('existing.png');

			$request = Request::create('/upload', 'POST');
			$request->files->set('file', $upload);

			mkdir($this->uploadAbsolutePath, 0777, true);
			file_put_contents($this->uploadAbsolutePath . '/existing.png', 'already here');

			$aspect = new SecureUploadAspect(
				uploadPath: $this->uploadRelativePath,
				randomizeFilenames: false,
				directoryStructure: ''
			);

			$aspect->before($this->makeContext($request));

			$this->assertTrue($request->attributes->get('upload_successful'));

			$results = $request->attributes->get('uploaded_files');
			$this->assertTrue($results['file'][0]['success']);

			// The pre-existing file must survive untouched, and the upload must have
			// landed under a different name rather than overwriting it.
			$this->assertSame('already here', file_get_contents($this->uploadAbsolutePath . '/existing.png'));
			$this->assertNotSame($this->uploadAbsolutePath . '/existing.png', $results['file'][0]['path']);
			$this->assertFileExists($results['file'][0]['path']);
		}

		public function testExistingDestinationFileIsRejectedWhenPolicyIsReject(): void {
			$upload = $this->makeImageUpload('existing.png');

			$request = Request::create('/upload', 'POST');
			$request->files->set('file', $upload);

			mkdir($this->uploadAbsolutePath, 0777, true);
			file_put_contents($this->uploadAbsolutePath . '/existing.png', 'already here');

			$aspect = new SecureUploadAspect(
				uploadPath: $this->uploadRelativePath,
				randomizeFilenames: false,
				directoryStructure: '',
				onDuplicateDestination: 'reject'
			);

			$aspect->before($this->makeContext($request));

			$this->assertFalse($request->attributes->get('upload_successful'));

			$results = $request->attributes->get('uploaded_files');
			$this->assertFalse($results['file'][0]['success']);

			// The pre-existing file must survive untouched, not be overwritten.
			$this->assertSame('already here', file_get_contents($this->uploadAbsolutePath . '/existing.png'));
		}

		public function testInvalidOnDuplicateDestinationPolicyThrows(): void {
			$this->expectException(\InvalidArgumentException::class);
			new SecureUploadAspect(onDuplicateDestination: 'overwrite');
		}

		public function testRollbackPreservesThePathWhenDeletionItselfFails(): void {
			$aspect = new SecureUploadAspect();

			// White-box: a nonexistent path makes @unlink() fail deterministically on every platform, avoiding flaky permission tricks.
			$movedPaths = [
				['field' => 'file', 'index' => 0, 'path' => $this->uploadAbsolutePath . '/never_existed.png'],
			];

			$processedFiles = [
				'file' => [
					[
						'success'       => true,
						'errors'        => [],
						'original_name' => 'never_existed.png',
						'filename'      => 'never_existed.png',
						'path'          => $this->uploadAbsolutePath . '/never_existed.png',
						'relative_path' => $this->uploadRelativePath . '/never_existed.png',
						'size'          => 68,
						'mime_type'     => 'image/png',
						'extension'     => 'png',
						'uploaded_at'   => '2024-01-01 00:00:00',
					],
				],
			];

			// invoke() doesn't honor by-reference parameters; invokeArgs() with an explicit reference does.
			$method = new \ReflectionMethod($aspect, 'rollbackMovedFiles');
			$method->setAccessible(true);
			$method->invokeArgs($aspect, [$movedPaths, &$processedFiles]);

			$result = $processedFiles['file'][0];
			$this->assertFalse($result['success']);
			$this->assertArrayHasKey('path', $result, "a failed rollback deletion must preserve 'path' for cleanup");
			$this->assertSame($this->uploadAbsolutePath . '/never_existed.png', $result['path']);
			$this->assertStringContainsString('could not be removed', $result['errors'][0]);
		}
	}
