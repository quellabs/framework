# Canvas Application Skeleton

A PHP application skeleton for the [Quellabs Canvas Framework](https://github.com/quellabs/canvas).

## Quick Start

Create a new Canvas application using Composer:

```bash
composer create-project quellabs/canvas-skeleton my-app
cd my-app
```

That's it! The skeleton will automatically:
- Set up the complete directory structure
- Copy configuration files
- Install all dependencies
- Configure proper permissions

## What's Included

### Directory Structure
```
my-app/
├── src/                   # Application logic
│   ├── Controllers/       # Request controllers
│   └── Aspects/           # Aspect-Oriented Programming aspects
├── config/                # Configuration files
├── public/                # Web server document root
│   ├── index.php          # Application entry point
│   └── .htaccess          # Apache URL rewriting
├── templates/             # Views
└── storage/               # Application storage
```

### Pre-configured Features
- **URL Rewriting**: Clean URLs with Apache `.htaccess`
- **Environment Configuration**: `.env` file for application settings
- **Autoloading**: PSR-4 autoloading ready for your application classes
- **Directory Structure**: Organized folders for a clean project layout

## Getting Started

### 1. Configure Your Environment

Edit the `config/database.php` file to match your setup.

### 2. Start the Development Server

```bash
php -S localhost:8000 -t public
```

Visit [http://localhost:8000](http://localhost:8000) to see your application running.

### 3. Start Building

Create your application logic in the `src/` directory and controllers in the `src/Controllers/` directory according to the Canvas framework documentation.

## Requirements

- **PHP**: 8.3 or higher
- **Composer**: For dependency management
- **Web Server**: Apache (with mod_rewrite), Litespeed or Nginx

## Framework Documentation

For detailed documentation about the Canvas framework features:

- [Canvas Framework Repository](https://github.com/quellabs/canvas)
- [Canvas Documentation](https://canvas.quellabs.com/docs) *(coming soon)*

## Development

Refer to the [Canvas Framework documentation](https://github.com/quellabs/canvas) for details on:
- Creating controllers
- Defining routes
- Working with views
- Database integration
- Testing your application

### Optional Canvas Packages

Enhance your Canvas application with these optional packages:

**Templating:**
```bash
composer require quellabs/canvas-smarty
```
Adds Smarty templating engine support for powerful template rendering.

**ORM:**
```bash
composer require quellabs/canvas-objectquel
```
Provides ObjectQuel ORM for elegant database interactions and object-relational mapping.

## Deployment

### Apache Configuration

The included `.htaccess` file handles URL rewriting for Apache. Ensure `mod_rewrite` is enabled.

### Nginx Configuration

For Nginx, add this to your server block:

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

### Production Environment

1. Update your database DSN for production
2. Set proper file permissions:
   ```bash
   chmod -R 755 storage/
   chown -R www-data:www-data storage/
   ```

## Contributing

This skeleton is maintained by the Canvas team. If you find issues or have suggestions:

1. Check the [Canvas Framework Issues](https://github.com/quellabs/canvas/issues)
2. Submit issues to the [Canvas Skeleton Repository](https://github.com/quellabs/canvas-skeleton/issues)

## License

The Canvas Application Skeleton is open-sourced software licensed under the [MIT license](LICENSE).

## Support

- **Documentation**: [Canvas Docs](https://canvas.quellabs.com/docs)
- **Issues**: [GitHub Issues](https://github.com/quellabs/canvas-skeleton/issues)
- **Discussions**: [GitHub Discussions](https://github.com/quellabs/canvas/discussions)