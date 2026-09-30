# Database Schema

Status: consolidated planning draft

## Conventions

- MySQL with a case-insensitive Unicode collation
- Laravel-style unsigned big integer primary and foreign keys
- Foreign-key columns are indexed
- Domain discriminator columns are strings cast to PHP-backed enums, not MySQL-native `ENUM` columns
- Dates are stored in UTC
- `created_at` and `updated_at` exist only where both have domain value
- `deleted_at` is present only on models that support archive/restore
- API resource IDs are serialized as strings even though database IDs are integers
- Domain services and policies always scope owned records through the authenticated user's sole household membership

## users

Laravel authentication record. Authentication-specific columns follow the selected Laravel starter baseline.

Domain-relevant columns:

- `id`
- `name`
- `email`, unique
- `email_verified_at`, nullable
- `password`
- `remember_token`, nullable
- Laravel authentication timestamps

## households

- `id`
- `name`
- `deleted_at`, nullable

Households are ownership and tenant boundaries. They do not use generic automatic timestamps.

## memberships

- `id`
- `household_id`
- `user_id`
- `role`, string cast to `MembershipRole` (`owner`, `member`)
- `joined_at`
- `deleted_at`, nullable

Constraints:

- A user has only one membership in 1.0, including archived membership history.
- A household/user pair cannot be duplicated.
- The final active owner cannot leave or be demoted.
- Membership removal is a soft delete.

## household_invitations

- `id`
- `household_id`
- `email`
- `role`, string cast to `MembershipRole` (`member` initially)
- `token_hash`
- `invited_by`
- `created_at`
- `expires_at`
- `accepted_at`, nullable
- `revoked_at`, nullable

Constraints and lifecycle:

- Invitation email addresses are normalized consistently with user email addresses.
- An invitation cannot be created when the email belongs to an account with a membership in this or another household, including membership state that prevents joining a new household.
- An existing member returns an already-member conflict; membership in another household returns a household-membership conflict.
- Only one active invitation may exist for a household/email pair.
- Tokens are one-time secrets and only their hashes are stored.
- Invitations expire after seven days.
- Resending rotates the token and restarts expiration.
- Revocation sets `revoked_at`; acceptance sets `accepted_at` and creates the membership transactionally.
- Invitation acceptance rechecks the one-household invariant to prevent races.
- Invitations use lifecycle timestamps rather than soft deletion or generic `updated_at`.

## items

- `id`
- `household_id`
- `name`
- `counting_unit`
- `description`, nullable text
- `deleted_at`, nullable

Constraints and normalization:

- `(household_id, name)` is unique, including archived rows.
- The case-insensitive database collation defines exact-name equivalence.
- Names are trimmed and repeated internal whitespace is collapsed before validation and persistence.
- `counting_unit` is an editable display label. Changing it performs no quantity conversion and relabels current and historical displays.

Archive guard:

- The item has no inventory level with positive quantity.
- The item has no unresolved inventory alert.

## categories

- `id`
- `household_id`
- `name`
- `deleted_at`, nullable

Constraints:

- `(household_id, name)` is unique, including archived rows.
- Names use the same whitespace normalization and case-insensitive comparison as items.
- Categories are flat; there is no parent or primary-category concept.

## category_item

- `category_id`
- `item_id`

Constraints and lifecycle:

- `(category_id, item_id)` is unique.
- The pivot has no primary model ID, timestamps, or soft deletes.
- Item/category archive preserves the pivot.
- Permanent deletion of either parent deletes the pivot.
- An active item cannot be newly attached to an archived category.

## locations

- `id`
- `household_id`
- `parent_id`, nullable self-reference
- `name`
- `description`, nullable text
- `deleted_at`, nullable

Constraints:

- A parent belongs to the same household.
- A location cannot parent itself or become a descendant of itself.
- Names are unique among siblings, including archived rows, under the case-insensitive collation.
- Root-name uniqueness and cycle checks are enforced inside a transaction that locks the household or affected hierarchy. A normal `(household_id, parent_id, name)` index also protects non-root siblings; MySQL's nullable unique-key behavior is not relied upon for roots.
- The same name may appear beneath different parents.

Archive guard:

- No positive direct inventory exists at the location.
- No active child location exists.
- Zero-quantity inventory levels do not block archive.

Permanent deletion guard:

- No child locations, inventory levels, or movement entries reference the location.

## inventory_levels

- `id`
- `item_id`
- `location_id`
- `quantity`, unsigned integer
- `alert_threshold`, nullable unsigned integer

Constraints and lifecycle:

