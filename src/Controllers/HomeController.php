<?php
	
	namespace App\Controllers;
	
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Canvas\Annotations\InterceptWith;
	use Quellabs\Canvas\Annotations\WithContext;
	use Quellabs\Canvas\Controllers\BaseController;
	use Quellabs\Canvas\Loom\Builder\Button;
	use Quellabs\Canvas\Loom\Builder\Column;
	use Quellabs\Canvas\Loom\Builder\Columns;
	use Quellabs\Canvas\Loom\Builder\Field;
	use Quellabs\Canvas\Loom\Builder\Resource;
	use Quellabs\Canvas\Loom\Builder\Section;
	use Quellabs\Canvas\Loom\Builder\Tab;
	use Quellabs\Canvas\Loom\Builder\Tabs;
	use Quellabs\Canvas\Loom\Loom;
	use Quellabs\Canvas\Loom\Validation\Rules\Email;
	use Quellabs\Canvas\Loom\Validation\Rules\MaxLength;
	use Quellabs\Canvas\Loom\Validation\Rules\MinLength;
	use Quellabs\Canvas\Loom\Validation\Rules\NotBlank;
	use Quellabs\Canvas\Translation\TranslationAspect;
	use Quellabs\Contracts\Templates\TemplateEngineInterface;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;
	
	class HomeController extends BaseController {
		
		const int MSG_SHOW_DELETE = 10000;
		const int MSG_HIDE_DELETE = 10001;
		
		/**
		 * Builds the resource definition, shared between GET and POST.
		 * Rules are defined here once so both rendering and validation use
		 * the same source.
		 */
		private function buildDefinition(): array {
			return Resource::make('post-form', '/save')
				->title('Edit Post')
				->useWakaForm()
				->addHeaderButton(
					Button::make('Delete')
						->danger()
						->name('delete')
						->showMessage(self::MSG_SHOW_DELETE)
						->hideMessage(self::MSG_HIDE_DELETE)
						->action("Stdlib.sendMessage('post-form', MSG_DELETE, 0, 0)")
				)
				->add(Tabs::make('post-tabs', 'general')
					->add(Tab::make('general', 'General')
						->add(Section::make('post-details')
							->add(Columns::make([70, 30])
								->add(Column::make()
									->add(Field::text('title', 'Title')
										->maxlength(200)
										->rules([new NotBlank(), new MaxLength(200)])
									)
									->add(Field::richtext('body', 'Content')
										->rows(10)
										->hint('{{ body.length }} characters typed')
									)
									->add(Field::file('attachments', 'Attachments', '/upload', multiple: true))
								)
								->add(Column::make()
									->add(Field::select('status', 'Status')
										->options([
											['value' => 'draft',     'label' => 'Draft'],
											['value' => 'published', 'label' => 'Published'],
										])
									)
									->add(Field::text('slug', 'Slug')
										->rules([new NotBlank(), new MinLength(3), new MaxLength(200)])
										->errorMessage('test error')
									)
									->add(Field::text('email', 'Email')
										->rules([new NotBlank(), new Email()])
									)
									->add(Field::toggle('featured', 'Featured post'))
									->add(Field::time('date', 'Date'))
									->add(Field::select('country', 'Country')
										->options([
											['value' => 'nl', 'label' => 'Netherlands'],
											['value' => 'de', 'label' => 'Germany'],
										])
									)
									->add(Field::select('region', 'Region')
										->dependsOn('country')
										->options([
											'nl' => [
												['value' => 'nh', 'label' => 'Noord-Holland'],
												['value' => 'zh', 'label' => 'Zuid-Holland'],
											],
											'de' => [
												['value' => 'by', 'label' => 'Bayern'],
												['value' => 'nw', 'label' => 'Nordrhein-Westfalen'],
											],
										])
									)
									->add(Field::select('city', 'City')
										->dependsOn('region')
										->options([
											'nl' => [
												'nh' => [
													['value' => 'ams', 'label' => 'Amsterdam'],
													['value' => 'hrl', 'label' => 'Haarlem'],
												],
												'zh' => [
													['value' => 'rot', 'label' => 'Rotterdam'],
													['value' => 'dhg', 'label' => 'Den Haag'],
												],
											],
											'de' => [
												'by' => [
													['value' => 'muc', 'label' => 'München'],
													['value' => 'nue', 'label' => 'Nürnberg'],
												],
												'nw' => [
													['value' => 'col', 'label' => 'Köln'],
													['value' => 'dus', 'label' => 'Düsseldorf'],
												],
											],
										])
									)
								)
							)
						)
					)
					->add(Tab::make('seo', 'SEO')
						->add(Section::make('post-seo')
							->add(Field::text('meta_title', 'Meta title')
								->maxlength(60)
								->rules([new MaxLength(60)])
							)
							->add(Field::textarea('meta_description', 'Meta description')
								->rows(3)
								->maxlength(160)
								->rules([new MaxLength(160)])
							)
						)
					)
				)
				->build();
		}
		
		/**
		 * @InterceptWith(TranslationAspect::class)
		 * @WithContext(parameter="engine", context="blade")
		 * @Route("/")
		 * @param TemplateEngineInterface $engine
		 * @return Response
		 */
		public function index(TemplateEngineInterface $engine): Response {
			$definition = $this->buildDefinition();
			$loom       = new Loom();
			
			$renderedDefinition = $loom->render($definition, [
				'title'   => 'My First Post',
				'slug'    => 'my-first-post',
				'status'  => 'draft',
				'country' => 'nl',
				'region'  => 'nh',
				'city'    => 'ams',
			]);
			
			return $this->buildResponse($renderedDefinition);
		}
		
		/**
		 * @Route("/upload", methods={"POST"})
		 */
		public function upload(Request $request): Response {
			$file = $request->files->get('file');
			
			// Store the file however you like, return the reference
			return new Response(
				json_encode([
					'id'   => uniqid(),
					'name' => $file->getClientOriginalName(),
					'size' => $file->getSize(),
				]),
				200,
				['Content-Type' => 'application/json']
			);
		}
		
		/**
		 * @Route("/save", methods={"POST"})
		 * @param Request $request
		 * @return Response
		 */
		public function save(Request $request): Response {
			$data       = $request->request->all();
			$definition = $this->buildDefinition();
			$loom       = new Loom();
			$result     = $loom->validate($definition, $data);
			
			if ($result->fails()) {
				// Re-render the form with submitted values and field errors
				$renderedDefinition = $loom->render($definition, array_merge($data, [
					'_errors' => $result->errors(),
				]));
				
				return $this->buildResponse($renderedDefinition);
			}
			
			// Validation passed — handle the save and redirect
			return new Response('', 302, ['Location' => '/']);
		}
		
		/**
		 * Wraps rendered Loom output in a minimal HTML page.
		 * @param string $body
		 * @return Response
		 */
		private function buildResponse(string $body): Response {
			return new Response("
				<html>
				<head>
				<script src='/wakapac.js'></script>
				<script src='/wakaform.js'></script>
				<script src='/wakajodit.js'></script>
				<script src='/wakasync.js'></script>
				<link rel='stylesheet' type='text/css' href='/loom.css'>
				<link rel='preconnect' href='https://fonts.googleapis.com'>
				<link rel='preconnect' href='https://fonts.gstatic.com' crossorigin>
				<link href='https://fonts.googleapis.com/css2?family=Roboto:ital,wght@0,100..900;1,100..900&display=swap' rel='stylesheet'>
				<script>
					wakaPAC.use(wakaForm);
					wakaPAC.use(WakaJodit);
					wakaPAC.use(wakaSync);
				</script>
				<style>
				  body {
				    font-family: 'Roboto', sans-serif;
				    font-optical-sizing: auto;
				    font-weight: 400;
				  }
				</style>
				</head>
				<body>
					{$body}
				</body>
				</html>
			");
		}
	}