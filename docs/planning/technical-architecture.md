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

Item-image uploads accept JPEG, PNG, WebP, and HEIC/HEIF up to 20 MB. SVG and animated images are rejected. The processor applies embedded orientation, strips metadata including GPS data, and produces two non-upscaled, aspect-preserving WebP derivatives at an initial quality setting of approximately 85:

- Thumbnail: maximum 320 by 320 pixels
- Display: maximum 1920 by 1080 pixels

Transparency is preserved when present. The upload source is discarded after both derivatives are stored. HEIC/HEIF decoding support must be verified in the selected local and production image-processing runtime before launch; an unsupported decode returns a clear validation error rather than retaining an unprocessed original.

The `inventory-images` disk is private. The API exposes stable authenticated thumbnail and display routes and never exposes storage paths. With local storage Laravel streams the authorized file with private caching headers. After migration to S3, the same application routes may redirect to short-lived signed URLs without changing the resource contract or Vue components.

Exact framework and package versions will be selected and locked when the application is scaffolded.

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

## Planning status

The household invitation lifecycle, core screen behavior, wireframe direction, and phased implementation sequence are settled. See [Implementation Roadmap](implementation-roadmap.md). The application is ready for Milestone 0 scaffolding.
