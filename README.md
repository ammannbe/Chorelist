# Chorelist

Offline-first web app (PWA) for shared household chores, similar to the tasks of Flatastic. German and English.

- **Recurring tasks**: daily, weekly on chosen days, monthly (day, “4th Monday”, last day), yearly, some time after the last completion, once, on specific dates, or when needed. Optional time window (“one week to do it”), end date, pausing.
- **Waste collection calendars**: import the collection dates of your municipality from a calendar file (.ics/.vcs), one task per type of waste.
- **Reminders** by push and/or email per kind of notification, at your reminder time; overdue notices, weekly summary.
- **History** of who did, skipped, postponed or changed what; missed dates.
- **Points ranking** per list (optional, per week, month or all time), fair with holidays, and personal suggestions which task is worth doing next.
- **Works offline**: all lists live on the device and sync in the background as soon as a connection is available.
- **Share lists**: invite others by email or with an invitation link, or share with a household (family, flat) so all its members see the list; changes of all members are merged.
- **Calendar subscription** (iCal), steps per task, categories, dark mode, data export.
- **Installable**: add it to the home screen on Android and iOS, no app store needed.
- **Passkeys**: log in with Face ID, Touch ID, Windows Hello or the device PIN.

Built with Laravel, Alpine.js and RxDB. See [AGENTS.md](AGENTS.md) for the architecture.

## Development

Requires [DDEV](https://ddev.com).

```bash
ddev start
ddev composer install
ddev npm install
ddev artisan key:generate
ddev artisan migrate --seed   # test@example.com and anna@example.com / password
ddev artisan webpush:vapid     # keys for push notifications
ddev npm run dev
```

- Tests: `ddev artisan test`
- Code style: `ddev exec vendor/bin/pint`

## Deployment (shared hosting, e.g. KeyHelp)

- PHP 8.3+, MariaDB/MySQL, HTTPS (required for service worker and offline mode).
- Document root: `public/`.
- `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build`, `php artisan migrate --force`, `php artisan optimize`.
- Cron jobs, every minute (same as RecipeManager):
  ```
  * * * * *  cd /path-to-the-project && php artisan schedule:run >> /dev/null 2>&1
  * * * * *  cd /path-to-the-project && php artisan queue:work --max-time=50 >> /dev/null 2>&1
  ```
  The scheduler sends the reminders (`reminders:send` every 5 minutes), the queue the mails and push notifications.
- Login with an OpenID Connect provider (optional, e.g. Pocket ID): `AUTH_OIDC_*` in `.env` (see `config/login.php`). An account linked to the provider logs in only there (no password, no passkeys, no password reset); keep the `AUTH_LOCAL_EMERGENCY_EMAILS` accounts unlinked as local emergency access. Way back to passwords: `php artisan users:unlink-oidc <email>` (or `--all`), then "Forgot your password?".
- Households from the AccountManager (optional): `HOUSEHOLDS_DRIVER=account-manager`, `ACCOUNT_MANAGER_URL`, `ACCOUNT_MANAGER_SERVICE_KEY`, `ACCOUNT_MANAGER_SECRET` in `.env`; in the AccountManager (Services → edit) the webhook URL `<APP_URL>/api/account-manager/webhook` and a secret. Needs OIDC login (members are matched by `oidc_sub`). Switching from local households: `php artisan households:import` (an admin of the AccountManager confirms the proposals, the hourly `households:import --check` maps them). Contract: AccountManager `docs/HOUSEHOLDS-API.md`.
- Entitlements from the AccountManager (optional, needs OIDC login): `ENTITLEMENTS_DRIVER=account-manager` with the same `ACCOUNT_MANAGER_*` values and webhook: a suspended or removed access of a linked user signs them out (the app shows the reason). Snapshots are fetched on the OIDC login and daily (`entitlements:sync`); `user.deleted` deletes the account like the account page does. Contract: AccountManager `docs/ENTITLEMENTS-API.md`.
- Push notifications: `php artisan webpush:vapid` once, `VAPID_SUBJECT` (mailto: or https: URL) in `.env`. Keep the keys: new keys invalidate all push subscriptions.
- Apache: `public/.htaccess` sets the manifest MIME type and disables caching of `sw.js` and `push-sw.js` (needs `mod_headers`).

### Automatic deployment (Forgejo Actions)

`.forgejo/workflows/deploy.yml` deploys every push to `main`: builds the assets (incl. service worker) in CI, copies them via SSH, then runs `git pull`, `composer install --no-dev`, migrations and `optimize` on the server (`php85`).

- Secret: `SSH_KEY` (private deploy key)
- Variables: `SSH_HOST`, `SSH_USER`, `SSH_FINGERPRINT` (SHA256 ed25519 host key), `DEPLOY_PATH`, optional `PRIMARY_SITE_URL` (checked after the deploy)
- The server needs a git checkout of the repository in `DEPLOY_PATH` and a configured `.env`.

## License

AGPLv3 or later, see [LICENSE](LICENSE).
