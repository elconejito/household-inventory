# Implementation Roadmap

## Delivery strategy

Build the application as vertical slices. Each slice extends the Laravel API and Vue SPA together, includes authorization and validation, and ends with a user-visible workflow that can be exercised and tested. Shared infrastructure is introduced when the first slice needs it rather than by building speculative layers in advance.

The sequence prioritizes the core question—what do we have, how much, and where is it—before supporting features such as images and household invitations. Later slices may depend on earlier ones, but every completed slice should leave the application in a coherent state.

## Definition of done for every slice

A slice is complete when its applicable parts include:

- Database migration, Eloquent model relationships, factories, and representative seed data
- Household scoping, policies, FormRequests, transactions, and domain services where rules extend beyond simple persistence
- Controller, Spatie Query Builder configuration, Fractal transformer, and flat response serialization
- Vue API client, Vue Query keys and invalidation, route, components, loading/empty/error states, and responsive behavior
- Backend feature tests for success, validation, authorization, household isolation, and important conflicts
- Frontend component tests for meaningful interaction behavior
- A Playwright test when the slice adds or changes a critical end-to-end workflow
- Formatting, static analysis or type checking, and the relevant automated test suites passing
- Corresponding planning contracts updated when implementation reveals a necessary change

## Milestone 0: Application foundation

Establish the repository and conventions that every later slice uses.

### Deliverables

- Conventional Laravel application at the repository root
- Vue 3 and TypeScript SPA under `resources`
- Vite, Vue Router, Axios, TanStack Vue Query, Pinia, and Tailwind configuration at the repository root
- MySQL environment configuration and an example environment file with no secrets
- Laravel Sanctum stateful SPA authentication baseline
- PHPUnit, Vitest with Vue Test Utils, and Playwright test harnesses
- Code-formatting and type-checking commands for PHP and TypeScript
- SPA fallback route separated cleanly from unversioned `/api` routes
- Base application shell, route-level error handling, and shared HTTP error normalization
- Custom Fractal flat serializer and a small transformer test proving the agreed envelope and field shape
- Global validation and exception rendering proving the agreed `errors` array shape

### Exit check

A user can load the Vue shell, an authenticated API request succeeds, an unauthenticated request receives the expected error response, and automated smoke tests run for both stacks.

## Milestone 1: Household and catalog

Deliver authentication plus the descriptive records needed before quantities can be tracked.

### Deliverables

- Registration creates a user, household, and owner membership transactionally
- Login, logout, current-session loading, and authenticated application shell
- Household-scoped Items, Categories, and Locations
- Item-to-category assignment with multiple equal categories and no primary category
- Parent/child location hierarchy with cycle protection and sibling-scoped name uniqueness
- Every location, including a parent, is selectable as an exact storage location
- Item, category, and location indexes, detail screens, create/edit flows, search, filtering, sorting, pagination, and explicit includes
- Soft deletion, restoration, archive views, and the agreed archive guards for records introduced in this milestone
- Descriptions for Items and Locations

### Exit check

An owner can create `Basement`, create child locations beneath it, create and categorize an item, find it through the item index, and browse the location hierarchy. Two households cannot observe or mutate one another's records.

## Milestone 2: Inventory ledger and current balances

Make quantities operational through immutable movements rather than direct edits.

### Deliverables

- Inventory Levels, Inventory Movements, and Movement Entries
- `MovementType` backed enum and movement validation
- Transactional, row-locked movement service for restock, consumption, transfer, correction, and disposal
- Missing-level behavior for restock, transfer destination, and positive correction
- Negative-stock prevention and transfer source limits
- Inventory-level alert-threshold editing without quantity editing
- Item detail inventory-by-location section with Consume, Restock, and Transfer actions
- Focused Correction and Disposal workflows
- Push and pull transfer wording with the same transfer API payload
- Location detail with `All within` and `Directly here` scopes, exact location paths, and direct `Restock here`
- Activity timeline and from/to/either location filtering

### Exit check

The toilet-paper scenario works end to end: stock can exist directly in `Basement`, move to `Main pantry`, move again to a bathroom, and be consumed without any balance becoming negative. Activity shows one immutable transfer with both source and destination effects.

## Milestone 3: Dashboard, search, and attention states

Deliver the application's main daily-use surface.

### Deliverables

