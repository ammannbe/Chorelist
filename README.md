# Chorelist

Offline-first web app (PWA) for shared household chores, similar to the tasks of Flatastic. German and English.

- **Recurring tasks**: daily, weekly on chosen days, monthly (day, “4th Monday”, last day), yearly, some time after the last completion, once, or when needed. Optional time window (“one week to do it”), end date, pausing.
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
- Push notifications: `php artisan webpush:vapid` once, `VAPID_SUBJECT` (mailto: or https: URL) in `.env`. Keep the keys: new keys invalidate all push subscriptions.
- Apache: `public/.htaccess` sets the manifest MIME type and disables caching of `sw.js` and `push-sw.js` (needs `mod_headers`).

### Automatic deployment (Forgejo Actions)

`.forgejo/workflows/deploy.yml` deploys every push to `main`: builds the assets (incl. service worker) in CI, copies them via SSH, then runs `git pull`, `composer install --no-dev`, migrations and `optimize` on the server (`php85`).

- Secret: `SSH_KEY` (private deploy key)
- Variables: `SSH_HOST`, `SSH_USER`, `SSH_FINGERPRINT` (SHA256 ed25519 host key), `DEPLOY_PATH`, optional `PRIMARY_SITE_URL` (checked after the deploy)
- The server needs a git checkout of the repository in `DEPLOY_PATH` and a configured `.env`.

## License

AGPLv3 or later, see [LICENSE](LICENSE).
