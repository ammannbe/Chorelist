# AGENTS.md

Chorelist: offline-first PWA for shared household chores (recurring tasks, reminders, history, points ranking). One Laravel app serves the UI and the sync API. Derived from Shoppinglist (same stack and sync architecture); concept in `docs/concept.md`.

## Stack

- Laravel 13, PHP 8.4, MariaDB 10.11, DDEV (`chorelist.ddev.site`). Run PHP/Composer/Artisan/npm via `ddev`.
- Auth: Fortify (session cookie, Blade views in `resources/views/auth`), email verification required for syncing. `authenticateSessions()`: changing or resetting the password ends the other sessions; resetting the password of an unverified account also deletes its passkeys (somebody else may have registered the address). Passkeys via Fortify + `laravel/passkeys`. Account page `/account` (name/email, password, passkeys, deleting the account; accounts linked to the OIDC login: name and deleting only, see below) is server rendered and needs a connection.
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
- Households (`App\Models\Household`, see below): `households` (uuid, name, `account_manager_household_uuid`, `account_manager_version`, `account_manager_import_status`, `account_manager_shares_hash`), `household_user` (role admin|member, `joined_at`), `household_invitations` (email, `token_hash`, role, `expires_at`, `accepted_at`), shares `task_list_household`, `household_pending_members` (mirrored members without a user yet), `account_manager_webhook_events` (processed event ids, 7 days). Memberships have `direct` (false: only through a household).
- Server only: `list_invites` (see invitations below), `reminder_deliveries` (each reminder once, pruned after 120 days), `push_subscriptions` (package table), `users.preferences` (JSON), `users.timezone` (set from the device), `users.ical_token`.
- Date columns of synced models use `App\Casts\DateOnly` (plain `Y-m-d`; the default date cast would store the millisecond timestamp format of `Syncable`).

## Schedule

- One implementation per side with the same rules: `app/Support/Schedule.php` and `resources/js/schedule.js`. `tests/fixtures/schedule.json` is run by both test suites (`tests/Unit/ScheduleTest.php`, `tests/js/schedule.test.js`); add cases there when changing the rules.
- Modes: `fixed` (RRULE from `starts_on` = DTSTART, `ends_on` = UNTIL), `after_completion` (FREQ/INTERVAL of the RRULE added to the last done/skipped completion; month ends do not overflow), `once`, `dates` (one occurrence on each date of `tasks.dates`, sorted unique `Y-m-d`, max. 400 = `Schedule::MAX_DATES`; `starts_on` is the first date, no `rrule`/`ends_on`, normalized by `TaskResource` and the form; finished after the last date; e.g. waste collection), `on_demand` (no date, optional `remind_after_days`).
- Supported RRULE parts: FREQ, INTERVAL, BYDAY (incl. `4MO`, `-1FR`), BYMONTHDAY (incl. -1), BYMONTH, BYSETPOS, WKST (`Schedule::RULE_PARTS`). The form builds them in `toSchedule()`/`fromSchedule()`; `describeSchedule()` renders them in German/English. Invalid rules fall back to `FREQ=WEEKLY` on the server.
- An occurrence starts on its date and is due `window_days` later, or on `postponed_to`. Only one occurrence is open: the latest started one that is not done/skipped, otherwise the next one; older open ones count as missed (shown in history/statistics, no minus points). Paused occurrences (before `paused_until`) are skipped.
- Completions of an occurrence have a deterministic id: UUID v5 (URL namespace) of `"<task id>:<occurrence>"` (`Completion::occurrenceId`, `occurrenceId()` in JS). Members completing the same occurrence offline end up with one document (server state wins). Completions are never deleted: undo sets `type: undone`, completing again sets it back. The occurrence never changes. A completion with points can only be changed by the member it counts for or the one who entered it. The server rejects (returns as deleted) occurrence completions with another id, completions of deleted tasks and implausible ones (`CompletionResource::plausible`: `done_on` at most tomorrow, `postponed_to` after the occurrence and within a year, max. 3 extra completions per task, member and day).
- Completing before the occurrence started asks: in advance (counts for that date) or extra (more points, next date stays).

## Optional OpenID Connect login (SSO)

