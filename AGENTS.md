# AGENTS.md

Chorelist: offline-first PWA for shared household chores (recurring tasks, reminders, history, points ranking). One Laravel app serves the UI and the sync API. Derived from Shoppinglist (same stack and sync architecture); concept in `docs/concept.md`.

## Stack

- Laravel 13, PHP 8.4, MariaDB 10.11, DDEV (`chorelist.ddev.site`). Run PHP/Composer/Artisan/npm via `ddev`.
- Auth: Fortify (session cookie, Blade views in `resources/views/auth`), email verification required for syncing. `authenticateSessions()`: changing or resetting the password ends the other sessions; resetting the password of an unverified account also deletes its passkeys (somebody else may have registered the address). Passkeys via Fortify + `laravel/passkeys`. Account page `/account` (name/email, password, passkeys, deleting the account) is server rendered and needs a connection.
- Frontend: Blade + Alpine.js + Tailwind 4 (Vite). No SPA framework, no Livewire (must work offline).
- Client database: RxDB (Dexie/IndexedDB storage) with HTTP replication.
- Hosting target: shared hosting with KeyHelp (PHP-FPM + cron, no long running processes, no websockets). Cron every minute: `schedule:run` and `queue:work --max-time=50` (mails and push notifications are queued).
- Libraries: `rlanvin/php-rrule` + `rrule` (npm) for RFC 5545 recurrence rules, `laravel-notification-channels/webpush` (VAPID keys in `.env`, `php artisan webpush:vapid`).

## Data model

- `task_lists` (owner, name, icon, `ranking_enabled`, `ranking_period` week|month|all) and memberships `task_list_user` (personal `position`, `muted`, own `version`). Owner deleted their account: `owner_id` null, list soft deleted.
- `tasks`: title, notes, icon (emoji), category, points 1–5, schedule (see below), `reminders` (days before the due date), `remind_after_days` (on demand), `paused_until`, `archived`, `checklist` (steps `[{id, text, done}]`, reset when completed). Every member may change tasks; the history shows who.
- `completions`: who gets the points (`user_id`, may be another member), who entered it (`entered_by_id`), `occurrence` (start date of the occurrence, null for extra/on demand), `done_on`, `type` done|extra|skipped|postponed|undone, `postponed_to`, `points` (copied from the task, so later changes keep the ranking).
- `activities`: history of changes written by the server (list/task created/updated/deleted with field changes, members, postponed, taken back). Pull only.
- `absences`: holidays of a user, visible (without note) to the members of shared lists.
- Server only: `list_invites` (see invitations below), `reminder_deliveries` (each reminder once, pruned after 120 days), `push_subscriptions` (package table), `users.preferences` (JSON), `users.timezone` (set from the device), `users.ical_token`.
- Date columns of synced models use `App\Casts\DateOnly` (plain `Y-m-d`; the default date cast would store the millisecond timestamp format of `Syncable`).

## Schedule

- One implementation per side with the same rules: `app/Support/Schedule.php` and `resources/js/schedule.js`. `tests/fixtures/schedule.json` is run by both test suites (`tests/Unit/ScheduleTest.php`, `tests/js/schedule.test.js`); add cases there when changing the rules.
- Modes: `fixed` (RRULE from `starts_on` = DTSTART, `ends_on` = UNTIL), `after_completion` (FREQ/INTERVAL of the RRULE added to the last done/skipped completion; month ends do not overflow), `once`, `on_demand` (no date, optional `remind_after_days`).
- Supported RRULE parts: FREQ, INTERVAL, BYDAY (incl. `4MO`, `-1FR`), BYMONTHDAY (incl. -1), BYMONTH, BYSETPOS, WKST (`Schedule::RULE_PARTS`). The form builds them in `toSchedule()`/`fromSchedule()`; `describeSchedule()` renders them in German/English. Invalid rules fall back to `FREQ=WEEKLY` on the server.
- An occurrence starts on its date and is due `window_days` later, or on `postponed_to`. Only one occurrence is open: the latest started one that is not done/skipped, otherwise the next one; older open ones count as missed (shown in history/statistics, no minus points). Paused occurrences (before `paused_until`) are skipped.
- Completions of an occurrence have a deterministic id: UUID v5 (URL namespace) of `"<task id>:<occurrence>"` (`Completion::occurrenceId`, `occurrenceId()` in JS). Members completing the same occurrence offline end up with one document (server state wins). Completions are never deleted: undo sets `type: undone`, completing again sets it back. The occurrence never changes. A completion with points can only be changed by the member it counts for or the one who entered it. The server rejects (returns as deleted) occurrence completions with another id, completions of deleted tasks and implausible ones (`CompletionResource::plausible`: `done_on` at most tomorrow, `postponed_to` after the occurrence and within a year, max. 3 extra completions per task, member and day).
- Completing before the occurrence started asks: in advance (counts for that date) or extra (more points, next date stays).

