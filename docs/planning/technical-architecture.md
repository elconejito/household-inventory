# Technical Architecture

## Repository shape

The repository root is a conventional Laravel application. The Vue SPA lives under `resources`, and project-level PHP, JavaScript, Vite, linting, formatting, and test configuration remains at the repository root.

Laravel serves the SPA shell for non-API application routes and reserves `/api/*` for the unversioned JSON API. The application does not use ChatGPT Sites, Inertia, or server-side rendering.

## Backend baseline

- Laravel JSON API backed by MySQL
- Laravel Sanctum stateful cookie authentication for the first-party SPA
- Policies and household-scoped queries for authorization
- FormRequests for request validation
- Fractal transformers with a custom flat serializer
- Spatie Laravel Query Builder for consistent includes, filters, sorting, sparse fieldsets, and pagination
- Laravel filesystem abstraction for item images

Domain discriminator columns use names that identify their context, such as `movement_type`, rather than a generic `type`. Laravel models cast these string columns to PHP-backed enums and FormRequests validate them with the corresponding enum class. Database-native MySQL `ENUM` columns are not used, keeping schema changes and a possible future lookup-table migration straightforward.

### Image processing

Item-image uploads accept JPEG, PNG, and WebP up to 20 MB in 1.0. HEIC/HEIF support is deferred to a future version. SVG and animated images are rejected. The processor applies embedded orientation, strips metadata including GPS data, and produces two non-upscaled, aspect-preserving WebP derivatives at an initial quality setting of approximately 85:

- Thumbnail: maximum 320 by 320 pixels
- Display: maximum 1920 by 1080 pixels

Transparency is preserved when present. The upload source is discarded after both derivatives are stored. HEIC/HEIF uploads return a clear validation error directing the user to export an accepted format rather than retaining an unprocessed original. Adding these formats later requires verifying a compatible decoder in both local and production runtimes; no additional decoder is required for 1.0.

The `inventory-images` disk is private. The API exposes stable authenticated thumbnail and display routes and never exposes storage paths. With local storage Laravel streams the authorized file with private caching headers. Uploads reject public disks and local roots exposed through the document root or configured storage links; both derivatives are explicitly written with private visibility. After a future migration to S3, the same application routes may redirect to short-lived signed URLs without changing the resource contract or Vue components. The S3 adapter is not currently installed; changing the disk environment variable alone is not a supported migration.

Framework and package versions are locked in `composer.lock` and `package-lock.json`. Release builds install from those lockfiles rather than updating dependencies.

## Frontend baseline

- Vue 3 with TypeScript
- Composition API and `<script setup>` single-file components
- Vue Router for client-side routing
- Axios as the Sanctum-aware HTTP client
- TanStack Vue Query for remote server state
- Pinia only for client-owned global state
- Tailwind CSS for responsive styling
- Vite through Laravel's root build configuration
- Vitest and Vue Test Utils for unit and component tests
- Playwright for critical browser workflows

### State ownership

```text
Laravel API data  -> TanStack Vue Query
Shared client state -> Pinia
Local form state  -> Vue components and composables
```

Vue Query keys and invalidation behavior are centralized by resource domain. Components do not invent unrelated cache keys. For example, creating a consumption movement invalidates the affected item, inventory level, relevant item/location lists, dashboard condition query, and activity query.

Axios remains responsible for HTTP and the API's JSON envelope. Vue Query wraps the resulting promises to provide request lifecycle state, caching, pagination continuity, and controlled refetching; it does not replace the API client.

Pinia does not duplicate collections already owned by Vue Query. Its initial responsibilities are limited to authenticated-session data and durable client preferences that do not originate as API resources.

## Component foundation

The SPA uses Tailwind CSS and a small application-owned component layer rather than a pre-themed or headless UI framework. Shared components include consistent buttons, fields, tables and responsive lists, status badges, dialogs, menus, pagination, empty states, and feedback messages.

Components prefer semantic HTML and native browser behavior. Native controls are used when they meet the interaction requirement. Custom behavior-heavy controls, including searchable item and location pickers, are implemented deliberately with keyboard interaction, focus management, labeling, and ARIA behavior covered by component tests. Reka UI is explicitly not part of the stack.

## Visual direction

- Calm, practical, household-oriented presentation rather than an enterprise-admin aesthetic
- Warm neutral surfaces and strong text contrast
- Compact, scan-friendly data presentation with comfortable touch targets
- Moderately squared component geometry, using approximately 10px corner radii by default rather than heavily rounded or pillowy surfaces
- Red reserved for empty and destructive states
- Amber for low stock
- A distinct blue or violet treatment for manual `buy_soon` alerts
- Text labels accompanying unfamiliar icons
- Light theme in 1.0, with dark mode deferred