- Off by default; any OIDC provider works (Laravel Socialite + `kovah/laravel-socialite-oidc`, JWT signature verified). Settings in `config/login.php` (`AUTH_LOCAL_*`, `AUTH_OIDC_*`) and `config/services.php` (`oidc`: issuer, client id/secret); same structure as RecipeManager and Shoppinglist. `App\Auth\OidcProvider` additionally checks `iss`, `aud`/`azp` and `exp` (`OidcClaims`).
- Routes `/auth/oidc/redirect`, `/auth/oidc/callback` (`OidcController`, intents `login`, `link`, `confirm`; redirect URI for the provider: `<APP_URL>/auth/oidc/callback`), link `POST /account/oidc`, unlink `DELETE /account/oidc`. Matching in `App\Auth\OidcLogin`: by `users.oidc_sub`, otherwise by email only if the provider marks it as verified (an unverified local account gets verified; somebody else may have registered or been invited with the address, linking drops their password, passkeys and sessions). Unknown users get a verified account without password (`AUTH_OIDC_REGISTRATION`); `AUTH_OIDC_REQUIRED_GROUP` checks the `groups` claim. The login stays remembered like the password login.
- `AUTH_LOCAL_ENABLED=false` (`RestrictLocalLogin` middleware): the login page shows only the OIDC button (`/login?local=1` shows the form), password logins only for `AUTH_LOCAL_EMERGENCY_EMAILS`, passkey logins are off. `AUTH_LOCAL_REGISTRATION=false` removes Fortify's registration. Password reset stays for unlinked accounts (users without password set one; needed for changing the password, managing passkeys and deleting the account).

### Linked accounts are SSO-only (same rules as RecipeManager)