## Optional OpenID Connect login (SSO)

- Off by default; any OIDC provider works (Laravel Socialite + `kovah/laravel-socialite-oidc`, JWT signature verified). Settings in `config/login.php` (`AUTH_LOCAL_*`, `AUTH_OIDC_*`) and `config/services.php` (`oidc`: issuer, client id/secret); same structure as RecipeManager and Shoppinglist. `App\Auth\OidcProvider` additionally checks `iss`, `aud`/`azp` and `exp` (`OidcClaims`).
- Routes `/auth/oidc/redirect`, `/auth/oidc/callback` (`OidcController`, redirect URI for the provider: `<APP_URL>/auth/oidc/callback`), unlink `DELETE /account/oidc`. Matching in `App\Auth\OidcLogin`: by `users.oidc_sub`, otherwise by email only if the provider marks it as verified. Linking an unverified account verifies it and removes its password, passkeys and sessions (somebody else may have registered or been invited with the address; `AuthenticateSession` skips users without password). Unknown users get a verified account without password (`AUTH_OIDC_REGISTRATION`); `AUTH_OIDC_REQUIRED_GROUP` checks the `groups` claim. The login stays remembered like the password login.
- `AUTH_LOCAL_ENABLED=false` (`RestrictLocalLogin` middleware): the login page shows only the OIDC button (`/login?local=1` shows the form), password logins only for `AUTH_LOCAL_EMERGENCY_EMAILS`, passkey logins are off. `AUTH_LOCAL_REGISTRATION=false` removes Fortify's registration. Password reset stays, so users without password can set one (needed for changing the password, managing passkeys and deleting the account).
- Account page: link/unlink at the top (unlink only with a password, otherwise the account is lost).
- Button label `App\Auth\OidcLabel::get()`: `AUTH_OIDC_LABEL`, otherwise the `appName` (Pocket ID's `APP_NAME`) from the public `<issuer>/api/application-configuration` (cached a day, a failure an hour), otherwise "SSO". Login and registration show the OIDC button first (registration only with `AUTH_OIDC_REGISTRATION`); `AUTH_OIDC_SIGNUP_URL` (e.g. the AccountManager's `/register`) adds "No … account yet?" below it and below "Link …" (`<x-oidc-signup>`). Users switch to SSO voluntarily: linking keeps the local account and its data.
- Local test: Pocket ID runs in the RecipeManager DDEV project (`https://recipemanager.ddev.site:14111`, see its `AGENTS.md`), reached at `AUTH_OIDC_ISSUER=http://ddev-RecipeManager-pocket-id:1411` through the shared `ddev_default` network. Put the `AUTH_OIDC_*` values into the git-ignored `.ddev/config.local.yaml` (`web_environment`). Tests mock Socialite (`tests/Feature/OidcLoginTest.php`).

## Sync architecture

Same architecture as Shoppinglist:

- The UI reads and writes only the local RxDB database; replication runs in the background (`resources/js/db`).
- Server side: `app/Sync/*Resource.php` implement pull (checkpoint `updated_at` + id) and push (conflict detection) per collection, routes `GET /sync/{collection}/pull`, `POST /sync/{collection}/push`. Each resource declares the validation rules of its fields (`rules()`).
- IDs are UUIDs generated on the client (v7, completions v5). Timestamps have millisecond precision (`Syncable` trait).
- Conflicts: every local change increases `version` (RxDB `preSave` hook); the server accepts a push only if `assumedMasterState.version` equals its version and keeps the client's version. Otherwise it returns its document and the server state wins.
- Lists are pulled via memberships, so revoked access and deleted lists arrive as deleted documents. Any list change has to call `touchForMembers()`; adding a member touches tasks, completions, activities and the absences of all members. Name/email changes touch the user's documents (`UpdateUserProfileInformation`).
- Name, icon and ranking of a list belong to the owner; members' pushes keep only `position`/`muted` and get the list back. The list document's `version` is the list's version plus the membership's (personal fields), so reordering or muting never conflicts with the others' changes; personal fields are kept even on a conflict.
- Data of a list that disappeared (left, removed, deleted) is removed from the device with `removeLocally()` (`resources/js/db/index.js`): the push modifier skips these deletions. Deleted documents are purged after 7 days (RxDB cleanup plugin).
- Moving a task to another list creates a new task (history stays with the old one); with an unchanged schedule it starts at the next open occurrence.
- Tasks/completions of lists or tasks unknown to the server return 409, the client retries (created offline, not pushed yet).
- Sharing, leaving, invitation links, push subscriptions and the calendar link need a connection.

## Notifications

