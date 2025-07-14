# Canvas

[![Packagist](https://img.shields.io/packagist/v/quellabs/canvas.svg)](https://packagist.org/packages/quellabs/canvas)

A modern, lightweight PHP framework that gets out of your way. Write clean controllers with route annotations, query your database with an intuitive ORM, and let contextual containers handle the complexity. **Built to work seamlessly alongside your existing PHP codebase** - modernize incrementally without breaking what already works.

## What Makes Canvas Different

- **🔄 Legacy-First Integration** - Drop into any existing PHP app without breaking existing URLs
- **🎯 Annotation-Based Routing** - Define routes directly in controllers with `@Route` annotations
- **🗄️ ObjectQuel ORM** - Query databases using intuitive, natural PHP syntax
- **📦 Contextual Containers** - Work with interfaces; Canvas resolves implementations by context
- **⚡ Aspect-Oriented Programming** - Add crosscutting concerns without cluttering business logic
- **🔔 Event-Driven Architecture** - Qt-style signals and slots for decoupled component communication
- **⏰ Task Scheduling** - Cron-based background task execution with multiple timeout strategies

## Quick Start

### Installation

```bash
# New project
composer create-project quellabs/canvas-skeleton my-app

# Existing project
composer require quellabs/canvas
```

### Bootstrap (public/index.php)

This bootstrap file is automatically generated when using the skeleton package. If you're not using the skeleton package, you'll need to create this file manually.

```php
<?php
use Quellabs\Canvas\Kernel;
use Symfony\Component\HttpFoundation\Request;

require_once __DIR__ . '/../vendor/autoload.php';

$kernel = new Kernel();
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
```

### Controllers with Route Annotations

Canvas automatically discovers controllers and registers their routes using annotations:

```php
<?php
namespace App\Controllers;

use Quellabs\Canvas\Annotations\Route;
use Quellabs\Canvas\Controllers\BaseController;

class BlogController extends BaseController {
    
    /**
     * @Route("/")
     */
    public function index() {
        return $this->render('home.tpl');
    }
    
    /**
     * @Route("/posts")
     */
    public function list() {
        $posts = $this->em->findBy(Post::class, ['published' => true]);
        return $this->render('posts.tpl', $posts);
    }

    /**
     * @Route("/posts/{id:int}")
     */
    public function show(int $id) {
        $post = $this->em->find(Post::class, $id);
        return $this->render('post.tpl', $post);
    }
}
```

### ObjectQuel ORM

ObjectQuel lets you query data using syntax that feels natural to PHP developers. Inspired by QUEL (a declarative query language from early relational databases), it bridges traditional database querying with object-oriented programming.

#### Simple Entity Operations

```php
// Find by primary key - fastest lookup method
$user = $this->em->find(User::class, $id);

// Simple filtering using findBy - perfect for basic criteria
$activeUsers = $this->em->findBy(User::class, ['active' => true]);
$recentPosts = $this->em->findBy(Post::class, ['published' => true]);
```

#### Advanced ObjectQuel Queries

For complex queries, ObjectQuel provides a natural language syntax:

```php
// Basic ObjectQuel query
$results = $this->em->executeQuery("
    range of p is App\\Entity\\Post
    retrieve (p) where p.published = true
    sort by p.publishedAt desc
");

// Queries with relationships and parameters
$techPosts = $this->em->executeQuery("
    range of p is App\\Entity\\Post
    range of u is App\\Entity\\User via p.authorId
    retrieve (p, u.name) where p.title = /^Tech/i
    and p.published = :published
    sort by p.publishedAt desc
", [
    'published' => true
]);
```

#### Key Components

- **`range`** - Creates an alias for an entity class, similar to SQL's `FROM` clause. Think of it as "let p represent App\Entity\Post"
- **`retrieve`** - Functions like SQL's `SELECT`, specifying what data to return. You can retrieve entire entities (`p`) or specific properties (`u.name`)
- **`where`** - Standard filtering conditions, supporting parameters (`:published`) and regular expressions (`/^Tech/i` matches titles starting with "Tech", case-insensitive)
- **`sort by`** - Equivalent to SQL's `ORDER BY` for result ordering
- **`via`** - Establishes relationships between entities using foreign keys (`p.authorId` links posts to users)

#### ObjectQuel Features

- **Readability**: More intuitive than complex Doctrine DQL or QueryBuilder syntax
- **Type Safety**: Entity relationships are validated at query time
- **Parameter Binding**: Safe parameter substitution prevents SQL injection
- **Relationship Traversal**: Easily query across entity relationships with `via` keyword
- **Flexible Sorting**: Multi-column sorting with `sort by field1 asc, field2 desc`

## Route Validation & Parameters

Canvas provides powerful route parameter validation to ensure your controllers receive the correct data types and formats.

### Basic Parameter Validation

```php
class ProductController extends BaseController {
    
    /**
     * @Route("/products/{id:int}")
     */
    public function show(int $id) {
        // Only matches numeric IDs
        // /products/123 ✓  /products/abc ✗
    }
    
    /**
     * @Route("/users/{username:alpha}")
     */
    public function profile(string $username) {
        // Only matches alphabetic characters
        // /users/johndoe ✓  /users/john123 ✗
    }
    
    /**
     * @Route("/posts/{slug:slug}")
     */
    public function post(string $slug) {
        // Matches URL-friendly slugs
        // /posts/hello-world ✓  /posts/hello_world ✗
    }
}
```

### Advanced Parameter Patterns

```php
class FileController extends BaseController {
    
    /**
     * @Route("/files/{path:**}")
     */
    public function serve(string $path) {
        // Matches any path depth with wildcards
        // /files/css/style.css → path = "css/style.css"
        // /files/images/icons/user.png → path = "images/icons/user.png"
        return $this->serveFile($path);
    }
}
```

### Available Validators

- **`int`** - Integer numbers only
- **`alpha`** - Alphabetic characters only
- **`alnum`** - Alphanumeric characters only
- **`slug`** - URL-friendly slugs (letters, numbers, hyphens)
- **`uuid`** - Valid UUID format
- **`email`** - Valid email address format
- **`**`** - Wildcard (matches any characters including slashes)

### Route Prefixes

Group related routes under a common prefix:

```php
/**
 * @RoutePrefix("/api/v1")
 */
class ApiController extends BaseController {
    
    /**
     * @Route("/users")  // Actual route: /api/v1/users
     */
    public function users() {
        return $this->json($this->em->findBy(User::class, []));
    }
    
    /**
     * @Route("/users/{id:int}")  // Actual route: /api/v1/users/{id}
     */
    public function user(int $id) {
        $user = $this->em->find(User::class, $id);
        return $this->json($user);
    }
}
```

### Method Constraints

Restrict routes to specific HTTP methods:

```php
class UserController extends BaseController {
    
    /**
     * @Route("/users", methods={"GET"})
     */
    public function index() {
        // Only responds to GET requests
    }
    
    /**
     * @Route("/users", methods={"POST"})
     */
    public function create() {
        // Only responds to POST requests
    }
    
    /**
     * @Route("/users/{id:int}", methods={"PUT", "PATCH"})
     */
    public function update(int $id) {
        // Responds to both PUT and PATCH
    }
}
```

## Form Validation

Canvas provides a powerful validation system that separates validation logic from your controllers using aspects, keeping your business logic clean and focused.

### Basic Form Validation

```php
<?php
namespace App\Controllers;

use Quellabs\Canvas\Annotations\Route;
use Quellabs\Canvas\Annotations\InterceptWith;
use Quellabs\Canvas\Canvas\Validation\ValidateAspect;
use App\Validation\UserValidation;

class UserController extends BaseController {
    
    /**
     * @Route("/users/create", methods={"GET", "POST"})
     * @InterceptWith(ValidateAspect::class, validator=UserValidation::class)
     */
    public function create(Request $request) {
        if ($request->isMethod('POST')) {
            // Check validation results set by ValidateAspect
            if ($request->attributes->get('validation_passed', false)) {
                // Process valid form data
                $user = new User();
                $user->setName($request->request->get('name'));
                $user->setEmail($request->request->get('email'));
                $user->setPassword(password_hash($request->request->get('password'), PASSWORD_DEFAULT));
                
                $this->em->persist($user);
                $this->em->flush();
                
                return $this->redirect('/users');
            }
            
            // Validation failed - render form with errors
            return $this->render('users/create.tpl', [
                'errors' => $request->attributes->get('validation_errors', []),
                'old'    => $request->request->all()
            ]);
        }
        
        // Show empty form for GET requests
        return $this->render('users/create.tpl');
    }
}
```

### Creating Validation Classes

Define your validation rules in dedicated classes:

```php
<?php
namespace App\Validation;

use Quellabs\Canvas\Validation\Contracts\SanitizationInterface;
use Quellabs\Canvas\Validation\Rules\NotBlank;
use Quellabs\Canvas\Validation\Rules\Email;
use Quellabs\Canvas\Validation\Rules\Length;
use Quellabs\Canvas\Validation\Rules\ValueIn;

class UserValidation implements SanitizationInterface {
    
    public function getRules(): array {
        return [
            'name' => [
                new NotBlank('Name is required'),
                new Length(2, null, 'Name must be at least {{min}} characters')
            ],
            'email' => [
                new NotBlank('Email is required'),
                new Email('Please enter a valid email address')
            ],
            'password' => [
                new NotBlank('Password is required'),
                new Length(8, null, 'Password must be at least {{min}} characters')
            ],
            'role' => [
                new ValueIn(['admin', 'user', 'moderator'], 'Please select a valid role')
            ]
        ];
    }
}
```

### Available Validation Rules

Canvas includes common validation rules out of the box:

- **`AtLeastOneOf`** - At least one field from a group must be filled
- **`Date`** - Valid date format validation
- **`Email`** - Valid email format
- **`Length`** - String length constraints (`min`, `max`)
- **`NotBlank`** - Field cannot be empty
- **`NotHTML`** - Field cannot contain HTML tags
- **`PhoneNumber`** - Valid phone number format
- **`RegExp`** - Custom regular expression matching
- **`Type`** - Type validation (string, integer, array, etc.)
- **`ValueIn`** - Value must be from a predefined list
- **`Zipcode`** - Valid zipcode/postal code format
- **`NotLongWord`** - Prevents excessively long words

### API Validation with Auto-Response

For API endpoints, enable automatic JSON error responses. When `auto_respond=true`, the validation aspect will automatically return error responses when validation fails, so you don't need to check validation results in your controller method:

```php
/**
 * @Route("/api/users", methods={"POST"})
 * @InterceptWith(ValidateAspect::class, validator=UserValidation::class, auto_respond=true)
 */
public function createUser(Request $request) {
    // For API requests, validation failures automatically return JSON:
    // {
    //   "message": "Validation failed", 
    //   "errors": {
    //     "email": ["Please enter a valid email address"],
    //     "password": ["Password must be at least 8 characters"]
    //   }
    // }
    
    // This code only runs if validation passes
    $user = $this->createUserFromRequest($request);
    return $this->json(['success' => true, 'user_id' => $user->getId()]);
}
```

### Custom Validation Rules

Create your own validation rules by implementing the `ValidationRuleInterface`:

```php
<?php
namespace App\Validation\Rules;

use Quellabs\Canvas\Validation\Contracts\SanitizationRuleInterface;

class StrongPassword implements SanitizationRuleInterface {
    
    public function validate($value, array $options = []): bool {
        if (empty($value)) {
            return false;
        }
        
        // Must contain uppercase, lowercase, number, and special character
        return preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]/', $value);
    }
    
    public function getMessage(): string {
        return 'Password must contain uppercase, lowercase, number, and special character';
    }
}
```

Use custom rules in your validation classes:

```php
'password' => [
    new NotBlank(),
    new Length(8),
    new StrongPassword()
]
```

# Input Sanitization

Canvas provides a powerful input sanitization system that automatically cleans and normalizes incoming request data before it reaches your controllers. The sanitization system works seamlessly with Canvas's validation system to provide a complete input security layer.

## Why Sanitize Input?

Input sanitization is crucial for:
- **XSS Prevention** - Remove or neutralize potentially dangerous HTML/JavaScript
- **Data Normalization** - Ensure consistent data formats (emails, phone numbers, etc.)
- **Content Filtering** - Strip unwanted characters or content
- **Security Hardening** - Clean data before validation and processing

## Basic Sanitization

Apply sanitization using the `@InterceptWith` annotation with `SanitizeAspect`:

```php
<?php
namespace App\Controllers;

use Quellabs\Canvas\Annotations\Route;
use Quellabs\Canvas\Annotations\InterceptWith;
use Quellabs\Canvas\Sanitization\SanitizeAspect;
use App\Sanitization\UserSanitization;

class UserController extends BaseController {
    
    /**
     * @Route("/users/create", methods={"GET", "POST"})
     * @InterceptWith(SanitizeAspect::class, sanitizer=UserSanitization::class)
     */
    public function create(Request $request) {
        if ($request->isMethod('POST')) {
            // Request data is automatically sanitized before reaching this point
            $name = $request->request->get('name');     // Already trimmed and cleaned
            $email = $request->request->get('email');   // Already normalized
            $bio = $request->request->get('bio');       // Already stripped of dangerous HTML
            
            // Process the clean data
            $user = new User();
            $user->setName($name);
            $user->setEmail($email);
            $user->setBio($bio);
            
            $this->em->persist($user);
            $this->em->flush();
            
            return $this->redirect('/users');
        }
        
        return $this->render('users/create.tpl');
    }
}
```

## Creating Sanitization Classes

Define your sanitization rules in dedicated classes that implement `SanitizationInterface`:

```php
<?php
namespace App\Sanitization;

use Quellabs\Canvas\Sanitization\Contracts\SanitizationInterface;
use Quellabs\Canvas\Sanitization\Rules\Trim;
use Quellabs\Canvas\Sanitization\Rules\EmailSafe;
use Quellabs\Canvas\Sanitization\Rules\StripTags;
use Quellabs\Canvas\Sanitization\Rules\HtmlEscape;

class UserSanitization implements SanitizationInterface {
    
    public function getRules(): array {
        return [
            'name' => [
                new Trim(),                    // Remove leading/trailing whitespace
                new StripTags()                // Remove HTML tags
            ],
            'email' => [
                new Trim(),
                new EmailSafe()                // Remove non-email characters
            ],
            'website' => [
                new Trim(),
                new UrlSafe()                  // Remove unsafe URL characters
            ]
        ];
    }
}
```

## Built-in Sanitization Rules

Canvas includes essential sanitization rules focused on security and data cleaning:

### Text Sanitization
- **`Trim`** - Remove leading and trailing whitespace
- **`StripTags`** - Remove HTML tags (with optional allowlist for safe tags)
- **`WhitespaceNormalize`** - Convert multiple consecutive whitespace characters to single spaces

### Security Sanitization
- **`HtmlEscape`** - Convert HTML special characters to entities for XSS prevention
- **`ScriptSafe`** - Remove or neutralize dangerous script-related content
- **`SqlSafe`** - Remove characters commonly used in SQL injection attempts
- **`RemoveControlChars`** - Remove control characters that can cause display issues

### URL and Path Sanitization
- **`EmailSafe`** - Remove characters not allowed in email addresses
- **`UrlSafe`** - Remove characters not safe for URLs
- **`PathSafe`** - Remove characters not safe for file paths (prevents directory traversal)

## Combining Sanitization with Validation

Sanitization works best when combined with validation. The order depends on your specific needs:

```php
class ContactController extends BaseController {
    
    /**
     * @Route("/contact", methods={"GET", "POST"})
     * @InterceptWith(ValidateAspect::class, validator=ContactValidation::class)
     * @InterceptWith(SanitizeAspect::class, sanitizer=ContactSanitization::class)
     */
    public function contact(Request $request) {
        if ($request->isMethod('POST')) {
            // Data flow: Raw Input → Sanitization → Validation → Controller
            // Only clean, valid data reaches your business logic
            
            if ($request->attributes->get('validation_passed', false)) {
                $this->processContactForm($request);
                return $this->redirect('/contact/success');
            }
            
            return $this->render('contact.tpl', [
                'errors' => $request->attributes->get('validation_errors', []),
                'old'    => $request->request->all()  // Already sanitized data
            ]);
        }
        
        return $this->render('contact.tpl');
    }
}
```

## Chain Sanitization

Apply multiple sanitization rules to the same field. Rules are executed in the order they're defined:

```php
class ProductSanitization implements SanitizationInterface {
    
    public function getRules(): array {
        return [
            'title' => [
                new Trim(),                    // 1. Remove whitespace
                new StripTags(),              // 2. Remove HTML
                new WhitespaceNormalize()     // 3. Normalize internal whitespace
            ],
            'description' => [
                new Trim(),
                new StripTags(['p', 'br', 'strong', 'em', 'ul', 'ol', 'li']),
                new HtmlEscape(),             // Escape any remaining HTML
                new WhitespaceNormalize()     // Convert multiple spaces to single
            ],
            'slug' => [
                new Trim(),
                new PathSafe()                // Make safe for URLs/paths
            ]
        ];
    }
}
```

## Custom Sanitization Rules

Create your own sanitization rules by implementing `SanitizationRuleInterface`. Here are examples of non-destructive sanitization:

```php
<?php
namespace App\Sanitization\Rules;

use Quellabs\Canvas\Sanitization\Contracts\SanitizationRuleInterface;

class RemoveNullBytes implements SanitizationRuleInterface {
    
    public function sanitize(mixed $value): mixed {
        if (!is_string($value)) {
            return $value;
        }
        
        // Remove null bytes that can cause security issues
        return str_replace("\0", '', $value);
    }
}

class RemoveInvisibleChars implements SanitizationRuleInterface {
    
    public function sanitize(mixed $value): mixed {
        if (!is_string($value)) {
            return $value;
        }
        
        // Remove invisible Unicode characters that can cause display issues
        return preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $value);
    }
}
```

## Performance Considerations

- **Selective Sanitization** - Only define rules for fields that need sanitization
- **Efficient Rules** - Custom rules should be optimized for performance
- **Type Checking** - Rules should check input types before processing

## Error Handling

Sanitization is designed to be non-breaking. Invalid sanitization classes or rules will throw descriptive exceptions:

```php
// If sanitization class doesn't exist
InvalidArgumentException: Sanitization class 'App\Invalid\Class' does not exist

// If class doesn't implement SanitizationInterface  
InvalidArgumentException: Sanitization class 'App\MyClass' must implement SanitizationInterface

// If sanitization class can't be instantiated
RuntimeException: Failed to instantiate sanitization class 'App\MyClass': Constructor requires parameter
```

## Built-in Aspects

Canvas includes three powerful built-in aspects that handle common web application concerns. These aspects demonstrate the framework's commitment to security, performance, and best practices.

### CSRF Protection Aspect

The `CsrfProtectionAspect` implements Cross-Site Request Forgery protection using tokens to ensure requests originate from your application.

#### Features

- **Automatic Token Generation** - Creates unique tokens for each user session
- **Smart Validation** - Checks tokens in both form data and headers (for AJAX requests)
- **Safe Method Exemption** - Skips protection for GET, HEAD, and OPTIONS requests
- **Session Management** - Prevents session bloat with configurable token limits
- **Flexible Response Handling** - Returns appropriate error formats for web and API requests

#### Basic Usage

```php
<?php
use Quellabs\Canvas\Security\CsrfProtectionAspect;

class ContactController extends BaseController {
    
    /**
     * @Route("/contact", methods={"GET", "POST"})
     * @InterceptWith(CsrfProtectionAspect::class)
     */
    public function contact(Request $request) {
        if ($request->isMethod('POST')) {
            // CSRF token automatically validated before this method runs
            $this->processContactForm($request);
            return $this->redirect('/contact/success');
        }
        
        // Token available in template via request attributes
        return $this->render('contact.twig', [
            'csrf_token' => $request->attributes->get('csrf_token'),
            'csrf_token_name' => $request->attributes->get('csrf_token_name')
        ]);
    }
}
```

#### Template Integration

Use the CSRF token in your forms:

```html
<form method="POST" action="/contact">
    <input type="hidden" name="{$csrf_token_name}" value="{$csrf_token}">
    <!-- Rest of your form -->
    <button type="submit">Send Message</button>
</form>
```

#### AJAX Integration

For AJAX requests, include the token in headers:

```javascript
// Get token from meta tag or hidden field
const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

fetch('/api/data', {
    method: 'POST',
    headers: {
        'X-CSRF-Token': csrfToken,
        'Content-Type': 'application/json'
    },
    body: JSON.stringify({ data: 'value' })
});
```

#### Configuration Options

Customize CSRF protection behavior:

```php
/**
 * @InterceptWith(CsrfProtectionAspect::class, 
 *     tokenName="_token", 
 *     headerName="X-Custom-CSRF-Token",
 *     intention="contact_form",
 *     exemptMethods={"GET", "HEAD"},
 *     maxTokens=20
 * )
 */
public function sensitiveAction() {
    // Custom CSRF configuration
}
```

**Parameters:**
- `tokenName` - Form field name for the token (default: `_csrf_token`)
- `headerName` - HTTP header name for AJAX tokens (default: `X-CSRF-Token`)
- `intention` - Token scope/purpose for different contexts (default: `default`)
- `exemptMethods` - HTTP methods that skip validation (default: `['GET', 'HEAD', 'OPTIONS']`)
- `maxTokens` - Maximum tokens per intention to prevent session bloat (default: `10`)

### Security Headers Aspect

The `SecurityHeadersAspect` automatically adds security-related HTTP headers to protect against common web vulnerabilities following OWASP recommendations.

#### Protected Attacks

- **Clickjacking** - X-Frame-Options prevents embedding in malicious frames
- **MIME Sniffing** - X-Content-Type-Options prevents content type confusion
- **XSS** - X-XSS-Protection enables browser XSS filtering
- **Man-in-the-Middle** - Strict-Transport-Security enforces HTTPS
- **Information Leakage** - Referrer-Policy controls referrer information
- **Code Injection** - Content-Security-Policy prevents unauthorized script execution

#### Basic Usage

```php
<?php
use Quellabs\Canvas\Security\SecurityHeadersAspect;

/**
 * @InterceptWith(SecurityHeadersAspect::class)
 */
class SecureController extends BaseController {
    
    /**
     * @Route("/admin/dashboard")
     */
    public function dashboard() {
        // Response automatically includes security headers
        return $this->render('admin/dashboard.twig');
    }
}
```

#### Configuration Options

Customize security headers for different needs:

```php
/**
 * @InterceptWith(SecurityHeadersAspect::class,
 *     frameOptions="DENY",
 *     noSniff=true,
 *     xssProtection=true,
 *     hstsMaxAge=31536000,
 *     hstsIncludeSubdomains=true,
 *     referrerPolicy="strict-origin-when-cross-origin",
 *     csp="default-src 'self'; script-src 'self' 'unsafe-inline'"
 * )
 */
public function secureApi() {
    // Strict security headers for sensitive operations
}
```

**Parameters:**
- `frameOptions` - X-Frame-Options value: `DENY`, `SAMEORIGIN`, or `ALLOW-FROM` (default: `SAMEORIGIN`)
- `noSniff` - Enable X-Content-Type-Options: nosniff (default: `true`)
- `xssProtection` - Enable X-XSS-Protection (default: `true`)
- `hstsMaxAge` - HSTS max-age in seconds, 0 disables (default: `31536000` = 1 year)
- `hstsIncludeSubdomains` - Include subdomains in HSTS (default: `true`)
- `referrerPolicy` - Referrer-Policy value (default: `strict-origin-when-cross-origin`)
- `csp` - Content-Security-Policy value, null disables (default: `null`)

#### Content Security Policy Examples

Common CSP configurations for different application types:

```php
// Strict policy for admin areas
csp: "default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'"

// Moderate policy for regular pages
csp: "default-src 'self'; script-src 'self' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline'; img-src 'self' https: data:"

// Development policy (more permissive)
csp: "default-src 'self' 'unsafe-inline' 'unsafe-eval'; img-src 'self' data: blob:"
```

### Cache Aspect

The `CacheAspect` provides method-level caching with intelligent key generation, configurable TTL, and thread-safe operations.

#### Features

- **Automatic Key Generation** - Creates cache keys from method signature and arguments
- **Thread-Safe Operations** - Uses file locking to prevent cache corruption
- **Graceful Fallback** - Executes original method if caching fails
- **Flexible TTL** - Configurable time-to-live with never-expire option
- **Context Namespacing** - Separate cache contexts for different application areas
- **Argument-Aware** - Different cache entries for different method arguments

#### Basic Usage

```php
<?php
use Quellabs\Canvas\Cache\CacheAspect;

class ReportController extends BaseController {
    
    /**
     * @Route("/reports/monthly")
     * @InterceptWith(CacheAspect::class, ttl=3600)
     */
    public function monthlyReport() {
        // Expensive operation cached for 1 hour
        return $this->generateMonthlyReport();
    }
    
    /**
     * @Route("/reports/user/{id:int}")
     * @InterceptWith(CacheAspect::class, namespace="user_reports", ttl=1800)
     */
    public function userReport(int $id) {
        // Each user ID gets separate cache entry
        // Cached for 30 minutes in "user_reports" context
        return $this->generateUserReport($id);
    }
}
```

#### Custom Cache Keys

Override automatic key generation:

```php
/**
 * @InterceptWith(CacheAspect::class, 
 *     namespace="products",
 *     key="product_catalog", 
 *     ttl=7200
 * )
 */
public function productCatalog() {
    // Uses fixed cache key regardless of arguments
    // Useful for methods that always return the same data
}
```

#### Configuration Options

```php
/**
 * @InterceptWith(CacheAspect::class,
 *     namespace="api_responses",                  // Cache namespace
 *     key=null,                                   // Auto-generate from method
 *     ttl=3600,                                   // 1 hour cache
 *     cachePath="/var/cache/canvas",              // Storage location
 *     lockTimeout=10,                             // File lock timeout
 *     gracefulFallback=true                       // Execute method if caching fails
 * )
 */
public function expensiveOperation($param1, $param2) {
    // Custom cache configuration
}
```

**Parameters:**
- `key` - Custom cache key template, null for auto-generation (default: `null`)
- `ttl` - Time to live in seconds, 0 for never expires (default: `3600`)
- `context` - Cache namespace for organization (default: `default`)
- `cachePath` - Base cache directory path (default: `/storage/cache`)
- `lockTimeout` - File lock timeout in seconds (default: `5`)
- `gracefulFallback` - Execute method if caching fails (default: `true`)

#### Cache Key Generation

The aspect automatically generates intelligent cache keys:

```php
// Method: ProductController::getProduct($id, $includeReviews)
// Arguments: [123, true]
// Generated key: "product_controller.get_product.arg0:123_arg1:true"

// Method: UserController::search($query, $filters)
// Arguments: ["admin", ["active" => true, "role" => "admin"]]
// Generated key: "user_controller.search.arg0:admin_arg1:array_a1b2c3"
```

#### Performance Considerations

- **Argument Serialization** - Complex objects are hashed to keep keys manageable
- **Key Length Limits** - Long keys are automatically truncated and hashed
- **Lock Timeouts** - Configurable timeouts prevent indefinite blocking
- **Storage Efficiency** - File-based storage with automatic cleanup

## Task Scheduling

Canvas includes a comprehensive task scheduling system that allows you to run background jobs on a cron-like schedule. The scheduler supports multiple execution strategies, timeout handling, and distributed locking to prevent concurrent task execution.

### Creating Tasks

Create tasks by extending the `AbstractTask` class and implementing the required methods:

```php
<?php
namespace App\Tasks;

use Quellabs\Contracts\TaskScheduler\AbstractTask;

class DatabaseCleanupTask extends AbstractTask {
    
    public function handle(): void {
        // Your task logic here
        $this->cleanupExpiredSessions();
        $this->archiveOldLogs();
        $this->optimizeTables();
    }
    
    public function getDescription(): string {
        return "Clean up expired sessions and optimize database tables";
    }
    
    public function getSchedule(): string {
        return "0 2 * * *"; // Run daily at 2 AM
    }
    
    public function getName(): string {
        return "database-cleanup";
    }
    
    public function getTimeout(): int {
        return 1800; // 30 minutes timeout
    }
    
    public function enabled(): bool {
        return true; // Task is enabled
    }
    
    // Optional: Handle task failures
    public function onFailure(\Exception $exception): void {
        error_log("Database cleanup failed: " . $exception->getMessage());
        // Send notification, log to monitoring system, etc.
    }
    
    // Optional: Handle task timeouts
    public function onTimeout(\Exception $exception): void {
        error_log("Database cleanup timed out: " . $exception->getMessage());
        // Perform cleanup, send alerts, etc.
    }
    
    private function cleanupExpiredSessions(): void {
        // Implementation details...
    }
    
    private function archiveOldLogs(): void {
        // Implementation details...
    }
    
    private function optimizeTables(): void {
        // Implementation details...
    }
}
```

### Task Discovery

Canvas automatically discovers tasks using its own discovery mechanism that reads from `composer.json`. Add your task classes to your `composer.json`:

```json
{
  "extra": {
    "discover": {
      "task-scheduler": {
        "providers": [
          "App\\Tasks\\DatabaseCleanupTask",
          "App\\Tasks\\EmailQueueTask",
          "App\\Tasks\\ReportGenerationTask"
        ]
      }
    }
  }
}
```

After updating composer.json, run:

```bash
composer dump-autoload
```

### Running the Task Scheduler

Running the task scheduler is done through sculpt:

```bash
php ./vendor/bin/sculpt schedule:run
```

### Setting Up Cron

Add this to your system's crontab to run the scheduler every minute:

```bash
# Edit crontab
crontab -e

# Add this line (adjust path to your script)
* * * * * /usr/bin/php /path/to/your/app/bin/sculpt schedule:run
```

### Cron Schedule Format

Canvas uses standard cron expressions for scheduling:

```
* * * * *
│ │ │ │ │
│ │ │ │ └─── Day of Week   (0-7, Sunday=0 or 7)
│ │ │ └───── Month         (1-12)
│ │ └─────── Day of Month  (1-31)
│ └───────── Hour          (0-23)
└─────────── Minute        (0-59)
```

**Common Examples:**

```php
"0 */6 * * *"     // Every 6 hours
"30 2 * * *"      // Daily at 2:30 AM
"0 0 * * 0"       // Weekly on Sunday midnight
"0 0 1 * *"       // Monthly on the 1st
"*/15 * * * *"    // Every 15 minutes
"0 9 * * 1-5"     // Weekdays at 9 AM
"0 0 * * 1,3,5"   // Monday, Wednesday, Friday at midnight
```

### Timeout Strategies

Canvas automatically selects the best timeout strategy based on your system:

- **No Timeout Strategy**: Used when `getTimeout()` returns 0
- **PCNTL Strategy (Preferred)**: Used on systems with PCNTL support. Uses signals for efficient timeout handling
- **Process Strategy (Fallback)**: Used on systems without PCNTL. Runs tasks in separate processes:

## Event-Driven Architecture with SignalHub

Canvas includes a signal system inspired by Qt's signals and slots pattern, enabling components to communicate without tight coupling.

### Basic Signal Usage

Add the `HasSignals` trait to emit signals from your classes:

```php
<?php
namespace App\Services;

use Quellabs\SignalHub\HasSignals;
use Quellabs\SignalHub\Signal;

class UserService {
    use HasSignals;
    
    public Signal $userRegistered;
    
    public function __construct() {
        // Define a signal that passes a User object
        $this->userRegistered = $this->createSignal('userRegistered', [User::class]);
    }
    
    public function register(string $email, string $password): User {
        $user = new User($email, $password);
        $this->saveUser($user);
        
        // Notify other parts of the app
        $this->userRegistered->emit($user);
        
        return $user;
    }
}
```

### Connecting to Signals

Listen for signals in other services:

```php
<?php
namespace App\Services;

class EmailService {
    
    public function __construct(
        UserService $userService,
        private MailerInterface $mailer
    ) {
        // Connect to the userRegistered signal
        $userService->userRegistered->connect($this, 'sendWelcomeEmail');
    }
    
    public function sendWelcomeEmail(User $user): void {
        // Send welcome email when a user registers
        $this->mailer->send($user->getEmail(), 'Welcome!', 'welcome-template');
    }
}
```

### Using Standalone Signals

Create global signals with SignalHub:

```php
<?php
// Create a system-wide signal
$signalHub = new SignalHub();
$loginSignal = $signalHub->createSignal('user.login', [User::class]);

// Connect a handler
$loginSignal->connect(function(User $user) {
    echo "User {$user->name} logged in";
});

// Emit the signal from anywhere
$loginSignal->emit($currentUser);
```

### Controller Integration

Use signals in controllers with dependency injection:

```php
<?php
class UserController extends BaseController {
    
    public function __construct(private UserService $userService) {}
    
    /**
     * @Route("/register", methods={"POST"})
     */
    public function register(Request $request) {
        $email = $request->request->get('email');
        $password = $request->request->get('password');
        
        // This automatically emits the userRegistered signal
        $user = $this->userService->register($email, $password);
        
        return $this->json(['success' => true]);
    }
}
```

### Key Features

- **Type Safety**: Parameters are validated when connecting and emitting
- **Simple Setup**: Just add the `HasSignals` trait to start emitting signals
- **Flexible Connections**: Connect using object methods or anonymous functions
- **Dependency Injection**: Works seamlessly with Canvas's container system

## Legacy Integration

Canvas is designed to work seamlessly alongside existing PHP codebases, allowing you to modernize your applications incrementally without breaking existing functionality. The legacy integration system provides a smooth migration path from traditional PHP applications to Canvas.

### Quick Start with Legacy Code

**Start using Canvas today in your existing PHP application**. No rewrites required - Canvas's intelligent fallthrough system lets you modernize at your own pace.

First, enable legacy support by updating your `public/index.php`:

```php
<?php
// public/index.php
use Quellabs\Canvas\Kernel;
use Symfony\Component\HttpFoundation\Request;

require_once __DIR__ . '/../vendor/autoload.php';

$kernel = new Kernel([
    'legacy_enabled' => true,          // Enable legacy support
    'legacy_path' => __DIR__ . '/../'  // Path to your existing files
]);

$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
```

**That's it!** Your existing application now has Canvas superpowers while everything continues to work exactly as before.

### Using Canvas Services in Legacy Files

Now you can immediately start using Canvas services in your existing files:

```php
<?php
// legacy/users.php - existing file, now enhanced with Canvas
use Quellabs\Canvas\Legacy\LegacyBridge;

// Access Canvas services in legacy code
$em = canvas('EntityManager');
$users = $em->findBy(User::class, ['active' => true]);

// Use ObjectQuel for complex queries
$recentUsers = $em->executeQuery("
    range of u is App\\Entity\\User
    retrieve u where u.active = true and u.createdAt > :since
    sort by u.createdAt desc
    limit 10
", ['since' => date('Y-m-d', strtotime('-30 days'))]);

echo "Found " . count($users) . " active users<br>";

foreach ($recentUsers as $user) {
    echo "<h3>{$user->name}</h3>";
    echo "<p>Joined: " . $user->createdAt->format('Y-m-d') . "</p>";
}
```

### How Route Fallthrough Works

Canvas uses an intelligent fallthrough system that tries Canvas routes first, then automatically looks for corresponding legacy PHP files:

```
URL Request: /users/profile
1. Try Canvas route: /users/profile → ❌ Not found in Canvas controllers
2. Try legacy files:
   - legacy/users/profile.php → ✅ Found! Execute this file
   - legacy/users/profile/index.php → Alternative location

Examples:
- `/users` → `legacy/users.php` or `legacy/users/index.php`
- `/admin/dashboard` → `legacy/admin/dashboard.php`
- `/api/data` → `legacy/api/data.php`
```

### Custom File Resolvers

If your legacy application has a different file structure, you can write custom file resolvers:

```php
<?php
// src/Legacy/CustomFileResolver.php
use Quellabs\Canvas\Legacy\FileResolverInterface;

class CustomFileResolver implements FileResolverInterface {
    
    public function resolve(string $path): ?string {
        // Handle WordPress-style routing
        if ($path === '/') {
            return $this->legacyPath . '/index.php';
        }
        
        // Map URLs to custom file structure
        if (str_starts_with($path, '/blog/')) {
            $slug = substr($path, 6);
            return $this->legacyPath . "/wp-content/posts/{$slug}.php";
        }
        
        // Handle custom admin structure
        if (str_starts_with($path, '/admin/')) {
            $adminPath = substr($path, 7);
            return $this->legacyPath . "/backend/modules/{$adminPath}.inc.php";
        }
        
        return null; // Fall back to default behavior
    }
}

// Register with kernel
$kernel->getLegacyHandler()->addResolver(new CustomFileResolver);
```

### Legacy Preprocessing

Canvas includes preprocessing capabilities to handle legacy PHP files that use common patterns like `header()`, `die()`, and `exit()` functions:

```php
<?php
// public/index.php
$kernel = new Kernel([
    'legacy_enabled' => true,
    'legacy_path' => __DIR__ . '/../legacy',
    'legacy_preprocessing' => true  // Default: enabled
]);
```

**What preprocessing does:**
- Converts `header()` calls to Canvas's internal header management
- Transforms `http_response_code()` to Canvas response handling
- Converts `die()` and `exit()` calls to Canvas exceptions (maintains flow control)

**Important limitation:** Preprocessing only applies to the main legacy file, not to included/required files. Files included by legacy scripts may need refactoring if they use these functions.

### Benefits of Legacy Integration

- **🚀 Zero Disruption**: Existing URLs continue to work unchanged
- **🔧 Enhanced Legacy Code**: Add Canvas services (ORM, caching, logging) to legacy files
- **🔄 Flexible Migration**: Start with services, move to controllers, then to full Canvas
- **📈 Immediate Benefits**: Better database abstraction, modern dependency injection, improved error handling

## Advanced Features

### Aspect-Oriented Programming

Canvas provides true AOP for controller methods, allowing you to separate crosscutting concerns from your business logic. Aspects execute at different stages of the request lifecycle.

#### Creating Aspects

Aspects implement interfaces based on when they should execute:

```php
<?php
namespace App\Aspects;

use Quellabs\Contracts\AOP\BeforeAspect;
use Quellabs\Contracts\AOP\AroundAspect;
use Quellabs\Contracts\AOP\AfterAspect;
use Symfony\Component\HttpFoundation\Response;

// Before Aspects - Execute before the method, can stop execution
class RequireAuthAspect implements BeforeAspect {
    public function __construct(private AuthService $auth) {}
    
    public function before(MethodContext $context): ?Response {
        if (!$this->auth->isAuthenticated()) {
            return new RedirectResponse('/login');
        }
        
        return null; // Continue execution
    }
}

// Around Aspects - Wrap the entire method execution
class CacheAspect implements AroundAspect {
    public function around(MethodContext $context, callable $proceed): mixed {
        $key = $this->generateCacheKey($context);
        
        if ($cached = $this->cache->get($key)) {
            return $cached;
        }
        
        $result = $proceed(); // Call the original method
        $this->cache->set($key, $result, $this->ttl);
        return $result;
    }
}

// After Aspects - Execute after the method, can modify response
class AuditLogAspect implements AfterAspect {
    public function after(MethodContext $context, Response $response): void {
        $this->logger->info('Method executed', [
            'controller' => get_class($context->getTarget()),
            'method' => $context->getMethodName(),
            'user' => $this->auth->getCurrentUser()?->id
        ]);
    }
}
```

#### Applying Aspects

**Class-level aspects** apply to all methods in the controller:

```php
/**
 * @InterceptWith(RequireAuthAspect::class)
 * @InterceptWith(AuditLogAspect::class)
 */
class UserController extends BaseController {
    // All methods automatically get authentication and audit logging
    
    /**
     * @Route("/users")
     * @InterceptWith(CacheAspect::class, ttl=300)
     */
    public function index() {
        // Gets: RequireAuth + AuditLog (inherited) + Cache (method-level)
        return $this->em->findBy(User::class, ['active' => true]);
    }
}
```

**Method-level aspects** apply to specific methods:

```php
class BlogController extends BaseController {
    
    /**
     * @Route("/posts")
     * @InterceptWith(CacheAspect::class, ttl=600)
     * @InterceptWith(RateLimitAspect::class, limit=100, window=3600)
     */
    public function list() {
        // Method gets caching and rate limiting
        return $this->em->findBy(Post::class, ['published' => true]);
    }
}
```

#### Aspect Parameters

Pass configuration to aspects through annotation parameters:

```php
/**
 * @InterceptWith(CacheAspect::class, ttl=3600, tags={"reports", "admin"})
 * @InterceptWith(RateLimitAspect::class, limit=10, window=60)
 */
public function expensiveReport() {
    // Cached for 1 hour with tags, rate limited to 10 requests per minute
}
```

The aspect receives these as constructor parameters:

```php
class CacheAspect implements AroundAspect {
    public function __construct(
        private CacheInterface $cache,
        private int $ttl = 300,
        private array $tags = []
    ) {}
}
```

#### Execution Order

Aspects execute in a predictable order:
1. **Before Aspects** - Authentication, validation, rate limiting
2. **Around Aspects** - Caching, transactions, timing
3. **After Aspects** - Logging, response modification

#### Inherited Aspects

Build controller hierarchies with shared crosscutting concerns:

```php
/**
 * @InterceptWith(RequireAuthAspect::class)
 * @InterceptWith(AuditLogAspect::class)
 */
abstract class AuthenticatedController extends BaseController {
    // Base authenticated functionality
}

/**
 * @InterceptWith(RequireAdminAspect::class)
 * @InterceptWith(RateLimitAspect::class, limit=100)
 */
abstract class AdminController extends AuthenticatedController {
    // Admin-specific functionality - inherits auth + audit
}

class UserController extends AdminController {
    /**
     * @Route("/admin/users")
     */
    public function manage() {
        // Automatically inherits: RequireAuth + AuditLog + RequireAdmin + RateLimit
        return $this->em->findBy(User::class, []);
    }
}
```

### Contextual Services

Use different implementations based on context:

```php
// Different template engines
$twig = $this->container->for('twig')->get(TemplateEngineInterface::class);
$blade = $this->container->for('blade')->get(TemplateEngineInterface::class);

// Different cache backends
$redis = $this->container->for('redis')->get(CacheInterface::class);
$file = $this->container->for('file')->get(CacheInterface::class);
```

## CLI Commands

Canvas includes a command-line interface called Sculpt for managing your application:

### Route Management

```bash
# View all registered routes in your application
./vendor/bin/sculpt route:list
./vendor/bin/sculpt route:list --controller=UserController

# Test which controller and method handles a specific URL path
./vendor/bin/sculpt route:match /users/123
./vendor/bin/sculpt route:match GET /users/123

# Clear route cache
./vendor/bin/sculpt route:clear-cache
```

### Task Scheduler Management

```bash
# List all discovered tasks
./vendor/bin/sculpt schedule:list

# Run all due tasks
./vendor/bin/sculpt schedule:run
```

### Asset Publishing

Canvas provides a powerful asset publishing system to deploy configuration files, templates, and other resources:

```bash
# List all available publishers
./vendor/bin/sculpt canvas:publish --list

# Publish assets using a specific publisher
./vendor/bin/sculpt canvas:publish package:production

# Overwrite existing files
./vendor/bin/sculpt canvas:publish package:production --overwrite

# Skip confirmation prompts (for automated deployments)
./vendor/bin/sculpt canvas:publish package:production --force

# Show help for a specific publisher
./vendor/bin/sculpt canvas:publish package:production --help
```

## Why Canvas?

- **Legacy Integration**: Works with existing PHP without breaking anything
- **Zero Config**: Start coding immediately with sensible defaults
- **Clean Code**: Annotations keep logic close to implementation
- **Performance**: Lazy loading, route caching, efficient matching
- **Flexibility**: Contextual containers and composable aspects
- **Event-Driven**: Decoupled components with type-safe signal system
- **Task Scheduling**: Robust background job processing with multiple execution strategies
- **Growth**: Scales from simple sites to complex applications

## Contributing

We welcome contributions! Here's how you can help improve Canvas:

### Reporting Issues

- Use GitHub issues for bug reports and feature requests
- Include minimal reproduction cases
- Specify Canvas version and PHP version

### Contributing Code

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/amazing-feature`
3. Follow PSR-12 coding standards
4. Add tests for new functionality
5. Update documentation for new features
6. Submit a pull request

## License

Canvas is open-sourced software licensed under the MIT license.