- Computed empty, low, okay, and unmonitored inventory-level states
- Persisted item-wide `buy_soon` alerts with explicit resolution and duplicate prevention
- Dashboard automatic-condition queries and active manual-alert query
- Search-first dashboard item finder with typeahead results
- Result rows showing item total, location breakdown, and Consume, Restock, Buy soon/status, and View actions
- Exact-location picker for dashboard consumption and restocking; consumption sources limited to positive balances
- Out of stock, Low stock, and Buy soon dashboard groups
- Appropriate query invalidation after movements, threshold changes, and alert changes

### Exit check

A user can open the dashboard, find an item without browsing the full inventory, act on a result row, and see affected totals, conditions, and activity update without a page reload.

## Milestone 4: Notes and item images

Add the supporting context needed for less self-explanatory supplies.

### Deliverables

- Polymorphic Notes for Items, Categories, Locations, Inventory Movements, and Inventory Alerts
- Plain-text note entry and editing with preserved line breaks, author, timestamps, edited state, newest-first order, and soft deletion
- Item-specific multiple images with exactly one active primary image
- JPEG, PNG, and WebP validation up to 20 MB; HEIC/HEIF support deferred to a future version
- Orientation normalization, metadata removal, and two WebP derivatives only: 320×320 thumbnail and 1920×1080 display bounds
- Configurable private `inventory-images` disk using local storage initially
- Authenticated image delivery routes exposing URLs rather than storage paths
- Primary-image display near item identity, thumbnail use in lists where available, image management, and automatic primary promotion

### Exit check

A user can identify an item from its primary image, inspect its larger display image, manage additional images, and add consistent notes to every supported notable model. No original upload or metadata remains after processing.

## Milestone 5: Membership, invitations, and destructive lifecycle

Complete household administration after the inventory workflows are stable.

### Deliverables

- Owner and member policy distinctions
- Owner-only household and membership settings
- Invitation creation, copyable one-time link, resend, revocation, expiration, and acceptance; outbound email delivery is deferred
- Early invite conflicts for current-household members and accounts belonging to another household
- Final-owner leave and demotion protection
- Owner-only permanent deletion after prior soft deletion
- Dependency checks, confirmation language, and clear conflict responses for permanent deletion
- Cleanup of owned image files when an image or item is permanently deleted

### Exit check

An owner can invite a new member and that member can manage inventory, while owner-only administration remains protected. Permanent deletion cannot bypass archival or leave orphaned records or files.

## Milestone 6: Release hardening

Prepare the complete 1.0 behavior for real household use.

### Deliverables

- Responsive and keyboard-accessibility pass across the application
- Loading, empty, validation, conflict, offline/network-failure, and retry states reviewed consistently
- Query-count and pagination review on dashboard, item, location, category, and activity screens
- Concurrency tests for movements, primary-image promotion, invitations, final-owner protection, and archive guards
- Complete Playwright coverage of the critical happy paths and highest-risk conflicts
- Production configuration checklist for MySQL, sessions, queues if enabled, image processing, private storage, mail if enabled, backups, and HTTPS
- Seed/demo data capable of exercising the toilet-paper movement chain and alert behavior
- Final reconciliation of the domain, schema, API, frontend, and technical documents with the built application

### Exit check

All 1.0 acceptance workflows pass in a production-like environment, no known high-risk data-integrity issue remains, and deployment requirements are documented.

## Critical end-to-end workflows

These receive Playwright coverage as soon as the responsible slices exist:

1. Register, create an item, assign categories, and find it in inventory.
2. Create a parent and child location, then store inventory directly in either one.
3. Restock, transfer, consume, correct, and dispose while preserving the immutable ledger and non-negative balances.
4. Search from the dashboard and consume from an explicitly selected positive-stock location.
5. Configure a threshold, trigger and clear an automatic condition, create and resolve `buy_soon`.
6. Upload an item image and confirm thumbnail, primary display, promotion, and protected delivery.
7. Invite a member and verify member versus owner capabilities.
8. Archive, restore, and permanently delete eligible records while blocked records return useful conflicts.

## Current delivery status

The agreed 1.0 feature work is complete. The remaining release work is hardening and verification rather than additional product features. HEIC/HEIF support and outbound invitation email remain deferred. Local automated suites cover the implemented workflows, but final production-like acceptance still requires a chosen hosting target, HTTPS configuration, persistent private image storage, and verified backups. The [Technical Architecture](technical-architecture.md#production-readiness-checklist) contains the deployment checklist; no production deployment has been performed.
