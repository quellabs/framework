<?php

	namespace Quellabs\Canvas\Tests\AOP;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\AOP\AspectDispatcher;
	use Quellabs\Canvas\AOP\Contracts\AfterAspectInterface;
	use Quellabs\Canvas\AOP\Contracts\BeforeAspectInterface;
	use Quellabs\Canvas\Routing\Contracts\MethodContextInterface;
	use Quellabs\DependencyInjection\Container;
	use Symfony\Component\HttpFoundation\Response;

	/**
	 * Covers the gap where a before-aspect short circuit used to skip every
	 * after aspect entirely: after aspects must still run against the
	 * short-circuit response, the same as they would against a controller's.
	 */
	class AspectDispatcherShortCircuitTest extends TestCase {

		public function testAfterAspectsRunAgainstABeforeAspectShortCircuitResponse(): void {
			$shortCircuit = new Response('denied', 403);

			$before = new class ($shortCircuit) implements BeforeAspectInterface {
				public function __construct(private readonly Response $response) {
				}

				public function before(MethodContextInterface $context): ?Response {
					return $this->response;
				}
			};

			$after = new class implements AfterAspectInterface {
				public bool $called = false;

				public function after(MethodContextInterface $context, Response $response): void {
					$this->called = true;
					$response->headers->set('X-After-Ran', '1');
				}
			};

			$context = $this->createStub(MethodContextInterface::class);
			$dispatcher = new AspectDispatcher($this->createStub(Container::class));

			$response = $dispatcher->dispatch($context, [$before, $after]);

			$this->assertTrue($after->called);
			$this->assertSame('1', $response->headers->get('X-After-Ran'));
			$this->assertSame(403, $response->getStatusCode());
			$this->assertSame('denied', $response->getContent());
		}
	}
