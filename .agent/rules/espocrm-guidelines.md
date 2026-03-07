---
trigger: always_on
---

# EspoCRM Google Workspace Extension AI Rules

These rules dictate the behavior and expectations when assisting with the `espocrm-ext-google-workspace` project.

## 1. Project Context
- **Framework**: EspoCRM v9.x (PHP 8.3+)
- **Architecture**: EspoCRM Extension Module.
- **Location**: All source code strictly resides inside `src/files/custom/Espo/Modules/GoogleWorkspace/`. Tests reside in `tests/`.
- **Packaging**: Built using Node.js build system (`npm run extension`). Do not modify core EspoCRM files outside the `src/files` namespace.
- **Source Code Context**: If you need to read or search the original EspoCRM core codebase to understand an API, run `npm run prepare-test`. This will generate a `site/` directory containing the full internal EspoCRM application. Use this folder strictly for reference and **never** modify its internal contents.

## 2. strict typing & PHPStan
- The project adheres to **PHPStan Level 8**.
- Strict type hinting is mandatory for all method parameters and return types.
- Array shapes and iterables must be typed (e.g., `@param array<string, mixed>`, `@return string[]`).
- Avoid `mixed` where a clear type exists. Handle all nullable variables before using them (e.g., using `?? ''` or `if ($var === null)`).

## 3. ORM & Database Rules
- Never use direct SQL queries. Rely on EspoCRM's `EntityManager`.
- When fetching repositories dynamically, use `$entityManager->getRDBRepository(Entity::ENTITY_TYPE)` rather than the generic `$entityManager->getRepository()` to prevent PHPStan errors regarding missing builder methods.
- For finding entities by conditions, prefer `$repository->where(['field' => 'value'])->findOne()`.
- Access properties safely using `$entity->get('propertyName')` instead of `$entity->propertyName` if the property relies on magic get/set methods. Conversely, if it is a defined public property with type hints from the SDK responses (like Google APIs object properties), check for existence/nullability.

## 4. Testing (PHPUnit)
- We maintain a **+90% code coverage** via PHPUnit. Always write Unit Tests when implementing new logic. 
- Mocks strictly map exactly EspoCRM's RDB Architecture.
  - Mock `RDBRepository` when returning repositories.
  - The `->where()` method of an `RDBRepository` always returns a mocked `RDBSelectBuilder`, which then returns the single entity mock on `->findOne()` or `EntityCollection` on `->find()`.
  - When simulating related queries (e.g. `team->has('users')`), mock `RDBRelation`.
- Use `Partial Mocking` instead of testing protected APIs containing outgoing network requests (cURL).

## 5. Metadata & Translations
- **Schema First**: Any new feature or configuration setting (`Config`) must first be declared in the schema files inside `Resources/metadata/entityDefs/Settings.json`.
- **UI Layouts**: To expose a setting to the Administrator, you must also add its definition and visibility conditions inside `Resources/metadata/authenticationMethods/GoogleWorkspace.json`. Ensure that the `layout` order is sensible, grouping related settings together visually.
- **No Hardcoded Strings**: Never hardcode user-facing strings in PHP or JS. Always place text mappings inside `Resources/i18n/en_US/Settings.json` (and `it_IT/Settings.json`), then reference them dynamically.

## 6. Documentation
- **README Updates**: Whenever a new feature is added, an existing feature is modified, or a configuration option is changed, you must ALWAYS update the `README.md` to reflect these changes accurately. Keep the feature list, settings instructions, and any technical setup steps (like Google Cloud configuration) up-to-date with your code changes.

## 7. Backend Best Practices & Performance
- **Constructor Property Promotion**: As the framework uses PHP 8.3+, every new class (Services, Jobs, Controllers) using Dependency Injection MUST utilize Constructor Property Promotion. Avoid declaring class properties and assigning them inside the constructor body.
- **Zero Dependencies & ext-curl**: To keep the architecture lightweight and aligned with EspoCRM core standards, do not install external HTTP clients (like GuzzleHTTP). Rely exclusively on vanilla `ext-curl` and native Espo utilities (`Espo\Core\Utils\Json`, `Config`, `Log`).
- **Generators for Pagination**: When fetching complex or paginated datasets from external APIs, heavily favor PHP Generators (`yield`) instead of populating large temporary arrays in RAM with `while` loops.

## 8. Frontend Guidelines (ECMAScript)
- **Modern JS (ES6+)**: When modifying frontend EspoCRM code (Client-side JS/Backbone):
  - Avoid manual string concatenations for URL query parameters; use `URLSearchParams`.
  - Avoid long syntax chains of `.then() / .catch()` with callbacks. Utilize native asynchronous `async / await` constructs paired with `try / catch` to elevate readability.

## 9. Build Commands
Always verify your changes before submitting:
- `npm run sa`: Executes PHPStan static analysis. It must report "No errors".
- `npm run unit-tests`: Executes the PHPUnit test suite.
- `npm run extension`: Transpiles the code and bundles the installable `.zip` archive to `build/`.