- A linked account (`oidc_sub` set, `User::isLinkedToOidc()`) logs in through the provider only. All rules live in `App\Auth\OidcAccount` (reference: RecipeManager `AGENTS.md`, «Linked accounts are SSO-only»); `User::booted()` throws if a linked account would get a password, `FortifyServiceProvider` if it would get a passkey (link/unlink only through `OidcAccount`, the factory drops the password of `linked()` users).
- Linking (first OIDC login matched by email, or "Link …" on the account page) = `OidcAccount::link()`: password null, passkeys deleted, remember token rotated, password reset tokens deleted, the user's other sessions deleted (database session driver only; other drivers: they run out after `SESSION_LIFETIME`, `AuthenticateSession` skips users without password), then the callback logs in again with a new remember cookie. Push subscriptions, the calendar link and the data stay.
- "Link …" on the account page first asks for the current password (`POST /account/oidc`, `throttle:5,1`; accounts without one skip it): it stores `oidc.password_confirmed_at`, `/auth/oidc/redirect?link=1` needs it (5 min, used once), otherwise back to the account page.
- Local login: the Fortify login pipeline (`Fortify::authenticateThrough`, Fortify's default steps plus `App\Actions\Fortify\RefuseLinkedAccounts` before the password check) refuses linked accounts (also with a leftover password) with "This account is connected to :provider. Sign in with :provider." (same key as RecipeManager) («Dieses Konto ist mit :provider verbunden. Melde dich mit :provider an.», `OidcAccount::message()`); `RestrictLocalLogin` answers the same with the local login disabled. Passkey logins of linked accounts (only leftover passkeys) are refused with the same message (`Passkeys::authorizeLoginUsing`); `auth.js` also shows it for the email field's autofill. Enumeration: this only adds "linked or not" for addresses whose account exists, which registration ("already taken") and the reset page ("can't find a user") tell anyway; unknown addresses and wrong passwords keep `auth.failed`, the login stays rate limited.
- Password reset (`App\Http\Controllers\Auth\PasswordResetLinkController`/`NewPasswordController`, bound in place of Fortify's): a linked account gets no token but the mail `SignInWithOidcNotice` (once per 15 min, not queued like the reset link) and the page answers like for any account (also "wait" within the broker's 60 s throttle); a valid reset link of a linked account (e.g. older than the link) is refused with the message above and its token deleted; an invalid token keeps the usual answer.
- Unlinking (only with the OIDC login enabled) = `OidcAccount::unlink()`: needs an OIDC login within 15 min (`oidc.signed_in_at`, set by every login/link/confirmation). Otherwise the account page links to `/auth/oidc/redirect?confirm=1`; the callback checks that the provider returns the same `sub` and opens `/account#oidc` with the form. The form takes a new password + confirmation (`PasswordValidationRules`); unlinking sets it, rotates the remember token, ends the other sessions and moves mirrored households to pending (AccountManager driver). The provider may confirm without a prompt while the user is still logged in there.
- Account page: linked accounts show "Connected with …" instead of the password and passkey sections, the email is read only (`UpdateUserProfileInformation` keeps it; unlink first). Deleting a linked account needs the same recent OIDC login instead of the password (`?confirm=1&for=delete` → `/account#delete-account`, `AccountController::destroy`); with the OIDC login disabled it is not possible (`users:unlink-oidc`, then "Forgot your password?"). The settings link reads "Name and deleting the account" (`/me` → `linked`).
- PWA: the account page is a navigation (service worker `NetworkOnly`, offline the app shell), never cached; `/me` is not cached by the service worker (the last answer only in `localStorage` for the link text).
- Existing data: migration `2026_11_10_090000_remove_local_login_from_linked_accounts` (passwords, passkeys, remember tokens, reset tokens and sessions of linked accounts; linked users log in again once). Unlinked accounts are untouched; accounts without password and without `oidc_sub` can still use "Forgot your password?".
- Emergency addresses (`AUTH_LOCAL_EMERGENCY_EMAILS`) must stay unlinked (with `AUTH_LOCAL_ENABLED=false` they are the only password login). Way back (provider gone, lost provider account): `users:unlink-oidc {emails*} {--all}` unlinks without password, the users then use "Forgot your password?".
- Tests: `OidcLinkedAccountTest` (shared Socialite mock `Tests\Feature\Concerns\MocksOidcProvider`).

### OIDC login button and local test

- Button label `App\Auth\OidcLabel::get()`: `AUTH_OIDC_LABEL`, otherwise the `appName` (Pocket ID's `APP_NAME`) from the public `<issuer>/api/application-configuration` (cached a day, a failure an hour), otherwise "SSO". Login and registration show the OIDC button first (registration only with `AUTH_OIDC_REGISTRATION`); `AUTH_OIDC_SIGNUP_URL` (e.g. the AccountManager's `/register`) adds "No … account yet?" below it and below "Link …" (`<x-oidc-signup>`). Users switch to SSO voluntarily: linking keeps the local account and its data (not its password and passkeys).
- Local test: Pocket ID runs in the AccountManager DDEV project (`https://accountmanager.ddev.site:14111`, see its `AGENTS.md`), reached at `AUTH_OIDC_ISSUER=http://ddev-AccountManager-pocket-id:1411` through the shared `ddev_default` network. Put the `AUTH_OIDC_*` values into the git-ignored `.ddev/config.local.yaml` (`web_environment`). Tests mock Socialite (`OidcLoginTest`, `OidcLinkedAccountTest`).

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
- Sharing (also with households), leaving, invitation links, push subscriptions and the calendar link need a connection.

## Households

- `HOUSEHOLDS_DRIVER` (`config/households.php`): `local` (default) manages households in the app; `account-manager` mirrors them from the AccountManager (see below) and makes the local management read only (403), sharing lists with households stays possible. Same tables and names as RecipeManager and Shoppinglist.
- Page `/households` (server rendered, `HouseholdController`, `HouseholdMemberController`, `HouseholdInvitationController`, needs a connection, linked in the settings): create, rename, delete, invite by email with a role, change roles, remove members, leave. Same page as in Shoppinglist (`households/index.blade.php` + `households/household.blade.php`): open invitations for the user on top, then every household as `<details>` (collapsed name, role, member count and AccountManager status; opened members, invitations, shared lists and actions). Actions redirect to `/households#household-<uuid>` with `household` in the session (`Controller::toHousehold`), forms send `_household` so a failed validation opens it again; a single household is always open; `/households/{uuid}` redirects there. Admins manage, members may leave; the last admin can neither leave nor lose the role (deleting the household is possible). Deleting the account leaves the households: the longest member becomes admin, empty households are deleted.
- Invitations (`HouseholdInvitationController`, 30 days, only the SHA-256 of the token stored): mail to the address (also without account), accepted only by the user with that verified address, by the link (`/household-invitations/<token>`) or on the households page (`/me` returns the number of open ones). Joining needs a POST.
- Sharing: the list owner shares with their own households in the list dialog (`POST/DELETE /lists/{list}/households`, activities `household.shared`/`household.unshared`). Every household member has the same access as an individual member.
- Access is resolved through the household membership and kept as list memberships with `direct = false` (`TaskList::syncHouseholdMembers()`), so sync, notifications, reminders, ranking, absences and the calendar work unchanged. It runs whenever shares or members change (`Household::addUser/removeUser/deleteWithShares`); removed members get the list as deleted document on the next pull, their pushes are ignored like those of any non-member. Removing a member or deleting the household only removes access; a removed member's own lists are no longer shared with the household.
- Members through a household cannot leave the list or be removed from it (422, a pushed deletion returns the list); they leave the household instead. Removing a direct member who is also in a shared household only clears `direct`. List documents contain `households` ({id: uuid, name}), `direct` and `members[].direct`.

### Households from the AccountManager (`HOUSEHOLDS_DRIVER=account-manager`)

- Contract: AccountManager `docs/HOUSEHOLDS-API.md` (branch/phase 8). Env `ACCOUNT_MANAGER_URL`, `ACCOUNT_MANAGER_SERVICE_KEY`, `ACCOUNT_MANAGER_SECRET` (one secret signs the webhooks and authenticates the API with HTTP Basic), `ACCOUNT_MANAGER_REPORT_SHARES`. Mirroring is active only with the driver and all three values (`AccountManagerHouseholds::enabled()`), otherwise the webhook returns 404.
- `App\Households\AccountManagerClient` (API: `GET /api/households?sub=`, `GET /api/households/{uuid}`, `PUT …/shares`, `POST/GET /api/households/import`; every failure is `AccountManagerUnavailable`, 404 is an answer), `AccountManagerHouseholds` (applying snapshots), jobs `ApplyAccountManagerEvent`, `ReportAccountManagerShares`.
- Webhook `POST /api/account-manager/webhook` (`routes/api.php`, no session/CSRF, `throttle:120,1`, `Api\AccountManagerWebhookController`): HMAC-SHA256 over `"{timestamp}.{body}"` (raw body, `X-Account-Manager-Signature`, `X-Account-Manager-Timestamp` max. 300 s off), accepted once per `event_id` (`account_manager_webhook_events`, `model:prune` after 7 days), applied by the queued `ApplyAccountManagerEvent`. Always 204 for duplicates, stale and unknown types.
- Every event and API answer is a full snapshot: name, members by `oidc_sub` (unknown subjects → `household_pending_members`, attached on the first OIDC login), roles, all other members removed (their own lists are unshared, like leaving). Webhooks apply only `version > account_manager_version`, API answers always apply (current truth, also with a lower version, e.g. after a backup restore of the AccountManager). `household.deleted` → `deleteWithShares()` (lists stay with their owners). Events of unknown households are confirmed with `GET /api/households/{uuid}` (404: nothing is created; AccountManager down: the job is retried). After every change `syncLists()`/`touchLists()`, so clients gain or lose lists on the next pull.
- `households:sync` (daily 04:15): `GET /api/households?sub=` for every user with `oidc_sub`, mirrored households nobody listed are checked one by one (404 deletes). The OIDC login (and linking) attaches pending memberships and syncs the user; unlinking moves the user's memberships back to pending. AccountManager down: last state stays, warning in the log, the login works.
- Shares (optional, `ACCOUNT_MANAGER_REPORT_SHARES`): `ReportAccountManagerShares` sends the lists shared with a mirrored household (`task_list`, list id, icon + name) after sharing/unsharing, renaming or deleting a list and in the daily sync; only changes (`account_manager_shares_hash`), failures are ignored.
- Switching from `local`: set the driver and the AccountManager values, run `households:import`: proposes the local households without `account_manager_household_uuid` (members with `oidc_sub` only; households without any are skipped). An admin of the AccountManager confirms; the hourly `households:import --check` maps confirmed ones (`account_manager_household_uuid`, name and members from the snapshot, shares stay; if the household is mirrored already the shares move there and the local one is dropped) and marks rejected ones (`account_manager_import_status`, stay local and read only). Members without a linked login lose the household access on confirmation.
- Back to `local`: mirrored households become ordinary local households (management enabled again, `account_manager_household_uuid`/`account_manager_version` stay, pending members are ignored), shares stay. Switching to `account-manager` again overwrites them with the next newer snapshot.
- Deleting the account: in mirrored households the user only leaves (no hand-over, the AccountManager decides).
- UI texts name the AccountManager ("Manage households in “:name”", "Only in this app, not managed in “:name”." …): `App\Households\AccountManagerLabel::get()` = `ACCOUNT_MANAGER_LABEL`, otherwise the `name` (its `APP_NAME`) from the public `ACCOUNT_MANAGER_URL/api/info` (only asked with the account-manager driver; cached a day, a failure 10 minutes), otherwise "Account" («Konto»). The link goes to `AccountManagerHouseholds::portalUrl()` (`households_url` of `/api/info`, otherwise `ACCOUNT_MANAGER_URL/households`). Tests: `AccountManagerLabelTest`.

## Entitlements from the AccountManager

- Contract: AccountManager `docs/ENTITLEMENTS-API.md` (phase 6a), concept 5.3/6. Same design and names as RecipeManager
  (reference, its `AGENTS.md`) and Shoppinglist (same Fortify/PWA implementation). `config/entitlements.php`: `driver`
  (`ENTITLEMENTS_DRIVER`, `local` default), `admin_source` (`AUTH_ADMIN_SOURCE`, `local` default), `local.features`
  (`ENTITLEMENTS_LOCAL_FEATURES`, empty: no feature is gated yet); uses the `ACCOUNT_MANAGER_*` values, the client
  (`AccountManagerClient::entitlements()`, `GET /api/entitlements/{sub}`, 404 = no access) and the webhook of the households.
- `App\Entitlements\Entitlements` (static): `has($user, 'key')`, `features()`, `isAdmin()`, `status()`, `allowsAccess()`.
  Linked users (`oidc_sub`) get the stored snapshot with the account-manager driver, unlinked users the local behaviour.
  Admin: the app has no admin features; `isAdmin()` is prepared (emergency addresses = admins, `AUTH_ADMIN_SOURCE=
  account-manager`: the snapshot's flag for linked users) and unused.
- Storage `user_entitlements` (user_id unique, status, admin, features JSON, version, synced_at), dropped when `oidc_sub`
  changes; `App\Entitlements\AccountManagerEntitlements`: webhooks apply only `version > stored`, API answers always apply (current truth, also with a lower version, e.g. after a backup restore of the AccountManager), 404 sets
  `none` (version kept), unknown subjects are ignored (the OIDC login fetches the snapshot, 5 s, failures keep the last state),
  `entitlements:sync` daily 04:30. The webhook is open with mirrored households or synced entitlements; `entitlements.changed`
  is applied right away, `user.deleted` queued (`DeleteAccountManagerUser`).
- Access (driver account-manager): `EnsureEntitlementAccess` (web group) blocks linked users with status `suspended`,
  `requested` or `none`: JSON requests get 403 `{message, access}` (session kept), pages sign out with «Dein Zugang zu :app ist
  gesperrt.» / «… ist nicht freigeschaltet.» on the login page; the PWA sets the sync status `blocked`, shows the message and
  stops syncing (local data stays). The OIDC callback refuses blocked users.
- `user.deleted` = `App\Services\UserDeletion`, the same code as deleting the account on the account page
  (`AccountController`): own lists deleted for all members, shared ones left, households left with hand-over (mirrored: only
  leaving), sessions (database driver), then the user. Removing the access is no deletion (`status: none`).
- Tests: `AccountManagerEntitlementsTest`.

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
- Waste collection import ("Import collection dates", chip in the list and list menu, `openWasteDialog()`): an `.ics`/`.vcs` file (iCalendar 2.0 or vCalendar 1.0 with quoted-printable) is parsed on the device (`resources/js/ical-import.js`: VEVENTs only, not their VALARMs; local date of DTSTART, UTC in Europe/Zurich; simple RRULEs expanded), grouped by summary into tasks of the mode `dates` (icon by keyword, category "Waste" in the UI language («Entsorgung»), 1 point, reminder a day before) or merged into an existing `dates` task of the list. Optionally only future dates. Without a file the dates are entered in the task form. Tests: `tests/js/ical-import.test.js` with the REAL Luzern samples in `tests/fixtures/waste-calendar-luzern.*`. Changed dates are recorded in the history by their number only.
- Holidays (`#/absences`), calendar subscription (`/ical/<user id>-<HMAC>.ics`, derived from `users.ical_token` and the app key: VEVENTs from start to due date for 180 days, because calendar apps ignore VTODOs in subscriptions), invitations (`list_invites`, only the SHA-256 of the token stored): links for anybody (7 days) and personal ones (30 days) for existing verified users the owner shares with by email; `/invite/<token>` shows list, owner and what members see, joining needs a POST (confirmation). Unknown and unverified addresses are added right away and get a link to choose a password. Sharing and links are throttled, JSON export (client only), dark mode (class `dark`, palette swap in `resources/css/app.css`), category filter per list (device only).

- Easter egg: "All rights reserved." in the mail footer (published `resources/views/vendor/mail/{html,text}/message.blade.php`, nothing else of the mail views) links to `<ACCOUNT_MANAGER_URL>/easter-egg` (the page and the video live only in the AccountManager) via `App\Support\EasterEgg::url()`; without `ACCOUNT_MANAGER_URL` it stays plain text. Tests: `EasterEggMailTest`.

## Translations

- Languages: `de_CH` (default, Swiss spelling "ss", informal "du") and `en`; keys are the English texts.
- One source for server and client: `lang/de_CH.json` (+ `lang/de_CH/*.php` for Laravel's validation/auth texts). The app shell translates itself with `$t()` (`resources/js/i18n.js`), Blade pages, mails and push texts use `__()`. The client has no pluralisation: use separate keys (e.g. 1 point).
- History sentences avoid conjugation by name ("«Bad putzen» erledigt" + who below), so "Du" works.

## PWA, UI patterns, deployment

As in Shoppinglist: `vite-plugin-pwa` (generateSW, `registerType: 'prompt'`), app shell `/` without user data, `data-online` links, `<x-swipe-row>` actions (swipe or long press, `preferences.row_actions`), undo toasts (`resources/js/undo.js`, also used to take back completions), dialogs with history entries (`layers.js`), install instructions (`install.js`, `#/install`). Icon: the Narrenhaus jester in blue in a bucket with a check mark, master `src/logo.svg`, rendered to `src/favicon.png` (1024 px, transparent) → `ddev npm run favicon -- --source src/favicon.png --theme '#0369a1' --bg '#fafaf9' --padding-bg '#dbeefa' --clean`; the header shows `favicon-96x96.png`. Link preview image `public/og-image.png` (1200×630).

Deployment: `.forgejo/workflows/deploy.yml` (same as Shoppinglist, default URL `https://aufgaben.narrenhaus.ch`).

## Commands

- Tests: `ddev artisan test` (Unit + Feature, SQLite in memory) and `ddev npm test` (Vitest: schedule, ranking, helpers, schemas, templates, calendar import). Code style: `ddev exec vendor/bin/pint`.
- Assets: `ddev npm run build` (or `ddev npm run dev`).
- Local data: `ddev artisan migrate:fresh --seed` (test@example.com and anna@example.com, password `password`, a shared list with chores). Mails: Mailpit (`ddev launch -m`).
- Reminders: `ddev artisan reminders:send --dry-run`.
- Households from the AccountManager: `ddev artisan households:sync`, `ddev artisan households:import [--check]`.
- Entitlements from the AccountManager: `ddev artisan entitlements:sync`.

## Rules

- Alpine proxies must be copied to plain values before `insert`/`incrementalPatch` (DataCloneError).
- Every synced field must exist in the RxDB schema (`resources/js/db/schemas.js`), the resource document and the resource's `rules()`. Schema changes in RxDB need a schema version bump and migration strategy; if the server already fills a new field, also increase the collection in `REPLICATION_RUNS` (`resources/js/db/index.js`), so every client pulls it once more.
- Schedule changes always in both implementations plus a fixture case.
- Keep the app shell (`resources/views/app.blade.php`, partials) free of user data, it is cached by the service worker.
