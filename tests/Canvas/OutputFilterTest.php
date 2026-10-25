<?php

	namespace Quellabs\Canvas\Tests;

	use PHPUnit\Framework\TestCase;
	use Quellabs\Canvas\Kernel;
	use Quellabs\Canvas\Output\HtmlMinifyFilter;
	use Quellabs\Canvas\Output\OutputFilterInterface;
	use Symfony\Component\HttpFoundation\JsonResponse;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;
	use Symfony\Component\HttpFoundation\StreamedResponse;

	class OutputFilterTest extends TestCase {

		/**
		 * Confirms that a configured filter runs on the completed response.
		 * @return void
		 */
		public function testKernelRunsConfiguredFilter(): void {
			$kernel = new Kernel(['output_filter' => TestOutputFilter::class]);
			$response = $kernel->handle(Request::create('/missing-output-filter-test-route'));

			$this->assertSame('filtered', $response->headers->get('X-Output-Filter'));
		}

		/**
		 * Confirms that an unconfigured filter leaves responses alone.
		 * @return void
		 */
		public function testKernelLeavesFilterDisabledByDefault(): void {
			$kernel = new Kernel();
			$response = $kernel->handle(Request::create('/missing-output-filter-test-route'));

			$this->assertFalse($response->headers->has('X-Output-Filter'));
		}

		/**
		 * Rejects classes that do not implement the output filter contract.
		 * @return void
		 */
		public function testKernelRejectsInvalidFilterClass(): void {
			$this->expectException(\InvalidArgumentException::class);
			new Kernel(['output_filter' => \stdClass::class]);
		}

		/**
		 * Checks that HEAD reports the length of the filtered representation.
		 * @return void
		 */
		public function testHeadLengthMatchesFilteredGetResponse(): void {
			$kernel = new Kernel(['output_filter' => FixedHtmlOutputFilter::class]);
			$head = $kernel->handle(Request::create('/missing-output-filter-test-route', 'HEAD'));

			$this->assertSame('', $head->getContent());
			$this->assertSame((string)strlen('<html><body><div>fixed</div></body></html>'), $head->headers->get('Content-Length'));
		}

		/**
		 * Minifies block indentation while preserving content-sensitive regions.
		 * @return void
		 */
		public function testHtmlMinifierPreservesSensitiveContent(): void {
			$html = '<html>\n<body>\n<div>one</div> <span>two</span>\n<div>three</div>\n<!-- keep <div>  text </div> -->\n<pre><div>  keep  </div></pre>\n<script>const x = "</div>  <div>";</script>\n</body>\n</html>';
			$html = str_replace('\\n', "\n", $html);
			$response = new Response($html, 200, [
				'Content-Type' => 'text/html; charset=UTF-8',
				'Content-Length' => (string)strlen($html),
				'ETag' => '"original"',
			]);

			(new HtmlMinifyFilter())->filter(Request::create('/'), $response);

			$this->assertStringContainsString('<html><body><div>one</div> <span>two</span>', $response->getContent());
			$this->assertStringContainsString('<pre><div>  keep  </div></pre>', $response->getContent());
			$this->assertStringContainsString('<!-- keep <div>  text </div> -->', $response->getContent());
			$this->assertStringContainsString('const x = "</div>  <div>";', $response->getContent());
			$this->assertSame((string)strlen($response->getContent()), $response->headers->get('Content-Length'));
			$this->assertFalse($response->headers->has('ETag'));
		}

		/**
		 * Collapses page indentation while keeping inline word spacing and attributes.
		 * @return void
		 */
		public function testHtmlMinifierCollapsesFormattedPage(): void {
			$html = "<html>\n<head>\n<title>My  page</title>\n</head>\n<body>\n<div title=\"a > b\">\n  <span>First</span>\n  <span>Second</span>\n</div>\n</body>\n</html>";
			$response = new Response($html, 200, ['Content-Type' => 'text/html']);

			(new HtmlMinifyFilter())->filter(Request::create('/'), $response);

			$this->assertSame('<html><head> <title>My page</title> </head><body><div title="a > b"> <span>First</span> <span>Second</span> </div></body></html>', $response->getContent());
		}

		/**
		 * Leaves non-HTML, streamed, and no-transform responses untouched.
		 * @return void
		 */
		public function testHtmlMinifierSkipsIneligibleResponses(): void {
			$filter = new HtmlMinifyFilter();
			$request = Request::create('/');
			$json = new JsonResponse(['html' => "<div>\n<div>x</div>"]);
			$originalJson = $json->getContent();
			$filter->filter($request, $json);
			$this->assertSame($originalJson, $json->getContent());

			$stream = new StreamedResponse(static function (): void {});
			$filter->filter($request, $stream);
			$this->assertFalse($stream->getContent());

			$html = new Response("<div>\n<div>x</div>", 200, ['Content-Type' => 'text/html', 'Cache-Control' => 'no-transform']);
			$filter->filter($request, $html);
			$this->assertSame("<div>\n<div>x</div>", $html->getContent());
		}
	}

	class TestOutputFilter implements OutputFilterInterface {

		/**
		 * Marks the completed response to show that the kernel invoked this filter.
		 * @param Request $request
		 * @param Response $response
		 * @return void
		 */
		public function filter(Request $request, Response $response): void {
			$response->headers->set('X-Output-Filter', 'filtered');
		}
	}

	class FixedHtmlOutputFilter implements OutputFilterInterface {

		/**
		 * Supplies fixed HTML and applies the minifier for the HEAD length test.
		 * @param Request $request
		 * @param Response $response
		 * @return void
		 */
		public function filter(Request $request, Response $response): void {
			$response->headers->set('Content-Type', 'text/html');
			$response->setContent("<html>\n<body>\n<div>fixed</div>\n</body>\n</html>");
			(new HtmlMinifyFilter())->filter($request, $response);
		}
	}
