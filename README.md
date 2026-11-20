# AttackScout for WordPress

Brings the AttackScout security grade of your site into the WordPress dashboard: grade A to F, the points to fix, upcoming deadlines (domain, certificates, end of support) and PHP/WordPress versions.

Part of [AttackScout](https://attackscout.com/en?utm_source=github&utm_medium=repo&utm_campaign=attackscout-wordpress): what an attacker can see of your site from the Internet, explained simply, with the fix.

## Installation

1. Download this repository (or the release zip) and copy the `attackscout` folder to `wp-content/plugins/`.
2. Activate **AttackScout** in Plugins.
3. Settings > AttackScout: create a free key with the **Create a free key** button ([https://attackscout.com/espace/cles?utm_source=wordpress&utm_medium=plugin&utm_campaign=wordpress-cle](https://attackscout.com/espace/cles?utm_source=wordpress&utm_medium=plugin&utm_campaign=wordpress-cle); channel `wordpress`, scope `scans:write`) and paste it.
4. Click **Run the audit**.

## Privacy and wordpress.org rules

Nothing leaves your site until an administrator clicks **Run the audit**; the only data sent is the site's domain name, with the API key, to `https://attackscout.com`. No tracking, no external scripts, no scheduled task. See the `External services` section of [`attackscout/readme.txt`](attackscout/readme.txt).

## Limits

Free account quotas, per API key: 600 requests per hour and 100 audit requests per day (300 per day across all the keys of an account). A result less than 24 hours old is served from cache and still counts. Over quota the API answers HTTP 429 with a `Retry-After` header.

## Development

PHP 7.4+. `composer install`, then `composer test`, `composer lint`, `composer analyse` (see `composer.json`).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
