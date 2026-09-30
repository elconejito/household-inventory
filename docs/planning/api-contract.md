# Household Inventory: API Contract

Status: Planning draft  
Last updated: 2026-08-29

## Response philosophy

The API uses a deliberately simplified, JSON:API-inspired representation. It retains a consistent top-level `data` envelope and resource identity through `type` and `id`, but it does not use JSON:API's `attributes`, `relationships`, linkage, or top-level `included` structures.

Fractal transformers return flat resource objects. A custom Fractal serializer provides the top-level envelope while embedding requested nested resources directly and preventing nested `data` wrappers.

## Top-level data envelope

A singular resource is returned as an object:

```json
{
  "data": {
    "type": "items",
    "id": "42",
    "name": "Toilet paper"
  }
}
```

A resource collection is returned as an array:

```json
{
  "data": [
    {
      "type": "items",
      "id": "42",
      "name": "Toilet paper"
    },
    {
      "type": "items",
      "id": "43",
      "name": "Facial tissue"
    }
  ]
}
```

An empty collection returns an empty array:

```json
{
  "data": []
}
```

## Flat resource objects

Every transformed model is represented by a flat resource object:

- `type` identifies the resource type.
- `id` is serialized as a string.
- Scalar and calculated fields are siblings of `type` and `id`.
- Requested related resources are embedded as sibling objects or arrays.
- The representation never adds `attributes` or `relationships` properties.
- Embedded resources never add another `data` wrapper.

Example item with embedded relationships:

```json
{
  "data": {
    "type": "items",
    "id": "42",
    "name": "Toilet paper",
    "counting_unit": "roll",
    "total_quantity": 179,
    "categories": [
      {
        "type": "categories",
        "id": "3",
        "name": "Paper goods"
      }
    ],
    "inventory_levels": [
      {
        "type": "inventory-levels",
        "id": "81",
        "quantity": 120,
        "alert_threshold": 30,
        "location": {
          "type": "locations",
          "id": "7",
          "name": "Basement"
        }
      }
    ]
  }
}
```

## Collection metadata

Collection pagination metadata and navigation links are top-level siblings of `data`:

```json
{
  "data": [],
  "links": {
    "first": "/api/items?page=1&per_page=25",
    "last": "/api/items?page=4&per_page=25",
    "prev": null,
    "next": "/api/items?page=2&per_page=25"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 4,
    "per_page": 25,
    "to": 25,
    "total": 87
  }
}
```

Pagination requests use Laravel-style parameters:

```http
GET /api/items?page=2&per_page=25
```

Rules:

- `page` defaults to `1`.
- `per_page` defaults to `10`.
- Allowed `per_page` values are `10`, `25`, `50`, and `100`.
- `100` is the maximum page size.
- Unsupported page sizes fail validation rather than being silently coerced.

Paginated collections include only these navigation links:

- `first`
- `last`
- `prev`
- `next`

Pagination metadata includes only:

- `current_page`
- `from`
- `last_page`
- `per_page`
- `to`
- `total`

Laravel's numbered pagination-link collection is not included in API responses.

## Current response-layer decisions

- API resources use the unversioned `/api/...` namespace.
- JSON property names use `snake_case`, matching the Laravel API vocabulary.
- Resource type names use kebab-case plural names, such as `inventory-levels`.
- Resource IDs are serialized as strings.
- Singular responses use an object under `data`.
- Collection responses use an array under `data`.
- Empty collections use an empty array.
- Scalar attributes and embedded relationships share the same flat resource namespace.
- Fractal controls transformed output; Eloquent models are not serialized directly.
- Without a `fields` parameter, each resource returns the complete scalar field set defined by its Fractal transformer, not every column defined by its Eloquent model.
- The frontend may request a smaller scalar field set when useful.
- Spatie Laravel Query Builder controls which allowed relationships are eager-loaded for optional inclusion.
- No collection or singular endpoint includes optional relationships by default.
- The frontend explicitly requests every relationship needed for a particular view.
- Relationships not requested through `include` are omitted from the resource object.
- A requested to-many relationship with no records is represented by an empty array.
- A requested to-one relationship with no related record is represented by `null`.