- `(item_id, location_id)` is unique.
- Item and location must belong to the same household.
- `quantity` cannot be negative and changes only through inventory-movement services.
- `alert_threshold = null` means unmonitored.
- Newly created levels always start with a null threshold unless a user explicitly supplies one when establishing a zero-quantity level.
- Levels have no timestamps, soft deletes, or direct delete endpoint.
- Permanent item deletion cascades to levels; location deletion is restricted while levels exist.

## inventory_movements

- `id`
- `household_id`
- `item_id`
- `movement_type`, string cast to `MovementType`
- `recorded_by`
- `recorded_at`

Allowed initial movement types:

- `restock`
- `transfer`
- `consumption`
- `correction`
- `disposal`

Lifecycle:

- Movements are immutable.
- `recorded_at` is assigned by the server when the transaction succeeds.
- Movements have no automatic timestamps or soft deletes.
- Permanent item deletion cascades through its movement history.

## inventory_movement_entries

- `id`
- `inventory_movement_id`
- `location_id`
- `quantity_delta`, signed integer
- `balance_after`, unsigned integer

Constraints and lifecycle:

- Every movement has at least one entry.
- A transfer has one negative source entry and one equal positive destination entry.
- Restock has one positive entry.
- Consumption and disposal have one negative entry and cannot make the source negative.
- Correction has one signed entry equal to the observed quantity minus the recorded quantity.
- A zero-delta correction is not persisted.
- Entries are immutable and have no timestamps or soft deletes.
- Deleting a movement as part of permanent item deletion cascades to its entries.
- Location deletion is restricted while entries exist.

## inventory_alerts

- `id`
- `household_id`
- `item_id`
- `alert_type`, string cast to `InventoryAlertType` (`buy_soon` initially)
- `created_by`
- `created_at`
- `resolved_at`, nullable
- `resolved_by`, nullable

Constraints and lifecycle:

- Only manual alerts are persisted; automatic level conditions are calculated.
- One unresolved alert of a given type may exist per item.
- Duplicate creation is prevented transactionally and returns `409 Conflict`.
- Resolution sets `resolved_at` and `resolved_by`; alerts are not edited or soft-deleted.
- Resolved alerts remain indefinitely and do not block a later alert of the same type.
- Permanent item deletion cascades to its alerts.

## item_images

- `id`
- `item_id`
- `disk`
- `thumbnail_path`
- `thumbnail_mime_type`
- `thumbnail_width`
- `thumbnail_height`
- `display_path`
- `display_mime_type`
- `display_width`
- `display_height`
- `caption`, nullable text
- `is_primary`
- `uploaded_by`
- `uploaded_at`
- `deleted_at`, nullable

Constraints and lifecycle:

- An item with active images has exactly one primary image.
- Primary changes and automatic promotion occur transactionally while locking the item's images.
- Non-primary images are ordered by `uploaded_at`.
- The database stores private paths; API transformers expose only authorized delivery URLs.
- Soft deletion retains both derivative files for restoration.
- Permanent image or item deletion removes both stored derivative files.
- There is no original path, original filename, kind, sort order, or generic model timestamps.

## notes

- `id`
- `notable_type`
- `notable_id`
- `body`, text
- `created_by`
- `created_at`
- `updated_at`
- `deleted_at`, nullable

Constraints and lifecycle:

- Laravel's morph-map aliases are used rather than persisting PHP class names.
- Initial notable types are Item, Category, Location, InventoryMovement, and InventoryAlert.
- Notes are plain text in 1.0 and preserve line breaks.
- Notes are ordered newest first, editable, and soft-deletable.
- Permanent deletion of a notable parent deletes its notes.
- The polymorphic owner is used to enforce household scope and authorization.

## Transaction boundaries

Inventory movement creation locks all affected inventory-level rows, creates missing permitted levels, writes the immutable movement and entries, and updates current balances in one transaction.

Other operations requiring transactional checks include:

- Location reparenting and sibling-name validation
- Item and location archive guards
- Manual-alert duplicate prevention and resolution
- Primary-image selection and promotion
- Final-owner membership protection
- Invitation creation, acceptance, resend, and revocation

## Infrastructure tables

Framework-owned tables for sessions, cache, queues, password resets, Sanctum tokens, and failed jobs are included only when required by the selected Laravel configuration. They follow framework conventions and are not exposed as application resources.

## Schema review status

The identified 1.0 domain tables, lifecycle columns, ownership boundaries, and transaction-sensitive constraints are consolidated. New implementation discoveries should update this document and the corresponding API or frontend contract together.
