# Computers for Charities (D3R Framework) - Agent Guidelines

You are an expert PHP web programmer for this project

## Role
- Maintain the YZE-Roller-API project using the FlightPHP framework (https://docs.flightphp.com/en/v3/)
- Prefer small, targeted changes and update only what the task needs

## Project Knowledge

### Tech Stack
- PHP 8.4+ (Composer platform), PSR-12 standards
- Nginx web server

### Framework
- Flightphp is used as the core framework
- The flightphp/container package is being used as a PSR-11 compatible container
- Database connectivity is provided by `flight\Database\SimplePdo`

### Technical Knowledge
- Some classes have specific knowledge stored in `docs/knowledge`
- Always check this directory for codebase knowledge when planning code changes

### Project Memory
If the project-memory skill is not available, read `.agents/skills/project-memory/SKILL.md`.
Follow these guidelines once you have read that file or if the project-memory skill is available.

- When investigating, analyzing or reviewing the project use the project-memory skill to persist discovered project knowledge with brief memory entries
- When working on the project use the project-memory skill to recall relevant project context before making changes
- Also when making concrete decisions about the codebase, write a brief memory (decision, rationale and pointers)

### File Structure
- `config/` App config (config.php, app.php, routes.php, acl.xml)
- `scripts/` CLI scripts (samplescript.php template)
- `src/` Namespaced App\* code (providers, controllers, models, views)
- `tests/` PHPUnit tests
- `docs` Documentation, plans and task tracking
- `web/` built assets and public web root.
- `web/index.php` request entry point, loads Composer autoload and initialises FlightPHP

## Commands you can use

```bash
# Dependencies
composer install

# Run tests
php vendor/bin/phpunit --configuration phpunit.xml.dist
php vendor/bin/phpcs --standard=.phpcs.xml.dist
```

## Coding Standards
- Always start files with `declare(strict_types=1);`
- PHP follows PSR-12
- Prefer meaningful, descriptive variable, function, class, and file names.
- Structure projects with proper namespaces reflecting directory hierarchy.
- Avoid global functions and variables unless absolutely necessary.
- Use **type hints** and **return types** for all functions and methods.
- Example PHP style:
```php
<?php

declare(strict_types=1);

namespace App\Example;

final class ExampleService
{
    public function run(): void
    {
    }
}
```

## When adding code

**New controller/routes:**
1. Add to index.php in the `api` route namespace

## Git Workflow
- Avoid touching `web/` directly unless it is a build output for a changed source.
- Do not edit `vendor/`; update dependencies via Composer and commit lockfiles when they change.

## Boundaries
✅ **Always:**
- Run unit tests to verify that they pass
- Follow existing patterns in `src/`

⚠️ **Ask first:**
- Adding new Composer dependencies
- Cron changes
- Config changes under `config/`

🚫 **Never:**
- Commit secrets, API keys, `.env.config.ini` files or anything else specified in `.gitignore`
- Modify `vendor/*` packages directly
- Remove any `.gitignore` or `.gitkeep` placeholder files
