<?php

declare(strict_types=1);

	namespace Quellabs\CanvasEloquent\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\CanvasEloquent\Annotations\ResolveModel;

	/**
	 * Unit tests for the ResolveModel annotation value object.
	 *
	 * ResolveModel targets a controller parameter by name (mirroring
	 * canvas-objectquel's @ResolveEntity convention) and requires the route
	 * parameter that supplies that parameter's value.
	 */
	class ResolveModelAnnotationTest extends TestCase {

		public function testThrowsWhenParameterMissing(): void {
			$this->expectException(\ArgumentCountError::class);
			new ResolveModel();
		}

		public function testThrowsWhenParameterIsNotString(): void {
			$this->expectException(\TypeError::class);
			new ResolveModel(parameter: 42);
		}

		public function testThrowsWhenParameterIsEmptyString(): void {
			$this->expectException(\InvalidArgumentException::class);
			new ResolveModel(parameter: '', routeParam: 'id');
		}

		public function testThrowsWhenRouteParamIsNotString(): void {
			$this->expectException(\TypeError::class);
			new ResolveModel(parameter: 'post', routeParam: 42);
		}

		public function testThrowsWhenRouteParamMissing(): void {
			$this->expectException(\ArgumentCountError::class);
			new ResolveModel(parameter: 'post');
		}

		public function testThrowsWhenRouteParamIsEmptyString(): void {
			$this->expectException(\InvalidArgumentException::class);
			new ResolveModel(parameter: 'post', routeParam: '');
		}

		public function testGetParameterReturnsName(): void {
			$annotation = new ResolveModel(parameter: 'post', routeParam: 'postId');
			$this->assertSame('post', $annotation->getParameter());
		}

		public function testGetRouteParamReturnsProvidedValue(): void {
			$annotation = new ResolveModel(parameter: 'post', routeParam: 'postId');
			$this->assertSame('postId', $annotation->getRouteParam());
		}

		public function testGetParametersReturnsFullArray(): void {
			$params = ['parameter' => 'post', 'routeParam' => 'postId'];
			$this->assertSame($params, (new ResolveModel(...$params))->getParameters());
		}
	}
