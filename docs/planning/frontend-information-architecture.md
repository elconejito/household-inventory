# Frontend Information Architecture

## Application structure

The Vue SPA uses four top-level areas:

- Dashboard
- Inventory
- Activity
- Settings

Inventory groups the three equally important browsing modes behind persistent tabs:

```text
/inventory/items
/inventory/locations
/inventory/categories
```

Each mode remains directly addressable and linkable. Item, location, and category detail screens live beneath their corresponding route families. Grouping these screens keeps the primary application navigation compact without treating locations or categories as secondary data-management pages.

### Responsibilities

Dashboard:

- Automatic low- and empty-stock conditions
- Manual `buy_soon` alerts
- Quick search
- Frequent quick actions
- Links into relevant inventory detail screens

Inventory:

- Complete item browsing and search
- Location browsing, including hierarchical navigation
- Category browsing
- Create and manage items, locations, and categories

Activity:

- Chronological inventory movement history
- Filters for item, movement type, user, date, and from/to/either location

Settings:

- Household settings
- Membership management
- Archived-resource management and permanent deletion workflows

## Item index

The 1.0 item index uses a compact, tabular-looking list optimized for scanning rather than an image-forward card grid.

Each item row presents:

- A small optional image thumbnail when one exists
- The item name as the most prominent value
- Total quantity with its correctly pluralized counting unit
- Positive-stock locations and the quantity at each
- Low, empty, or manual `buy_soon` indicators when applicable
- Categories as secondary context

The desktop layout aligns these values into columns. The mobile layout stacks the same information within a compact row while preserving the same ordering and emphasis. The default sort is item name ascending. Search plus location, category, stock-on-hand, and attention filters sit above the list.

Stock and attention are separate filters: an item with no stock is not automatically an alert when none of its locations is monitored. Stock options distinguish any positive balance from no stock; attention options cover any attention needed, an empty monitored location, a low monitored location, Buy soon, or no attention needed. Both filters check the item across all active locations, even when the location picker limits the list to items tracked at one particular location. Helper text makes that distinction explicit. Filter changes return to page one; Clear filters resets both new selectors as well as the existing controls.

The initial release does not include a grid view or thumbnail-visibility preference. Both are reasonable future display options and must not require an API or domain-model change.

## Item detail

The item detail page prioritizes identification and location-specific inventory actions.

### Header and identity

- Item name
- Total quantity across all locations
- Categories
- Manual `buy_soon` status and action
- Edit and archive actions in a secondary menu

When an item has a primary image, it appears prominently beside the identity information near the top of the page. The image is large enough to distinguish packaging, labels, colors, or visually similar products while shopping. Items without images do not reserve an empty placeholder area. Additional item images remain in a secondary media section.

### Inventory by location

This is the primary operational section. Each location row shows:

- Location
- Quantity
- Alert threshold and computed status
- Consume one, Restock, and Transfer actions
- Correction and Disposal in a less-prominent action menu

The section also provides an action to establish the item at another location.

### Supporting information

- Notes
- Additional images
- The five most recent inventory movements
- A link to the complete Activity view filtered to the item

## Location detail

The location detail page supports both discovery within an area and precise inventory operations.

The header shows the location name, its breadcrumb path through the location hierarchy, notes when present, and edit/archive actions. Active child locations are presented near the top.

Every active location can store inventory directly, whether it is a parent, a child, or a leaf. A parent such as `Basement` is therefore both a browsable container for its sublocations and a valid exact stock location in its own right. The page provides a direct `Restock here` action for the current location and clearly distinguishes quantities stored directly there from quantities stored in descendants.

Locations with descendants provide two inventory scopes:

- **All within _Location_** includes inventory assigned directly to the current location and every descendant. This is the default.
- **Directly in _Location_** includes only inventory levels whose exact location is the current location.

Every item result displays its exact location path, even in the descendant-inclusive view. Quantity-changing actions operate on that exact inventory level and never on an aggregate quantity. Leaf locations omit the scope control because both scopes would be identical.

## Categories

Categories are flat in the initial release. They do not have parents, descendants, or a primary-category concept. An item may belong to any number of categories, allowing independent classifications such as `Bathroom` and `Paper goods` without a hierarchy.

The category index is alphabetical and searchable. A category detail page presents:

- Category name and notes
- Item count
- A searchable, tabular item list using the same scan-first presentation as the item index
- Controls to add or remove item assignments
- Edit and archive actions

## Dashboard

The dashboard is an attention and action surface, not a complete inventory view.

Its information hierarchy is:

1. Prominent, search-first item finder
2. Typeahead results with item-specific actions in each row
3. Separate Out of stock, Low stock, and Buy soon groups

Each typeahead result shows the item name, total quantity, and a compact location breakdown. The entire result, plus a dedicated `View` action, opens item detail. The row also exposes `Consume 1`, `Restock`, and `Buy soon`; an item already flagged shows its active status instead of offering a duplicate alert action. `Add item` remains a page-level action rather than a search-result action.

Consume and restock from dashboard search still require an exact location. Consumption offers only locations with positive stock, and when multiple locations qualify the user chooses one from a compact picker. The API never guesses a location.

Automatic-alert rows show the item, exact location, current quantity, threshold context, and appropriate restock or transfer actions. Manual-alert rows show the item, recent notes when present, and note/resolve actions.

On wide screens the three attention groups may appear in columns. On narrow screens they stack in urgency order: Out of stock, Low stock, then Buy soon. The dashboard contains neither the full inventory list nor general-purpose inventory statistics.

## Item creation

The initial item form contains:

- Name, required
- Singular counting unit, required and defaulted to `item`
- Any number of optional categories
- An optional primary photo

