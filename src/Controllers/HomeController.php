<?php
	
	namespace App\Controllers;
	
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Canvas\Annotations\InterceptWith;
	use Quellabs\Canvas\Annotations\WithContext;
	use Quellabs\Canvas\Controllers\BaseController;
	use Quellabs\Canvas\Loom\Builder\Column;
	use Quellabs\Canvas\Loom\Builder\Columns;
	use Quellabs\Canvas\Loom\Builder\Field;
	use Quellabs\Canvas\Loom\Builder\Resource;
	use Quellabs\Canvas\Loom\Builder\Section;
	use Quellabs\Canvas\Loom\Builder\Tab;
	use Quellabs\Canvas\Loom\Builder\Tabs;
	use Quellabs\Canvas\Loom\Loom;
	use Quellabs\Canvas\Translation\TranslationAspect;
	use Quellabs\Contracts\Templates\TemplateEngineInterface;
	use Symfony\Component\HttpFoundation\Response;
	
	class HomeController extends BaseController {
		
		/**
		 * @InterceptWith(TranslationAspect::class)
		 * @WithContext(parameter="engine", context="blade")
		 * @Route("/")
		 * @param TemplateEngineInterface $engine
		 * @return Response
		 */
		public function index(TemplateEngineInterface $engine): Response {
			// Create page
			$definition = Resource::make('post-form', '/admin/posts/save')
				->title('Edit Post')
				->add(Tabs::make('post-tabs', 'general')
					->add(Tab::make('general', 'General')
						->add(Section::make('post-details')
							->add(Columns::make([70, 30])
								->add(Column::make()
									->add(Field::text('title', 'Title')->required()->maxlength(200))
									->add(Field::textarea('body', 'Content')->rows(10))
								)
								->add(Column::make()
									->add(Field::select('status', 'Status')->options(['draft' => 'Draft', 'published' => 'Published']))
									->add(Field::text('slug', 'Slug')->required())
									->add(Field::checkbox('featured', 'Featured post')->value('1'))
								)
							)
						)
					)
					->add(Tab::make('seo', 'SEO')
						->add(Section::make('post-seo')
							->add(Field::text('meta_title', 'Meta title')->maxlength(60))
							->add(Field::textarea('meta_description', 'Meta description')->rows(3)->maxlength(160))
						)
					)
				)
				->build();
			
			// Render page
			$loom = new Loom();
			
			return new Response("
				<html>
				<head>				
				<script src='https://cdn.jsdelivr.net/gh/quellabs/wakapac@main/wakapac.min.js'></script>
				<link rel='stylesheet' type='text/css' href='/loom.css'>
				<link rel='preconnect' href='https://fonts.googleapis.com'>
				<link rel='preconnect' href='https://fonts.gstatic.com' crossorigin>
				<link href='https://fonts.googleapis.com/css2?family=Roboto:ital,wght@0,100..900;1,100..900&display=swap' rel='stylesheet'>
				
				<style>
				  body {
				    font-family: 'Roboto', sans-serif;
				    font-optical-sizing: auto;
				    font-weight: 400;
				  }
				</style>
				</head>
				<body>
					{$loom->render($definition)}
				</body>
				</html>
			");
		}
		
		/**
		 * @Route("routes::test")
		 * @return Response
		 */
		public function hello(): Response {
			return new Response("Hello from routes file");
		}
		
		/**
		 * @Route("/user/{path:**}/hallo")
		 * @param string $path
		 * @return Response
		 */
		public function user(string $path): Response {
			return new Response("<h1>Hello, " . $path . "</h1>");
		}
	}