## Relationship presence

An unrequested relationship is omitted:

```json
{
  "type": "items",
  "id": "42",
  "name": "Toilet paper"
}
```

A requested but empty to-many relationship is explicit:

```json
{
  "type": "items",
  "id": "42",
  "name": "Toilet paper",
  "categories": []
}
```

A requested but absent to-one relationship is explicit:

```json
{
  "type": "inventory-levels",
  "id": "81",
  "location": null
}
```

## Includes

All optional relationships are requested explicitly:

```http
GET /api/items/42?include=categories,inventory_levels.location,images
```

Rules:

- Include names use the same `snake_case` names exposed in resource objects.
- Commas separate sibling includes.
- Dot notation requests nested includes.
- Requesting a nested include also embeds its parent relationship.
- Every endpoint maintains an explicit allowlist of supported includes.
- Unsupported includes fail with a client error rather than being ignored.
- Vue API modules define the includes needed by each view; Vue components do not assemble include strings directly.

## Sparse fieldsets

Sparse fieldsets are optional. Omitting `fields` returns every scalar field exposed by the resource transformer:

```http
GET /api/items
```

The frontend may request a smaller scalar representation:

```http
GET /api/items?fields[items]=name,total_quantity
```

Included resource types may have their own fieldsets:

```http
GET /api/items?include=categories&fields[items]=name&fields[categories]=name
```

Rules:

- Fractal transformers define the complete and allowed public field set for each resource type.
- Each transformer also defines a minimum field set that is always returned for that resource type.
- A sparse fieldset is combined with the transformer's minimum field set; it cannot suppress minimum fields.
- `fields` controls scalar and calculated resource fields only.
- `include` exclusively controls relationship presence; a fieldset never implicitly includes or excludes a relationship.
- Unknown or disallowed fields fail with a client error rather than being ignored.
- Database column selection may be optimized when safe, but the public fieldset is an output contract and is not defined by raw Eloquent columns.

For example, the item transformer has a minimum field set of `type`, `id`, and `name`. A request for only `total_quantity`:

```http
GET /api/items?fields[items]=total_quantity
```

still returns the minimum fields plus the requested field:

```json
{
  "data": [
    {
      "type": "items",
      "id": "42",
      "name": "Toilet paper",
      "total_quantity": 179
    }
  ]
}
```

Requesting a minimum field explicitly is harmless and does not duplicate it. Requesting a valid optional field adds it to the minimum representation. Requesting an unknown or forbidden field fails rather than silently falling back to the minimum.

## Error responses

All API failures use a top-level `errors` array, including failures that contain only one error:

```json
{
  "errors": [
    {
      "status": "422",
      "code": "validation_failed",
      "title": "Validation failed",
      "detail": "The name field is required.",
      "source": {
        "pointer": "/data/name"
      }
    }
  ]
}
```

Responsibilities are separated as follows:

- FormRequest classes define authorization, validation rules, attribute names, and validation messages.
- Failed FormRequests throw Laravel's normal `ValidationException` and retain the standard 422 status.
- The application exception renderer in `bootstrap/app.php` owns the API error representation.
- Individual FormRequests do not override failure rendering.
- The same renderer converts authentication, authorization, missing-model, HTTP, query-builder, and domain exceptions into the shared envelope.

The renderer maps common exceptions to appropriate statuses:

- validation: `422`
- unauthenticated: `401`
- unauthorized: `403`
- not found: `404`
- invalid include, field, filter, sort, or pagination parameter: `400` unless it fails an explicit FormRequest validation rule and is therefore `422`
- domain-state conflict: `409`
- rate limited: `429`
- unexpected server failure: `500`, without exposing implementation details