- Kinds (`PreferenceController::NOTIFICATIONS`): reminder, overdue, completed, task_changed, list_shared, weekly_summary. Channels per kind: push and/or mail (`users.preferences.notifications`). Muted lists notify nothing.
- `reminders:send` (scheduled every 5 minutes): per user after their `reminder_time` in their time zone, not while absent: reminders N days before the due date, overdue once per occurrence, on demand tasks after `remind_after_days`, weekly summary on Mondays. Every reminder once (`reminder_deliveries`, key per task/kind/occurrence/due date/offset, so a postponed occurrence is reminded again). A user is computed again only on a new day or when their lists, tasks, completions or settings changed (fingerprint in the cache). `reminder_time` is rounded down to 5 minutes. `--dry-run` lists them.
- Sync changes notify the other members (`App\Support\Notify`, queued after commit); a completion only once, also when taken back and done again.
- Push endpoints must belong to a known push service (`PushSubscriptionController::PUSH_HOSTS`, the server sends requests there).
- Push: `resources/js/push.js` subscribes the device (`POST /push-subscriptions`), `public/push-sw.js` (imported into the generated service worker via `workbox.importScripts`) shows notifications and opens the URL. iOS only allows push for apps added to the home screen.

## Ranking and suggestions

- `resources/js/ranking.js`: points per member in the list's period (week from Monday, month, all), `fair` share by days present (holidays do not count), weekly points for the statistics.
- Suggestions ("For you") are computed on the device only and never stored: urgency (overdue, due, open, upcoming within 2 days, on demand after `remind_after_days`) plus points, weighted more when the user is behind their fair share; names the member the task would overtake. Can be turned off in the settings.

## Other features

- History per list (`#/history/<id>`) and per task: completions, activities and missed dates (last 60 days), filter by member/task.
- Statistics (`#/stats/<id>`): ranking per period, points per week (8 weeks), per task done/missed in 90 days.
- Holidays (`#/absences`), calendar subscription (`/ical/<user id>-<HMAC>.ics`, derived from `users.ical_token` and the app key: VEVENTs from start to due date for 180 days, because calendar apps ignore VTODOs in subscriptions), invitations (`list_invites`, only the SHA-256 of the token stored): links for anybody (7 days) and personal ones (30 days) for existing verified users the owner shares with by email; `/invite/<token>` shows list, owner and what members see, joining needs a POST (confirmation). Unknown and unverified addresses are added right away and get a link to choose a password. Sharing and links are throttled, JSON export (client only), dark mode (class `dark`, palette swap in `resources/css/app.css`), category filter per list (device only).

## Translations

- Languages: `de_CH` (default, Swiss spelling "ss", informal "du") and `en`; keys are the English texts.
- One source for server and client: `lang/de_CH.json` (+ `lang/de_CH/*.php` for Laravel's validation/auth texts). The app shell translates itself with `$t()` (`resources/js/i18n.js`), Blade pages, mails and push texts use `__()`. The client has no pluralisation: use separate keys (e.g. 1 point).
- History sentences avoid conjugation by name ("«Bad putzen» erledigt" + who below), so "Du" works.

## PWA, UI patterns, deployment

As in Shoppinglist: `vite-plugin-pwa` (generateSW, `registerType: 'prompt'`), app shell `/` without user data, `data-online` links, `<x-swipe-row>` actions (swipe or long press, `preferences.row_actions`), undo toasts (`resources/js/undo.js`, also used to take back completions), dialogs with history entries (`layers.js`), install instructions (`install.js`, `#/install`). Favicons: `src/favicon.svg` → `ddev npm run favicon -- --source src/favicon.svg --theme '#0369a1' --bg '#0369a1' --padding-scale 1`. Link preview image `public/og-image.png`.

Deployment: `.forgejo/workflows/deploy.yml` (same as Shoppinglist, default URL `https://aufgaben.narrenhaus.ch`).

## Commands

- Tests: `ddev artisan test` (Unit + Feature, SQLite in memory) and `ddev npm test` (Vitest: schedule, ranking, helpers, schemas). Code style: `ddev exec vendor/bin/pint`.
- Assets: `ddev npm run build` (or `ddev npm run dev`).
- Local data: `ddev artisan migrate:fresh --seed` (test@example.com and anna@example.com, password `password`, a shared list with chores). Mails: Mailpit (`ddev launch -m`).
- Reminders: `ddev artisan reminders:send --dry-run`.

## Rules

- Alpine proxies must be copied to plain values before `insert`/`incrementalPatch` (DataCloneError).
- Every synced field must exist in the RxDB schema (`resources/js/db/schemas.js`), the resource document and the resource's `rules()`. Schema changes in RxDB need a schema version bump and migration strategy; if the server already fills a new field, also increase the collection in `REPLICATION_RUNS` (`resources/js/db/index.js`), so every client pulls it once more.
- Schedule changes always in both implementations plus a fixture case.
- Keep the app shell (`resources/views/app.blade.php`, partials) free of user data, it is cached by the service worker.
