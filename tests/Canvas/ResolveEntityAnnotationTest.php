<?php

declare(strict_types=1);

	namespace Quellabs\Canvas\Tests\ObjectQuel;

	use PHPUnit\Framework\TestCase;
	use Quellabs\CanvasObjectQuel\Annotations\ResolveEntity;

	/**
	 * Unit tests for the ResolveEntity annotation value object.
	 *
	 * ResolveEntity targets a controller parameter by name (mirroring
	 * @WithContext's parameter="..." convention) and requires the route
	 * parameter that supplies that parameter's value.
	 */
	class ResolveEntityAnnotationTest extends TestCase {

		public function testThrowsWhenParameterMissing(): void {
			$this->expectException(\ArgumentCountError::class);
			new ResolveEntity();
		}

		public function testThrowsWhenParameterIsNotString(): void {
			$this->expectException(\TypeError::class);
			new ResolveEntity(parameter: 42);
		}

		public function testThrowsWhenParameterIsEmptyString(): void {
			$this->expectException(\InvalidArgumentException::class);
			new ResolveEntity(parameter: '', routeParam: 'id');
		}

		public function testThrowsWhenRouteParamIsNotString(): void {
			$this->expectException(\TypeError::class);
			new ResolveEntity(parameter: 'post', routeParam: 42);
		}

		public function testThrowsWhenRouteParamMissing(): void {
			$this->expectException(\ArgumentCountError::class);
			new ResolveEntity(parameter: 'post');
		}

		public function testThrowsWhenRouteParamIsEmptyString(): void {
			$this->expectException(\InvalidArgumentException::class);
			new ResolveEntity(parameter: 'post', routeParam: '');
		}

		public function testGetParameterReturnsName(): void {
			$annotation = new ResolveEntity(parameter: 'post', routeParam: 'slug');
			$this->assertSame('post', $annotation->getParameter());
		}

		public function testGetRouteParamReturnsProvidedValue(): void {
			$annotation = new ResolveEntity(parameter: 'post', routeParam: 'slug');
			$this->assertSame('slug', $annotation->getRouteParam());
		}

		public function testGetParametersReturnsFullArray(): void {
			$params = ['parameter' => 'post', 'routeParam' => 'slug'];
			$this->assertSame($params, (new ResolveEntity(...$params))->getParameters());
		}
	}
