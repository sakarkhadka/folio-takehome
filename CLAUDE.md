# Folio — Project Context for AI Assistance

## What this app does

Folio is a small internal document-sharing tool. Staff create documents and generate
one-time share links for recipients. There is a staff admin page, document creation,
share-link generation, and a recipient view.

---

## Stack

- **Runtime:** PHP 8.3 CLI, built-in server (`php -S`), no Apache/Nginx, no framework
- **Database:** SQLite via PDO (`db.sqlite` in project root)
- **Container:** Docker + Compose — single `app` service
- **Frontend:** Plain HTML + CSS. No JavaScript framework, no build step, no bundler
- **Tests:** Custom micro-framework in `tests/test.php`

---

## How the DB lifecycle works — read this before touching schema

`seed.php` **deletes and recreates `db.sqlite` from scratch** on every `docker compose up`.
There is no persistent state between runs. The sequence in `seed.php` is:

1. Delete `db.sqlite`
2. Apply `schema.sql` (the base schema — four tables, never edited directly)
3. Apply every `migrations/*.sql` file in alphanumeric order via `glob()`
4. Insert seed data (one staff row, one document, one share)

**Schema changes must go in a numbered migration file under `migrations/`, not in
`schema.sql`.** The README explicitly requires this. Migration files are named
`001_description.sql`, `002_description.sql`, etc. The numeric prefix guarantees
execution order.

Because the DB is recreated every run, there is no need for a `schema_migrations`
tracking table. All migrations always run. This is intentional for the demo model.

---

## Authentication — there is none

`current_staff()` in `lib/bootstrap.php` always returns the staff row with `id = 1`
(Freddy Folio). There is no login, no session, no auth middleware. Anyone who
navigates to `/admin.php` has full staff access. This is a known limitation of the
starter code — flag it, do not silently work around it, and do not add fake auth
complexity as a workaround.

---

## Timezone — a split to be aware of

`bootstrap.php` sets `date_default_timezone_set('America/Chicago')`. This affects
PHP's `date()`, `DateTime`, etc.

SQLite's `datetime('now')` returns **UTC**, not Chicago time. These are different
clocks and the existing code never reconciles them — `created_at` values in the DB
are UTC but are displayed as-is, so they appear offset for Chicago users.

**For any new feature that compares timestamps (e.g. scheduled publishing):**
- Store timestamps in UTC in the DB
- Convert from local time (America/Chicago) to UTC on input using `DateTime` +
  `DateTimeZone` before inserting
- Convert back to America/Chicago for display
- Do NOT use SQLite's `datetime('now', 'localtime')` — inside Docker the system
  timezone is UTC, so 'localtime' and 'now' return the same value

---

## Audit logging — must be called on every write

Every create, update, or state-change action must call `audit_log()` from
`lib/bootstrap.php`:

```php
audit_log('create', 'document', $docId, ['title' => $title]);
audit_log('create', 'share',    $shareId, ['document_id' => $docId, 'recipient_email' => $email]);
```

Signature: `audit_log(string $action, string $entity_type, int $entity_id, array $details = [])`

`entity_id` is `INTEGER` — store the numeric PK there. If a feature introduces a
string identifier (e.g. a readable slug), put it in the `$details` array as JSON,
not in `entity_id`.

---

## Code conventions — follow these exactly

- **Output escaping:** Always use `h($value)` (wraps `htmlspecialchars`) when echoing
  user-supplied data into HTML. Never echo raw values.
- **Forms:** Use server-side POST forms. Do not add JavaScript form handling.
- **Search / filters:** Use GET parameters (`?q=query`) so URLs are bookmarkable.
  Do not build client-side JS filtering.
- **Queries:** Raw PDO with prepared statements. No ORM, no query builder.
- **No new CSS files** — add classes to the existing `public/assets/style.css` only.
- **No new JS files** — the app has zero JavaScript. Keep it that way unless a feature
  genuinely cannot be built without it (none of the three planned features require JS).
- **HTTP responses:** Use `http_response_code()` explicitly for non-200 responses.

---

## File layout

```
lib/bootstrap.php     db(), current_staff(), audit_log(), random_token(), h()
lib/layout.php        render_header(title, staff?) / render_footer()
public/admin.php      Staff: create documents, list all documents
public/share.php      Staff: generate share link for a specific document
public/view.php       Recipient: view document by token
public/assets/style.css  Full design system — teal accent, CSS custom properties
schema.sql            Base schema — DO NOT EDIT
seed.php              Drops DB, applies schema + migrations, inserts seed data
migrations/           Numbered SQL files for schema changes (001_*, 002_*, ...)
tests/test.php        Test runner — add a test() block for every new feature
```

---

## The three features being built (implementation in progress)

| Step | Feature | Status |
|------|---------|--------|
| 0 | Migration system (runner in seed.php + migrations/ dir) | **Done** |
| 1 | Share by name — title search on admin page | **Done** |
| 2 | Scheduled publishing — publish_at timestamp + view gate | **Done** |
| 3 | Human-readable document IDs — slug + random suffix | Pending |

---

## What NOT to do

- Do not edit `schema.sql` — schema changes go in `migrations/`
- Do not add a `schema_migrations` tracking table — the fresh-seed model makes it
  unnecessary and adds complexity that would confuse the next reader
- Do not add client-side JavaScript unless genuinely unavoidable
- Do not introduce new libraries or Composer dependencies
- Do not add authentication — it is out of scope; flag the gap instead
- Do not use `datetime('now', 'localtime')` in SQLite queries — it does not do what
  you expect inside this Docker container