## Production-readiness checklist

This is a checklist for the eventual deployment target, not evidence that production infrastructure has been provisioned or verified. No hosting provider, public domain, backup service, or deployment pipeline is assumed.

### Runtime and secrets

- Use PHP 8.4 with Laravel's required extensions, PDO MySQL, and GD with JPEG, PNG, and WebP support. Enable Exif for JPEG orientation handling. Confirm processing works in the web runtime as well as the CLI.
- Set `APP_ENV=production`, `APP_DEBUG=false`, and `APP_URL` to the canonical HTTPS application URL. Invitation links use that configured URL rather than the incoming request host.
- Provision a strong `APP_KEY` once and retain it securely across releases. Do not routinely regenerate it during deployments. Keep environment values, credentials, private files, and logs outside the public document root and out of source control.
- Serve only Laravel's `public` directory. Configure HTTPS, accepted hostnames, and any trusted proxies on the chosen ingress; never broadly trust forwarded headers without knowing the proxy topology.
- Set `SESSION_SECURE_COOKIE=true`; retain HTTP-only session cookies and the default `lax` SameSite policy. Configure Sanctum's stateful domains for the actual SPA origin. Avoid array-backed sessions or rate-limit caches in production.

### Durable data and images

- Use a persistent MySQL database and least-privilege application credentials. Review migrations and arrange a recoverable backup before applying them to production. Never run test migrations against the household's real database.
- Keep the configured session and cache stores available across requests. Database-backed defaults are supported; multiple instances must share session and rate-limit state.
- Launch with the private `inventory-images` local disk on persistent storage. A single-instance setup needs a durable volume across releases; multiple instances need a shared durable mount or an explicitly implemented private object-storage migration. Ephemeral container filesystems are not sufficient.
- Do not expose the image root through web-server aliases or storage links. Uploaded source files are discarded; include both WebP derivatives in backups alongside the database. Verify a restore preserves the references and authenticated delivery.
- Configure PHP and ingress upload limits to accept a 20 MB file plus multipart overhead, with `post_max_size` larger than `upload_max_filesize`. Allow sufficient memory for the processor's 40-megapixel limit and test representative large images on the target runtime. HEIC/HEIF remain deferred.
- S3 is a future enhancement requiring the Flysystem adapter, credentials, private bucket access, and delivery/cleanup verification. It is not part of the local-disk launch baseline.

### Release and verification

- Build from lockfiles: `composer install --no-dev --prefer-dist --optimize-autoloader`, plus `npm ci` and `npm run build` in the build stage. Do not use `composer setup` against production: it is a development bootstrap script that generates a key and applies migrations.
- Once the target environment is configured, run reviewed migrations and rebuild Laravel's configuration, route, and view caches. Give the runtime write access to `storage` and `bootstrap/cache` without making those directories public. See [Laravel deployment guidance](https://laravel.com/docs/13.x/deployment).
- Run PHPUnit, including the opt-in MySQL concurrency tests, only against the dedicated local `household_inventory_test` database or isolated test databases. Clear the development configuration cache before test runs; the shared test case rejects cached configuration before test database refreshes can run.
- Run frontend type checking, component tests, the production asset build, and Playwright's real-API workflows before release. The browser harness uses an explicit testing environment and canonical loopback origin; it must not reuse an application server connected to real household data.
- Run Composer and npm dependency audits at release time. A clean audit is a point-in-time dependency check, not a substitute for application authorization tests. No repository CI pipeline is currently configured.
- Perform staging smoke checks for login/logout and CSRF, owner/member isolation, inventory movements and conflicts, copied invitation links, image upload and protected delivery, and archive/restore/permanent deletion over HTTPS. Verify backups, restore procedure, error logging, and health checks on the chosen target before calling the deployment ready.
- No application queue worker, scheduler, or outbound invitation email is required by the implemented 1.0 workflows. Invitations are shared through copyable links; workers and mail configuration become requirements only if those future workflows are implemented.

## Implementation status

The agreed 1.0 feature work is complete, including household administration, inventory workflows, notes, images, and destructive lifecycle protections. Release hardening and local automated verification are underway. HEIC/HEIF remain explicitly deferred. See [Implementation Roadmap](implementation-roadmap.md). Production deployment and target-specific verification require a separate hosting decision.
