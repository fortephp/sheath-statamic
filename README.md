# Sheath Statamic

Catch broken Statamic tags and templates before they reach production.

This [Sheath](https://github.com/fortephp/sheath) plugin checks Statamic tags wherever you use them: Blade components, fluent tag calls, or `@tags` definitions. It also understands Antlers embedded in Blade, and reports problems without rendering views or running application tags.

## Installation

```bash
composer require --dev fortephp/sheath-statamic
```

Laravel discovers the package automatically. Add its preset to `config/sheath.php`:

```php
'preset' => ['recommended', 'statamic'],
```

Run Sheath normally:

```bash
php artisan sheath:lint
```

You can also try the plugin without changing your config:

```bash
php artisan sheath:lint --preset=statamic
```

## What it catches

- unknown Statamic tags and methods across component, fluent, and `@tags` syntax;
- missing required parameters and paired content;
- missing partials, collections, taxonomies, forms, asset containers, and other literal project resources;
- incompatible pagination, chunking, limiting, and collection-navigation options;
- malformed `@antlers` regions and invalid Antlers expressions;
- misplaced `slot` and `no_results` tags, and malformed Statamic Blade directives;
- executable `dump`, `dd`, and `ddd` tags or modifiers left in templates.

Errors are reported in your Blade or Antlers source rather than generated compiler output, so they are easier to find and fix.

The plugin reads tags, modifiers, project resources, views, and add-ons from your Statamic application. Custom and wildcard tags work automatically. When a dynamic value cannot be checked reliably, the plugin leaves it alone instead of guessing.

## Configuration

The `statamic` preset enables all 19 rules at error severity. You can adjust individual rules in `config/sheath.php`:

```php
'rules' => [
    'statamic-no-debug-tags' => 'warning',
    'statamic-partial-exists' => 'off',
    'statamic-resource-exists' => 'error',
],
```

Use `php artisan sheath:lint --print-config` to see every active rule and severity. The rules do not offer automatic fixes because the right change depends on what the template should do.

## Requirements

- PHP 8.2 or newer
- Statamic 6.x
- Sheath 1.x

## License

MIT. See [LICENSE.md](LICENSE.md).
