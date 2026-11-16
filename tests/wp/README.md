# WordPress tests for People (hosts)

These run inside a WordPress install, against a fake Google Calendar API, so they need no Google account.

1. Install WordPress (SQLite works: the `sqlite-database-integration` drop-in) and activate this plugin.
2. Copy `fake-google.php` to `wp-content/mu-plugins/`. It answers every Google call: each refresh token is one account, with its own busy times and events, and it logs calls and emails to options.
3. On a fresh database run each test:

```
wp eval-file tests/wp/hosts-test.php   # two people: hidden until connected, separate busy times, parallel bookings, cancel and hourly sync with the right account
wp eval-file tests/wp/owner-test.php   # a site without people works as before
```

Each prints PASS/FAIL lines and a total. Never install `fake-google.php` on a real site.
