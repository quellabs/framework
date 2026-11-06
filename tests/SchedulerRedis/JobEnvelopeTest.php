<?php

	namespace Quellabs\CanvasSchedulerRedis\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Scheduler\Redis\JobEnvelope;
	use Quellabs\Contracts\Scheduler\JobInterface;
	use Quellabs\Contracts\Scheduler\QueueableInterface;

	/**
	 * Stub job used for class-name-based checks in fromJson().
	 * Must implement QueueableInterface so is_a() passes.
	 */
	class StubJob implements JobInterface, QueueableInterface {
		public function __construct(public readonly string $title = '') {}
		public function handle(): void {}
		public function getPayload(): array { return ['title' => $this->title]; }
		public function getMaxRetries(): int { return 3; }
		public function getTimeout(): int { return 60; }
	}

	/**
	 * Implements only the broader JobInterface (e.g. a Cron task would) - not
	 * QueueableInterface - to prove fromJson() rejects it rather than accepting
	 * any JobInterface implementor, which would let a queue message remotely
	 * instantiate a class never designed to be reconstructed this way.
	 */
	class NonQueueableJob implements JobInterface {
		public function handle(): void {}
		public function getMaxRetries(): int { return 3; }
		public function getTimeout(): int { return 60; }
	}

	class JobEnvelopeTest extends TestCase {

		// -------------------------------------------------------------------------
		// fromJob / toJson / fromJson round trip
		// -------------------------------------------------------------------------

		public function testFromJobCapturesClassAndPayload(): void {
			$job = new StubJob('hello');
			$envelope = JobEnvelope::fromJob($job);

			$this->assertSame(StubJob::class, $envelope->class);
			$this->assertSame(['title' => 'hello'], $envelope->payload);
			$this->assertSame(3, $envelope->maxRetries);
			$this->assertSame(60, $envelope->timeout);
		}

		public function testToJsonAndFromJsonRoundTrip(): void {
			$original = JobEnvelope::fromJob(new StubJob('hello'));
			$restored = JobEnvelope::fromJson($original->toJson());

			$this->assertSame($original->class, $restored->class);
			$this->assertSame($original->payload, $restored->payload);
			$this->assertSame($original->id, $restored->id);
		}

		// -------------------------------------------------------------------------
		// fromJson validation
		// -------------------------------------------------------------------------

		private function validEnvelopeArray(): array {
			return [
				'id'          => 'test-id',
				'class'       => StubJob::class,
				'payload'     => ['title' => 'x'],
				'attempts'    => 0,
				'max_retries' => 3,
				'timeout'     => 60,
				'queued_at'   => 0,
			];
		}

		public function testFromJsonThrowsWhenClassDoesNotExist(): void {
			$data = $this->validEnvelopeArray();
			$data['class'] = 'Totally\\Nonexistent\\ClassName';

			$this->expectException(\InvalidArgumentException::class);
			$this->expectExceptionMessageMatches('/does not exist/');
			JobEnvelope::fromJson(json_encode($data));
		}

		public function testFromJsonThrowsWhenPayloadIsNotAnArray(): void {
			$data = $this->validEnvelopeArray();
			$data['payload'] = 'not-an-array';

			$this->expectException(\InvalidArgumentException::class);
			$this->expectExceptionMessageMatches("/'payload' must be an array/");
			JobEnvelope::fromJson(json_encode($data));
		}

		public function testFromJsonThrowsWhenAttemptsIsNotAnInteger(): void {
			$data = $this->validEnvelopeArray();
			$data['attempts'] = 'not-a-number';

			$this->expectException(\InvalidArgumentException::class);
			$this->expectExceptionMessageMatches("/'attempts' must be an integer/");
			JobEnvelope::fromJson(json_encode($data));
		}

		public function testFromJsonThrowsWhenIdIsEmpty(): void {
			$data = $this->validEnvelopeArray();
			$data['id'] = '';

			$this->expectException(\InvalidArgumentException::class);
			$this->expectExceptionMessageMatches("/'id' must be a non-empty string/");
			JobEnvelope::fromJson(json_encode($data));
		}

		public function testFromJsonThrowsWhenClassDoesNotImplementQueueableInterface(): void {
			// Use a real class that exists but doesn't implement QueueableInterface
			$data = $this->validEnvelopeArray();
			$data['class'] = \stdClass::class;

			$this->expectException(\InvalidArgumentException::class);
			$this->expectExceptionMessageMatches('/does not implement QueueableInterface/');
			JobEnvelope::fromJson(json_encode($data));
		}

		public function testFromJsonRejectsAJobInterfaceImplementorThatIsNotQueueable(): void {
			// A class implementing only JobInterface (e.g. a Cron task) must still be
			// rejected - accepting it would let a queue message remotely instantiate
			// a class never designed to be reconstructed from queue-supplied arguments.
			$data = $this->validEnvelopeArray();
			$data['class'] = NonQueueableJob::class;

			$this->expectException(\InvalidArgumentException::class);
			$this->expectExceptionMessageMatches('/does not implement QueueableInterface/');
			JobEnvelope::fromJson(json_encode($data));
		}

		// -------------------------------------------------------------------------
		// withIncrementedAttempts / hasExceededMaxRetries
		// -------------------------------------------------------------------------

		public function testWithIncrementedAttemptsIncreasesAttemptsOnly(): void {
			$envelope = new JobEnvelope(StubJob::class, [], attempts: 1, maxRetries: 3);
			$incremented = $envelope->withIncrementedAttempts();

			$this->assertSame(2, $incremented->attempts);
			$this->assertSame($envelope->id, $incremented->id);
		}

		public function testHasExceededMaxRetries(): void {
			$envelope = new JobEnvelope(StubJob::class, [], attempts: 3, maxRetries: 3);
			$this->assertTrue($envelope->hasExceededMaxRetries());

			$envelope = new JobEnvelope(StubJob::class, [], attempts: 2, maxRetries: 3);
			$this->assertFalse($envelope->hasExceededMaxRetries());
		}
	}
