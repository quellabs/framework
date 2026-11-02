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

		/**
		 * Rejects construction when the required parameter argument is missing.
		 * @return void
		 */
		public function testThrowsWhenParameterMissing(): void {
			$this->expectException(\ArgumentCountError::class);
			new ResolveModel();
		}

		/**
		 * Rejects construction when parameter is not a string.
		 * @return void
		 */
		public function testThrowsWhenParameterIsNotString(): void {
			$this->expectException(\TypeError::class);
			new ResolveModel(parameter: 42);
		}

		/**
		 * Rejects construction when parameter is an empty string.
		 * @return void
		 */
		public function testThrowsWhenParameterIsEmptyString(): void {
			$this->expectException(\InvalidArgumentException::class);
			new ResolveModel(parameter: '', routeParam: 'id');
		}

		/**
		 * Rejects construction when routeParam is not a string.
		 * @return void
		 */
		public function testThrowsWhenRouteParamIsNotString(): void {
			$this->expectException(\TypeError::class);
			new ResolveModel(parameter: 'post', routeParam: 42);
		}

		/**
		 * Rejects construction when the required routeParam argument is missing.
		 * @return void
		 */
		public function testThrowsWhenRouteParamMissing(): void {
			$this->expectException(\ArgumentCountError::class);
			new ResolveModel(parameter: 'post');
		}

		/**
		 * Rejects construction when routeParam is an empty string.
		 * @return void
		 */
		public function testThrowsWhenRouteParamIsEmptyString(): void {
			$this->expectException(\InvalidArgumentException::class);
			new ResolveModel(parameter: 'post', routeParam: '');
		}

		/**
		 * getParameter() returns the configured parameter name.
		 * @return void
		 */
		public function testGetParameterReturnsName(): void {
			$annotation = new ResolveModel(parameter: 'post', routeParam: 'postId');
			$this->assertSame('post', $annotation->getParameter());
		}

		/**
		 * getRouteParam() returns the configured route parameter name.
		 * @return void
		 */
		public function testGetRouteParamReturnsProvidedValue(): void {
			$annotation = new ResolveModel(parameter: 'post', routeParam: 'postId');
			$this->assertSame('postId', $annotation->getRouteParam());
		}

		/**
		 * getParameters() returns every constructor argument keyed by name.
		 * @return void
		 */
		public function testGetParametersReturnsFullArray(): void {
			$params = ['parameter' => 'post', 'routeParam' => 'postId'];
			$this->assertSame($params, (new ResolveModel(...$params))->getParameters());
		}
	}
