<?php
	
	namespace Quellabs\CanvasSchedulerRabbitMQ\Tests;
	
	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Scheduler\RabbitMQ\JobEnvelope;
	use Quellabs\Contracts\Scheduler\JobInterface;
	use Quellabs\Contracts\Scheduler\QueueableInterface;
	
	/**
	 * Stub job used for class-name-based checks in fromJson().
	 * Must implement JobInterface so is_a() passes.
	 */
	class StubJob implements JobInterface, QueueableInterface {
		public function __construct(public readonly string $title = '') {}
		public function handle(): void {}
		public function getPayload(): array { return ['title' => $this->title]; }
		public function getMaxRetries(): int { return 3; }
		public function getTimeout(): int { return 60; }
	}
	
	class JobEnvelopeTest extends TestCase {
		
		// -------------------------------------------------------------------------
		// fromJob
		// -------------------------------------------------------------------------
		
		public function testFromJobCapturesClassAndPayload(): void {
			$job = new StubJob('hello');
			$envelope = JobEnvelope::fromJob($job);
			
			$this->assertSame(StubJob::class, $envelope->class);
			$this->assertSame(['title' => 'hello'], $envelope->payload);
		}
		
		public function testFromJobSetsDefaultAttempts(): void {
			$envelope = JobEnvelope::fromJob(new StubJob());
			$this->assertSame(0, $envelope->attempts);
		}
		
		public function testFromJobCopiesMaxRetriesAndTimeout(): void {
			$envelope = JobEnvelope::fromJob(new StubJob());
			$this->assertSame(3, $envelope->maxRetries);
			$this->assertSame(60, $envelope->timeout);
		}
		
		public function testFromJobGeneratesNonEmptyId(): void {
			$envelope = JobEnvelope::fromJob(new StubJob());
			$this->assertNotEmpty($envelope->id);
		}
		
		public function testFromJobSetsQueuedAtToCurrentTime(): void {
			$before = time();
			$envelope = JobEnvelope::fromJob(new StubJob());
			$after = time();
			
			$this->assertGreaterThanOrEqual($before, $envelope->queuedAt);
			$this->assertLessThanOrEqual($after, $envelope->queuedAt);
		}
		
		// -------------------------------------------------------------------------
		// toJson / fromJson round-trip
		// -------------------------------------------------------------------------
		
		public function testJsonRoundTrip(): void {
			$original = new JobEnvelope(
				class: StubJob::class,
				payload: ['title' => 'test'],
				attempts: 1,
				maxRetries: 5,
				timeout: 30,
				id: 'abc-123',
				queuedAt: 1700000000
			);
			
			$restored = JobEnvelope::fromJson($original->toJson());
			
			$this->assertSame($original->id, $restored->id);
			$this->assertSame($original->class, $restored->class);
			$this->assertSame($original->payload, $restored->payload);
			$this->assertSame($original->attempts, $restored->attempts);
			$this->assertSame($original->maxRetries, $restored->maxRetries);
			$this->assertSame($original->timeout, $restored->timeout);
			$this->assertSame($original->queuedAt, $restored->queuedAt);
		}
		
		public function testToJsonProducesValidJson(): void {
			$envelope = JobEnvelope::fromJob(new StubJob('x'));
			$decoded = json_decode($envelope->toJson(), true);
			
			$this->assertIsArray($decoded);
			$this->assertArrayHasKey('id', $decoded);
			$this->assertArrayHasKey('class', $decoded);
			$this->assertArrayHasKey('payload', $decoded);
			$this->assertArrayHasKey('attempts', $decoded);
			$this->assertArrayHasKey('max_retries', $decoded);
			$this->assertArrayHasKey('timeout', $decoded);
			$this->assertArrayHasKey('queued_at', $decoded);
		}
		
		// -------------------------------------------------------------------------
		// fromJson — validation failures
		// -------------------------------------------------------------------------
		
		public function testFromJsonThrowsOnInvalidJson(): void {
			$this->expectException(\InvalidArgumentException::class);
			$this->expectExceptionMessageMatches('/failed to decode/');
			JobEnvelope::fromJson('not json at all');
		}
		
		public function testFromJsonThrowsOnEmptyString(): void {
			$this->expectException(\InvalidArgumentException::class);
			JobEnvelope::fromJson('');
		}
		
		/**
		 * @dataProvider missingFieldProvider
		 */
		public function testFromJsonThrowsOnMissingField(string $field): void {
			$data = $this->validEnvelopeArray();
			unset($data[$field]);
			
			$this->expectException(\InvalidArgumentException::class);
			$this->expectExceptionMessageMatches("/missing field '{$field}'/");
			JobEnvelope::fromJson(json_encode($data));
		}
		
		public static function missingFieldProvider(): array {
			return [
				['id'],
				['class'],
				['payload'],
				['attempts'],
				['max_retries'],
				['timeout'],
				['queued_at'],
			];
		}
		
		public function testFromJsonThrowsOnNonExistentClass(): void {
			$data = $this->validEnvelopeArray();
			$data['class'] = 'Quellabs\\NoSuchClass\\Nowhere';
			
			$this->expectException(\InvalidArgumentException::class);
			$this->expectExceptionMessageMatches('/does not exist/');
			JobEnvelope::fromJson(json_encode($data));
		}
		
		public function testFromJsonThrowsWhenClassDoesNotImplementJobInterface(): void {
			// Use a real class that exists but doesn't implement JobInterface
			$data = $this->validEnvelopeArray();
			$data['class'] = \stdClass::class;
			
			$this->expectException(\InvalidArgumentException::class);
			$this->expectExceptionMessageMatches('/does not implement JobInterface/');
			JobEnvelope::fromJson(json_encode($data));
		}
		
		// -------------------------------------------------------------------------
		// withIncrementedAttempts
		// -------------------------------------------------------------------------
		
		public function testWithIncrementedAttemptsReturnsNewInstance(): void {
			$original = JobEnvelope::fromJob(new StubJob());
			$incremented = $original->withIncrementedAttempts();
			
			$this->assertNotSame($original, $incremented);
		}
		
		public function testWithIncrementedAttemptsDoesNotMutateOriginal(): void {
			$original = JobEnvelope::fromJob(new StubJob());
			$original->withIncrementedAttempts();
			
			$this->assertSame(0, $original->attempts);
		}
		
		public function testWithIncrementedAttemptsIncrementsCounter(): void {
			$envelope = new JobEnvelope(StubJob::class, [], 2, 5, 60, 'id', 0);
			$this->assertSame(3, $envelope->withIncrementedAttempts()->attempts);
		}
		
		public function testWithIncrementedAttemptsPreservesAllOtherFields(): void {
			$original = new JobEnvelope(StubJob::class, ['k' => 'v'], 1, 5, 30, 'fixed-id', 1700000000);
			$incremented = $original->withIncrementedAttempts();
			
			$this->assertSame($original->id, $incremented->id);
			$this->assertSame($original->class, $incremented->class);
			$this->assertSame($original->payload, $incremented->payload);
			$this->assertSame($original->maxRetries, $incremented->maxRetries);
			$this->assertSame($original->timeout, $incremented->timeout);
			$this->assertSame($original->queuedAt, $incremented->queuedAt);
		}
		
		// -------------------------------------------------------------------------
		// hasExceededMaxRetries
		// -------------------------------------------------------------------------
		
		public function testHasNotExceededMaxRetriesWhenBelowLimit(): void {
			$envelope = new JobEnvelope(StubJob::class, [], 2, 3, 60, 'id', 0);
			$this->assertFalse($envelope->hasExceededMaxRetries());
		}
		
		public function testHasExceededMaxRetriesAtExactLimit(): void {
			// attempts >= maxRetries → exceeded
			$envelope = new JobEnvelope(StubJob::class, [], 3, 3, 60, 'id', 0);
			$this->assertTrue($envelope->hasExceededMaxRetries());
		}
		
		public function testHasExceededMaxRetriesAboveLimit(): void {
			$envelope = new JobEnvelope(StubJob::class, [], 5, 3, 60, 'id', 0);
			$this->assertTrue($envelope->hasExceededMaxRetries());
		}
		
		public function testHasNotExceededMaxRetriesAtZeroAttempts(): void {
			$envelope = new JobEnvelope(StubJob::class, [], 0, 3, 60, 'id', 0);
			$this->assertFalse($envelope->hasExceededMaxRetries());
		}
		
		// -------------------------------------------------------------------------
		// ID uniqueness
		// -------------------------------------------------------------------------
		
		public function testEachEnvelopeGetsAUniqueId(): void {
			$a = JobEnvelope::fromJob(new StubJob());
			$b = JobEnvelope::fromJob(new StubJob());
			
			$this->assertNotSame($a->id, $b->id);
		}
		
		// -------------------------------------------------------------------------
		// Helpers
		// -------------------------------------------------------------------------
		
		private function validEnvelopeArray(): array {
			return [
				'id'          => 'test-id-123',
				'class'       => StubJob::class,
				'payload'     => ['title' => 'test'],
				'attempts'    => 0,
				'max_retries' => 3,
				'timeout'     => 60,
				'queued_at'   => 1700000000,
			];
		}
	}