Each error object contains:

- `status`, serialized as a string
- stable machine-readable `code`
- short `title`
- human-readable `detail`
- optional `source.pointer` for request-body fields
- optional `source.parameter` for query parameters

Multiple validation messages produce multiple entries in `errors`, allowing the frontend to associate each error with its field while still presenting a response-level summary.

## Request data envelope

Every create, update, or domain-action request that has a JSON body uses a top-level `data` envelope:

```json
{
  "data": {
    "name": "Toilet paper",
    "counting_unit": "roll"
  }
}
```

Rules:

- Resource fields are never sent directly at the request root.
- FormRequest rules address enveloped paths such as `data.name` and `data.counting_unit`.
- Validation error pointers use the same structure, such as `/data/name`.
- Domain action payloads, such as transfers, corrections, and manual-alert resolution, also use `data`.
- Requests with no body, such as an ordinary delete, do not send an empty envelope.

## Mutation resource identity

Mutation requests rely on the route to identify the resource type and, for existing resources, the resource ID:

```http
PATCH /api/items/42
```

```json
{
  "data": {
    "name": "Toilet paper"
  }
}
```

Rules:

- Create requests do not accept a client-generated `id` or `type`.
- Update requests do not accept `id` or `type`; both are immutable.
- The route determines the resource type.
- Scoped route-model binding determines the existing resource and enforces the household boundary before mutation.
- FormRequests explicitly prohibit `data.id` and `data.type` rather than silently discarding them.
- Successful resource responses continue to include the transformer-defined `type` and server-assigned `id`.

## Successful mutation responses

Mutation responses use these conventions:

| Action | Status | Response body |
| --- | ---: | --- |
| Create a resource | `201` | Created transformed resource under `data`, plus a `Location` header |
| Update a resource | `200` | Updated transformed resource under `data` |
| Archive a resource | `204` | No body |
| Restore a resource | `200` | Restored transformed resource under `data` |
| Permanently delete a resource | `204` | No body |
| Create an inventory movement or other persisted domain action | `201` | Created transformed action resource under `data` |
| Resolve a manual alert | `200` | Resolved transformed alert under `data` |

Relationships remain opt-in through `include` on mutation responses. A mutation does not implicitly expand relationships merely because it changed one.

## Relationship writes

Simple writable relationships use explicit ID fields inside the request's `data` object:

```json
{
  "data": {
    "name": "Toilet paper",
    "counting_unit": "roll",
    "category_ids": ["3", "5"]
  }
}
```

Rules:

- A many-to-many relationship uses a plural `_ids` field, such as `category_ids`.
- Omitting `category_ids` from an update leaves category assignments unchanged.
- Sending an empty `category_ids` array detaches every category.
- Sending IDs synchronizes the complete category assignment.
- FormRequests validate that every referenced ID exists within the authenticated household and may be assigned by the user.
- Nested resource creation is not supported inside a parent create or update payload.
- Inventory movements, notes, item images, and other complex child resources use their own endpoints.
- Response relationships retain their resource names, such as `categories`; writable ID fields are request-only and are not emitted by transformers.

## Partial updates

Existing resources are updated through `PATCH`. The API does not expose `PUT` endpoints.

```http
PATCH /api/items/42
```

```json
{
  "data": {
    "description": null,
    "category_ids": []
  }
}
```

Rules:

- An omitted field remains unchanged.
- An explicit `null` clears a nullable field.
- An explicit `null` for a required field fails validation with `422`.
- An empty relationship ID array removes all assignments for that relationship.
- A provided scalar or array replaces the current value of that writable field.
- The Vue form and API layers compare against the loaded baseline and normally send only dirty fields.
- Backend correctness does not depend on the frontend diff: resending an unchanged valid value remains safe.
- A successful patch returns the complete default transformed scalar representation unless a sparse fieldset was requested.

## Route nesting

The API uses a shallow hybrid route strategy.