It presents two completion paths:

- **Save item** creates the item and opens its detail page.
- **Save and add stock** creates the item and immediately opens the Restock workflow.

Initial quantity is never embedded in the item-creation API request. The second path is a coordinated frontend flow that records the quantity as a proper inventory movement after the item exists. If the optional image upload fails after item creation, the item remains valid and the UI offers a retry rather than rolling back the item.

The item detail page separately supports adding stock through a restock movement or establishing a zero-quantity location with an optional threshold. Notes and additional images are added after the item exists.

Editing an item's counting unit shows a warning that the change affects how quantities are displayed in every location and throughout movement history. It performs no numeric conversion and is not blocked by existing inventory or movements.

## Notes

Items, locations, categories, inventory movements, and inventory alerts may each have multiple polymorphic notes. Notes are appendable records rather than one shared text field or per-model note column.

Items and locations also have a nullable `description`. A description is the model's single current summary and is edited with that model; it is not a timestamped note. No other initial notable model has a dedicated description field.

Initial behavior:

- The body is stored and returned as raw plain text.
- Line breaks and blank lines are preserved in storage and presentation.
- The SPA escapes the content and renders it with preserved whitespace; it does not interpret HTML or Markdown in 1.0.
- Notes are ordered newest first.
- Each note displays its author and creation date.
- The UI indicates when a note has been edited.
- Notes may be edited or soft-deleted.
- Notes do not initially have titles, categories, attachments, or pinning.

Markdown rendering is a near-future enhancement. Keeping the original body unchanged allows Markdown support to be added without a schema migration. Enabling it later will require an explicit rendering and sanitization policy.

## Item images

Items may have multiple images. The first active image is automatically primary, exactly one image is primary while any active images remain, and any active image may be promoted. Images have an optional plain-text caption and otherwise appear in upload order with the primary image first. Deleting the primary image automatically promotes the oldest remaining active image.

An upload is temporary source material. It is normalized for orientation, processed, and then discarded. The application retains exactly two aspect-preserving derivatives without upscaling:

- A thumbnail bounded to 320 by 320 pixels
- A display image bounded to 1920 by 1080 pixels

Both derivatives are written to a dedicated, configurable `inventory-images` filesystem disk. The initial deployment uses a local driver; moving the disk to S3 later does not change image records, domain behavior, or frontend resource shapes. Image records are created only after both derivatives have been written successfully.

Image records use soft deletion. Their derivative files remain available for restoration until permanent deletion, at which point both files are removed. The original upload is never retained.

## Household authorization

The initial application has two household roles:

- **Member** may view and modify all household inventory, including items, locations, categories, movements, alerts, notes, images, archiving, and restoration.
- **Owner** has all member capabilities and may also manage household settings and memberships and permanently delete resources.

There are no read-only or custom roles in 1.0. Archiving is available to members because it is reversible; permanent deletion is owner-only. The final owner may not leave the household or be demoted.

## Household scope

Each user belongs to exactly one household in 1.0. Registration creates a household and an owner membership; invited users join that household as members. The SPA therefore has no household selector or active-household preference.

The memberships table remains the authorization boundary between users and households, while the inventory data remains tenant-scoped by household. Multiple memberships per user may be introduced later together with an explicit household-selection design.

### Invitations

Owners invite members by email from Settings. The interface may send through configured email delivery and always permits copying the one-time link. Pending invitations show their expiration and provide resend and revoke actions.

Invite creation checks account membership immediately. An existing member of the current household and an account attached to another household produce distinct conflict feedback before an invitation is created. Acceptance verifies the invited email and repeats the membership check before joining the household.

## Responsive application shell

Wide layouts use a persistent top navigation bar for Dashboard, Inventory, and Activity. Inventory screens add a persistent secondary tab row for Items, Locations, and Categories. Account and Settings actions live in the user menu at the right side of the primary bar.

Narrow layouts use a sticky bottom navigation bar for Dashboard, Inventory, and Activity, while the page header provides account and Settings access. Inventory retains its three secondary tabs in a horizontally compact treatment.

Create and context-specific actions belong to the relevant page header or content section. The initial shell does not use a universal floating action button. A desktop sidebar is unnecessary for the small number of primary destinations and would reduce useful horizontal space for inventory tables.

## Quantity-action interactions

### Consume one

Consume one is the frequent, low-friction quantity action and does not open a separate confirmation dialog.

- The action label includes the quantity and appropriate unit, such as `Consume 1 roll`.
- It is available only in the context of an exact location with positive stock.
- The control disables as soon as submission starts to prevent duplicate clicks.
- Success feedback names the item or unit and exact location.
- The UI does not offer Undo because movements are immutable. An inaccurate result is handled through Correction.

### Other movements

- Restock collects a quantity and uses the form submission itself as confirmation.
- Transfer collects direction, source/destination, and quantity, then confirms with an explicit movement sentence.
- Correction collects the observed final quantity and confirms the calculated change.
- Disposal collects a quantity and confirms the removal.

Desktop may present these focused forms in dialogs. Narrow layouts may use a full-width sheet or dedicated full-screen treatment, while preserving the same fields and wording.

Inventory movements do not have a dedicated `note` field. They use the same polymorphic notes relationship, note component, and note lifecycle as other notable models. A movement's ledger facts remain immutable while its separate annotations may be added, edited, or soft-deleted.

Movement `recorded_at` values are returned in UTC and displayed in the user's local timezone. The initial movement forms do not expose a date/time field or support backdating.

## Planning status

The dashboard and core inventory wireframe direction is settled, including search-first dashboard actions and direct inventory at parent locations. See [Implementation Roadmap](implementation-roadmap.md) for the vertical-slice build sequence.
