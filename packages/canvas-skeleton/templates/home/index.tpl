{literal}
	<!doctype html>
	<html lang="en">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
		<title>Welcome — Canvas Application</title>
		<link rel="shortcut icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🎨</text></svg>">
		<link rel="preconnect" href="https://fonts.googleapis.com">
		<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
		<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
		<style>
			*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

			body {
				font-family: 'Nunito', sans-serif;
				font-size: 1rem;
				color: #212529;
				background-color: #fff;
				line-height: 1.6;
			}

			/* ── Shared ── */
			.container {
				max-width: 1100px;
				margin: 0 auto;
				padding: 0 48px;
			}

			/* ── Hero ── */
			.hero {
				background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
				padding: 120px 0 100px;
			}

			.hero-inner {
				display: flex;
				align-items: center;
				gap: 48px;
			}

			.hero-text { flex: 1; }

			.hero h1 {
				font-family: 'Nunito', sans-serif;
				font-size: 2.8rem;
				font-weight: 700;
				letter-spacing: 0.5px;
				line-height: 1.2;
				color: #fff;
				margin-bottom: 16px;
			}

			.hero h1 span { color: rgba(255,255,255,.85); }

			.hero p {
				font-family: 'Nunito', sans-serif;
				color: rgba(255,255,255,.55);
				font-size: 1.05rem;
				max-width: 520px;
				margin-bottom: 28px;
			}

			.btn {
				display: inline-block;
				font-family: 'Nunito', sans-serif;
				font-weight: 600;
				font-size: 0.95rem;
				padding: 0.5rem 1.25rem;
				border-radius: 0.3rem;
				border: 1px solid transparent;
				text-decoration: none;
				transition: all 0.15s;
				cursor: pointer;
				vertical-align: middle;
			}
			.btn + .btn { margin-left: 8px; }
			.btn svg { vertical-align: -3px; margin-right: 4px; }
			.btn-light { background: #f8f9fa; border-color: #f8f9fa; color: #212529; }
			.btn-light:hover { background: #e2e6ea; }
			.btn-outline-light { background: transparent; border-color: rgba(255,255,255,.5); color: #fff; }
			.btn-outline-light:hover { background: rgba(255,255,255,.1); border-color: #fff; }
			.btn-primary { background: #667eea; border-color: #667eea; color: #fff; white-space: nowrap; }
			.btn-primary:hover { background: #5a6fd6; border-color: #5a6fd6; }

			.hero-mascot {
				flex-shrink: 0;
				width: 340px;
				text-align: center;
			}
			.hero-mascot img {
				max-width: 100%;
				height: auto;
				filter: drop-shadow(0 20px 40px rgba(0,0,0,.3));
			}

			/* ── Content ── */
			.content {
				padding: 80px 48px;
				max-width: 1100px;
				margin: 0 auto;
			}

			.content h2 {
				font-family: 'Nunito', sans-serif;
				font-size: 1.5rem;
				font-weight: 700;
				color: #111;
				margin-bottom: 6px;
			}

			.content > p {
				font-family: 'Nunito', sans-serif;
				color: #6c757d;
				margin-bottom: 52px;
			}

			/* ── Steps ── */
			.steps { display: flex; flex-direction: column; }

			.step {
				display: flex;
				gap: 24px;
				align-items: flex-start;
				padding: 32px 0;
				border-bottom: 1px solid #e8ecf0;
			}
			.step:last-child { border-bottom: none; }

			.step-number {
				flex-shrink: 0;
				width: 32px;
				height: 32px;
				background: #667eea;
				color: #fff;
				border-radius: 50%;
				display: flex;
				align-items: center;
				justify-content: center;
				font-family: 'Nunito', sans-serif;
				font-weight: 700;
				font-size: 0.85rem;
				margin-top: 3px;
			}

			.step-body { flex: 1; }

			.step-body h3 {
				font-family: 'Nunito', sans-serif;
				font-size: 1rem;
				font-weight: 700;
				color: #111;
				margin-bottom: 6px;
			}

			.step-body p {
				font-family: 'Nunito', sans-serif;
				color: #555;
				font-size: 0.95rem;
				margin-bottom: 12px;
			}

			.step-body p:last-child { margin-bottom: 0; }

			pre {
				background: #1e293b;
				border-radius: 6px;
				padding: 16px 18px;
				overflow-x: auto;
				margin-bottom: 12px;
			}
			pre:last-child { margin-bottom: 0; }

			pre code {
				font-family: 'Courier New', Consolas, monospace;
				font-size: 0.875rem;
				color: #e2e8f0;
				white-space: pre;
			}

			.step-body ul {
				margin: 0 0 12px 20px;
				color: #555;
				font-size: 0.95rem;
				font-family: 'Nunito', sans-serif;
			}

			.step-body ul li { margin-bottom: 4px; }

			.step-body a { color: #667eea; text-decoration: none; }
			.step-body a:hover { text-decoration: underline; }

			p code, li code {
				font-family: 'Courier New', Consolas, monospace;
				font-size: 0.875em;
				background: #f1f3f5;
				color: #c7254e;
				padding: 1px 5px;
				border-radius: 3px;
			}

			/* ── Docs callout ── */
			.docs-callout {
				margin-top: 52px;
				background: #f8f9fa;
				border: 1px solid #dee2e6;
				border-radius: 8px;
				padding: 24px 28px;
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 24px;
			}

			.docs-callout p {
				font-family: 'Nunito', sans-serif;
				color: #444;
				font-size: 0.95rem;
				margin: 0;
			}

			/* ── Footer ── */
			footer {
				background: #202942;
				padding: 28px 24px;
				text-align: center;
			}

			footer p {
				font-family: 'Nunito', sans-serif;
				color: #8492a6;
				font-size: 0.875rem;
				margin: 0;
			}

			footer a { color: #8492a6; text-decoration: none; }
			footer a:hover { color: #667eea; }

			/* ── Responsive ── */
			@media (max-width: 768px) {
				.container { padding: 0 24px; }
				.hero-inner { flex-direction: column; text-align: center; }
				.hero h1 { font-size: 2rem; }
				.hero p { margin: 0 auto 28px; }
				.hero-mascot { width: 200px; }
				.content { padding: 60px 24px; }
				.docs-callout { flex-direction: column; text-align: center; }
			}
		</style>
	</head>
	<body>

	<!-- ── Hero ── -->
	<section class="hero">
		<div class="container">
			<div class="hero-inner">
				<div class="hero-text">
					<h1>Your Canvas app is<br><span>up and running.</span></h1>
					<p>You're looking at the default welcome page. Follow the steps below to build your first controller and route.</p>
					<div>
						<a href="#steps" class="btn btn-light">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M17.92,11.62a1,1,0,0,0-.21-.33l-5-5a1,1,0,0,0-1.42,1.42L14.59,11H7a1,1,0,0,0,0,2h7.59l-3.3,3.29a1,1,0,0,0,0,1.42,1,1,0,0,0,1.42,0l5-5a1,1,0,0,0,.21-.33A1,1,0,0,0,17.92,11.62Z"/></svg>
							Get Started
						</a>
						<a href="https://canvasphp.com/docs" target="_blank" class="btn btn-outline-light">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M21.17,2.06A13.1,13.1,0,0,0,19,1.87a12.94,12.94,0,0,0-7,2.05,12.94,12.94,0,0,0-7-2,13.1,13.1,0,0,0-2.17.19,1,1,0,0,0-.83,1v12a1,1,0,0,0,1.17,1,10.9,10.9,0,0,1,8.25,1.91l.12.07.11,0a.91.91,0,0,0,.7,0l.11,0,.12-.07A10.9,10.9,0,0,1,20.83,16a1,1,0,0,0,1.17-1v-12A1,1,0,0,0,21.17,2.06ZM11,15.35a12.87,12.87,0,0,0-6-1.48c-.33,0-.66,0-1,0v-10a8.69,8.69,0,0,1,1,0,10.86,10.86,0,0,1,6,1.8Zm9-1.44c-.34,0-.67,0-1,0a12.87,12.87,0,0,0-6,1.48V5.67a10.86,10.86,0,0,1,6-1.8,8.69,8.69,0,0,1,1,0Z"/></svg>
							Documentation
						</a>
					</div>
				</div>
				<div class="hero-mascot">
					<img
							src="https://canvasphp.com/images/gnome-transparent-2.webp"
							alt="Canvas mascot"
							width="340"
							height="340"
					>
				</div>
			</div>
		</div>
	</section>

	<!-- ── Steps ── -->
	<main class="content" id="steps">

		<h2>Getting started</h2>
		<p>Follow these steps to build your first page.</p>

		<div class="steps">

			<div class="step">
				<div class="step-number">1</div>
				<div class="step-body">
					<h3>Create a controller</h3>
					<p>Controllers live in <code>src/Controllers/</code>. Canvas scans that directory automatically — no registration needed.</p>
					<pre><code>&lt;?php

namespace App\Controllers;

use Quellabs\Canvas\Annotations\Route;
use Quellabs\Canvas\Controllers\BaseController;
use Symfony\Component\HttpFoundation\Response;

class HomeController extends BaseController {

    /**
     * @Route("/")
     */
    public function index(): Response {
        return $this->render('home/index.tpl', []);
    }
}</code></pre>
				</div>
			</div>

			<div class="step">
				<div class="step-number">2</div>
				<div class="step-body">
					<h3>Create a Smarty template</h3>
					<p>Canvas uses <a href="https://smarty.net" target="_blank" rel="noopener">Smarty</a> as its default
						template engine. Templates live in <code>templates/</code>. Variables passed from the controller are
						available directly in Smarty syntax. When desired you can switch to a <a href="https://canvasphp.com/docs?section=templating" target="_blank" rel="noopener">
							different templating engine</a> like switch to Blade, Twig, Handlebars, Plates, or Latte. See
						the documentation for details.

					<pre><code>&lt;!-- templates/home/index.tpl --&gt;
&lt;!DOCTYPE html&gt;
&lt;html&gt;
&lt;body&gt;
    &lt;h1&gt;Hello, {$name}!&lt;/h1&gt;
&lt;/body&gt;
&lt;/html&gt;</code></pre>
				</div>
			</div>

			<div class="step">
				<div class="step-number">3</div>
				<div class="step-body">
					<h3>Add route parameters</h3>
					<p>Routes support typed inline parameters. Canvas validates and casts them before your method is called — no manual type checking needed.</p>
					<pre><code>/**
 * @Route("/users/{id:int}")
 */
public function show(int $id): Response {
    return $this->render('users/show.tpl', ['id' => $id]);
}</code></pre>
				</div>
			</div>

			<div class="step">
				<div class="step-number">4</div>
				<div class="step-body">
					<h3>Query the database with ObjectQuel</h3>
					<p>Canvas integrates with <a href="https://objectquel.com" target="_blank" rel="noopener">ObjectQuel</a>, a QUEL-inspired ORM built on the Data Mapper pattern. Install it, configure your database, and start querying your entity model directly. Relationships are traversed with <code>via</code>, eliminating manual join conditions. The entity manager is available in any controller via <code>$this->em()</code>.</p>
					<pre><code>composer require quellabs/canvas-objectquel</code></pre>
					<pre><code>// config/database.php
return [
    'driver'   => 'mysql',
    'host'     => 'localhost',
    'database' => 'my_database',
    'username' => 'root',
    'password' => '',
];</code></pre>
					<pre><code>$results = $this->em()->executeQuery("
    range of p is PostEntity
    range of c is CommentEntity via p.comments
    retrieve (p.title, c.body)
    where p.published = true
    sort by p.createdAt desc
");</code></pre>
				</div>
			</div>

			<div class="step">
				<div class="step-number">5</div>
				<div class="step-body">
					<h3>Start the development server</h3>
					<p>The <code>public/</code> directory is the document root. Apache's <code>.htaccess</code> is already configured for you.</p>
					<pre><code>php -S localhost:8000 -t public/</code></pre>
					<p>Then open <code>http://localhost:8000/</code> in your browser.</p>
				</div>
			</div>

		</div>

		<div class="docs-callout">
			<p>Ready to go further? The full documentation covers ObjectQuel ORM, Aspect-Oriented Programming, legacy integration, and much more.</p>
			<a href="https://canvasphp.com/docs" class="btn btn-primary">
				<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M17.92,11.62a1,1,0,0,0-.21-.33l-5-5a1,1,0,0,0-1.42,1.42L14.59,11H7a1,1,0,0,0,0,2h7.59l-3.3,3.29a1,1,0,0,0,0,1.42,1,1,0,0,0,1.42,0l5-5a1,1,0,0,0,.21-.33A1,1,0,0,0,17.92,11.62Z"/></svg>
				Read the docs →
			</a>
		</div>

	</main>

	<!-- ── Footer ── -->
	<footer>
		<p>© 2025–2026 <a href="https://quellabs.com">Quellabs</a> &mdash; Released under the MIT License &mdash; <a href="https://canvasphp.com/docs">canvasphp.com</a></p>
	</footer>

	</body>
	</html>
{/literal}