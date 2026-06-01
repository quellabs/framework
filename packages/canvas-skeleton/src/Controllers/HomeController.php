<?php

	// Enforce strict type checking for this file
	declare(strict_types=1);

	namespace App\Controllers;

	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Canvas\Controllers\BaseController;
	use Symfony\Component\HttpFoundation\Response;

	/**
	 * Handles the application root and welcome page
	 */
	class HomeController extends BaseController {

		/**
		 * Renders the welcome/getting-started page at the application root
		 * @Route("/")
		 */
		public function index(): Response {
			return $this->render('home/index.tpl', []);
		}
	}