Independently addressable resources use top-level routes:

```text
/api/items
/api/categories
/api/locations
/api/inventory-levels
/api/inventory-movements
/api/inventory-alerts
```

Parent-owned resources are listed and created within their parent context:

```text
GET  /api/items/{item}/images
POST /api/items/{item}/images

GET  /api/item-images/{item_image}/thumbnail
GET  /api/item-images/{item_image}/display

GET  /api/items/{item}/notes
POST /api/items/{item}/notes
GET  /api/categories/{category}/notes
POST /api/categories/{category}/notes
GET  /api/locations/{location}/notes
POST /api/locations/{location}/notes
GET  /api/inventory-movements/{inventory_movement}/notes
POST /api/inventory-movements/{inventory_movement}/notes
GET  /api/inventory-alerts/{inventory_alert}/notes
POST /api/inventory-alerts/{inventory_alert}/notes
```

After creation, child resources use shallow top-level member routes:

```text
PATCH  /api/item-images/{item_image}
DELETE /api/item-images/{item_image}

PATCH  /api/notes/{note}
DELETE /api/notes/{note}
```

Rules:

- URLs do not nest more than one parent level.
- Route-model binding remains scoped to the authenticated household.
- Authorization verifies both the child resource and its owning parent.
- Polymorphic notes have parent-specific collection/create routes but one shared shallow member route.
- Item-image transformers expose authorized thumbnail and display URLs rather than filesystem disk names or paths.
- Image delivery routes enforce household access. They stream private local files initially and may redirect to short-lived signed object-storage URLs after an S3 migration.

## Soft-delete lifecycle routes

Soft-deletable resources use explicit lifecycle routes:

```text
DELETE /api/items/{item}
POST   /api/items/{item}/restore
DELETE /api/items/{item}/permanently
```

Equivalent routes apply to other soft-deletable resources such as categories and locations.

Rules:

- Ordinary `DELETE` archives an active resource and returns `204` with no body.
- `POST .../restore` works only on an archived resource and returns `200` with restored `data`.
- `DELETE .../permanently` works only on an archived resource and returns `204` with no body.
- Restoring an active resource returns `409`.
- Permanently deleting an active resource returns `409`.
- Archiving an item with positive inventory or an unresolved manual alert returns `409` with the blocking conditions.
- Scoped binding, authorization policies, cascading rules, and dependency guards apply to every lifecycle action.

## Inventory movement creation

All inventory changes are created through one resource endpoint:

```http
POST /api/inventory-movements
```

The enveloped payload uses `movement_type` to select the operation:

```json
{
  "data": {
    "movement_type": "transfer",
    "item_id": "42",
    "source_location_id": "7",
    "destination_location_id": "9",
    "quantity": 30
  }
}
```

A correction accepts the observed final count:

```json
{
  "data": {
    "movement_type": "correction",
    "item_id": "42",
    "location_id": "7",
    "observed_quantity": 120,
    "note": null
  }
}
```

Correction rules:

- The referenced location must already exist, be active, and belong to the household.
- A missing item/location inventory level is treated as a recorded quantity of zero.
- A positive observed quantity may create that missing level with a null alert threshold.
- `observed_quantity` must be a nonnegative integer.
- When the observed quantity already equals the recorded quantity, no movement is created and the request returns `422` with a stable no-change validation error.

A quick consumption records one counting unit at a specific location:

```json
{
  "data": {
    "movement_type": "consumption",
    "item_id": "42",
    "location_id": "7",
    "quantity": 1
  }
}
```

Rules:

- Every successful request creates one immutable inventory-movement resource and returns it with `201`.
- `movement_type` is a writable domain discriminator and is distinct from the response resource identity `type: inventory-movements`.
- A shared FormRequest validates the envelope and discriminator, then delegates type-specific validation and execution to dedicated movement services.
- Every service executes its movement entries and inventory-level updates in one database transaction.
- The authenticated user is recorded by the server and cannot be supplied through the payload.
- Referenced items and locations must belong to the authenticated household.
- Supported movement types are `restock`, `transfer`, `consumption`, `correction`, and `disposal`.
- There is no generic `adjustment` movement type. Miscounts use `correction`; inbound stock uses `restock` or `transfer`.
- The primary quick quantity action creates a one-unit `consumption` movement.
- Consumption is always initiated from a specific inventory-level context; there is no item-wide consumption action.
- Item detail views place consume, restock, and transfer controls on each location row.
- A zero-stock inventory level does not expose or permit consumption.
- The API requires the location explicitly and never selects one on behalf of the frontend.
- `recorded_at` is assigned by the server, returned as an ISO 8601 UTC timestamp, and cannot be supplied or patched by the client.

### Transfer direction

Push and pull are frontend entry modes for the same transfer payload:

```json
{
  "data": {
    "movement_type": "transfer",
    "item_id": "42",
    "source_location_id": "7",
    "destination_location_id": "9",
    "quantity": 5
  }
}
```

Transfer out (push):

- Starts from an item/location row with positive stock.
- Locks that row's location as the source.
- Offers every other active household location as a destination, including locations without an existing level for the item.
- Caps quantity at the source level's current balance.

Transfer in (pull):

- Starts from an item/location row that will receive stock.
- Locks that row's location as the destination.
- Offers only other locations where the same item currently has a positive balance.
- Caps quantity at the selected source level's current balance.

Shared API invariants:

- Source and destination must differ.
- Quantity must be a positive integer.
- Source quantity may never become negative.
- The server rechecks and locks the source balance inside the transaction; frontend maximums are guidance, not the concurrency guarantee.
- The destination inventory level is created when it does not exist.
- A newly created destination inventory level always defaults to `alert_threshold: null`.
- Alert monitoring is not inherited from the source, inferred from the item, or enabled implicitly by a movement.
- An existing destination level retains its current threshold, including when its prior quantity was zero.
- The persisted movement has no separate push/pull field because source and destination fully describe its direction.
- The confirmation UI states the resulting movement explicitly, such as `Move 5 rolls from Basement to Main pantry.`

## Inventory movement history

Movement history is exposed as one chronological collection, newest first:

```text
GET /api/inventory-movements
```

Supported location filters include:

```text
filter[from_location_id]=7
filter[to_location_id]=9
filter[location_id]=7
```

Rules:

- `from_location_id` matches transfers leaving the specified location.
- `to_location_id` matches transfers entering the specified location.
- `location_id` matches any movement touching the specified location, including either side of a transfer.
- From/to semantics are transfer-specific. A negative correction is not described as moving stock from the location.
- A transfer remains one movement resource with a negative source entry and positive destination entry; filtering never splits or duplicates it into separate movement resources.
- Additional filters include `item_id`, `movement_type`, `recorded_by`, `recorded_from`, and `recorded_until`.
- Item and location detail views use the same top-level endpoint with filters rather than separate nested history endpoints.
- A history view requests presentation data through explicit includes such as `item`, `entries.location`, and `recorded_by`.
- Movement notes use the same polymorphic note resource and may be requested with an explicit `notes` include.

## Inventory-level updates

Inventory levels use these routes:

```text
POST  /api/inventory-levels
PATCH /api/inventory-levels/{inventory_level}
```

Creation example:

```json
{
  "data": {
    "item_id": "42",
    "location_id": "7",
    "alert_threshold": 5
  }
}
```

Rules:

- Creating a level establishes an item/location association with quantity `0`.
- `alert_threshold` may be omitted or explicitly set to `null`; either leaves the new level unmonitored.
- Quantity cannot be supplied during level creation. Initial stock must be recorded as a restock movement.
- Restock and transfer movements may create a missing level automatically, always with `alert_threshold: null`.
- Attempting to create an existing item/location pair returns `409 Conflict` with a stable error code.
- `alert_threshold` is the only directly writable field.
- `quantity` is read-only and every quantity change must be recorded through `POST /api/inventory-movements`.
- `item_id` and `location_id` are immutable. Relocating stock is a transfer movement, not an inventory-level edit.
- Setting `alert_threshold` to `null` disables automatic alert monitoring for that level.
- A zero-quantity level remains in place so its monitoring configuration and item/location association are retained.
- Inventory levels are not archived or directly deleted.

## Manual inventory alerts

Manual `buy_soon` alerts use these routes:

```text
POST  /api/inventory-alerts
POST  /api/inventory-alerts/{inventory_alert}/resolve
```

Creation example:

```json
{
  "data": {
    "item_id": "42",
    "alert_type": "buy_soon"
  }
}
```

Rules:

- Only one unresolved `buy_soon` alert may exist for an item.
- A duplicate create attempt returns `409 Conflict` with a stable error code.
- The frontend replaces the create action with note and resolve actions while an alert is active.
- Inventory alerts have no general update endpoint because their item, alert type, creator, and lifecycle timestamps are immutable or action-controlled.
- Optional explanatory content uses the shared polymorphic notes endpoint and may contain multiple notes.
- Resolving sets `resolved_at` and `resolved_by` on the server and returns the resolved transformed alert with `200`.
- Resolution has no request body unless a future domain requirement adds resolution data.
- Resolved alerts remain as history and do not block creating a later `buy_soon` alert for the same item.

## Dashboard alert composition

The dashboard loads automatic inventory conditions and manual alerts as two separate typed collections, in parallel:

```text
GET /api/inventory-levels?filter[alert_status]=triggered&include=item,location
GET /api/inventory-alerts?filter[status]=active&include=item,notes.created_by
```

Automatic conditions:

- They are computed from an inventory level and are not persisted alert records.
- `alert_status` is `unmonitored` when `alert_threshold` is `null`.
- `alert_status` is `empty` when a monitored level has quantity `0`.
- `alert_status` is `low` when a monitored level has positive quantity less than or equal to its threshold.
- `alert_status` is `okay` when a monitored level is above its threshold.
- The `triggered` filter matches `low` and `empty`.
- A condition disappears automatically when its level becomes okay or unmonitored.

Manual conditions:

- They are persisted `inventory-alerts` resources with stable IDs.
- The `active` filter selects unresolved alerts.
- They are edited and resolved through the manual-alert routes.

The SPA displays the collections as separate dashboard sections. The same item may appear in both because an automatic stock condition and an explicit `buy_soon` decision communicate different facts. A special mixed alert resource or dashboard aggregation endpoint is not part of the initial API; one may be added later only if there is a demonstrated performance need.

## Household invitations

Owner-only invitation routes:

```text
GET    /api/household-invitations
POST   /api/household-invitations
POST   /api/household-invitations/{household_invitation}/resend
DELETE /api/household-invitations/{household_invitation}
POST   /api/household-invitations/accept
```

Rules:

- Creation accepts a normalized email address and initially assigns the `member` role.
- If the email already belongs to this household, creation returns `409` with an already-member code.
- If the email belongs to another household, creation returns `409` with a household-membership conflict before creating an invitation.
- A duplicate active invitation for the household/email returns `409`.
- Invitations expire after seven days and store only a token hash.
- Resending rotates the token and expiration, invalidating the previous link.
- Revocation marks the invitation revoked and returns `204`.
- Acceptance requires an authenticated or newly registered user whose normalized email matches the invitation.
- Acceptance rechecks household membership and creates the membership in one transaction.
- Email delivery is optional infrastructure; the owner may copy the invitation URL directly.

## Planning status

The core API behavior and corresponding screen contracts are sufficiently settled for implementation. Remaining endpoint-level details should be resolved inside the responsible vertical slice and reconciled with this document. See [Implementation Roadmap](implementation-roadmap.md).
