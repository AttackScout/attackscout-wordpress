=== AttackScout ===
Contributors: attackscout
Tags: security, ssl, dmarc, headers, dashboard
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See what attackers can see of your site from the Internet: security grade from A to F, upcoming deadlines, PHP and WordPress versions.

== Description ==

AttackScout audits what anyone can see of a site from the Internet: email authentication (SPF, DKIM, DMARC), DNS, TLS certificate, HTTP security headers, exposed files and software versions. It grades the result from A to F and explains how to fix each point.

This plugin brings that grade into your WordPress administration:

* a dashboard widget with the grade of your site, the number of points to fix and the three main ones, with a link to the step-by-step fixes;
* the upcoming deadlines found by the audit: domain name expiry, certificate expiry, end of support of a software;
* your PHP and WordPress versions, with the updates WordPress already knows about;
* a button to run the audit again;
* a link to monitor the site on attackscout.com and get an email before a deadline (certificate, domain name).

The plugin is available in English, French, Spanish, German, Italian and Portuguese.

The AttackScout service opens on November 30, 2026. Until then, an audit started from the plugin ends with the message "The service will be available on Nov 30, 2026." and nothing else happens.

The audit **only runs when an administrator clicks "Run the audit"**. Nothing is sent when the plugin is installed or activated, or when a page is displayed. Only users with the `manage_options` capability see the widget and the settings.

All features of the plugin are free. A free AttackScout API key is needed: the "Create a free key" link in the plugin takes you to your AttackScout account, where the key is created in one minute. Paid AttackScout plans add a full audit after proof of domain ownership, outside this plugin.

== External services ==

This plugin connects to the AttackScout API, a service run by AttackScout (https://attackscout.com), to audit your site.

* **What is sent, and when**: the domain name of your site (for example `example.com`) and your AttackScout API key, only when an administrator clicks "Run the audit". The key is sent in the `Authorization` header of the request to `https://attackscout.com/api/v1/public/scans`.
* **What is not sent**: nothing at installation, activation or when a page is displayed (no request is made by the public pages of your site, and the plugin has no scheduled task). No identifier of your site or of you, no list of plugins, no statistics.
* **What AttackScout does with it**: it audits what is public on the Internet about this domain (DNS records, certificate, home page headers…), with an identifiable probe (`AttackScout-Probe`), and returns the result. The result is shown only to you, through a private link.
* **Links to attackscout.com** ("Create a free key", fixes, monitoring) carry `utm_source=wordpress&utm_medium=plugin` and a campaign name (`wordpress-cle`, `dashboard`), so that AttackScout can count the visits that come from the plugin. They contain no identifier of your site or of you. They are plain links, opened in a new tab **only when you click them**: the plugin makes no request when it displays them. The "Create a free key" link points to `https://attackscout.com/espace/cles`, where you sign in or sign up for a free account to create your key.
* Terms of use: https://attackscout.com/en/terms-of-use
* Privacy policy: https://attackscout.com/en/privacy
* How the probe works and how to stop it: https://attackscout.com/en/probe

== Installation ==

1. Install the plugin from the Plugins screen, or upload the `attackscout` folder to `/wp-content/plugins/`, then activate it.
2. Click "Create a free key" (on the dashboard widget or in Settings > AttackScout). On the page that opens in your AttackScout account ("API keys"), create a key: channel "WordPress", scope `scans:write` only. The key is shown only once.
3. In WordPress, go to Settings > AttackScout, paste the key and save.
4. Click "Run the audit". The grade then appears on the dashboard.

To remove the key, use "Remove the key" on the same page. Deleting the plugin removes its options (key and latest summary).

== Frequently Asked Questions ==

= Does the plugin slow down my site? =

No. Pages only read the summary stored in the options. The API is called only when an administrator clicks "Run the audit".

= My site runs on localhost or a local domain. =

AttackScout can only audit a site that is reachable from the Internet. The button is not shown for a local address.

= Can the audit be run automatically? =

Not in this version: each audit is started by a click. The result of an audit is reused for 24 hours by AttackScout.

= Do I need to pay or to give a card to get the key? =

No. The key is free, and so is the plugin. The "Create a free key" link opens your AttackScout account in a new tab; nothing is sent to AttackScout until you click it.

= Is my key safe? =

The key is stored in the options of your site and never shown again in full. It only allows running public audits (scope `scans:write`). You can revoke it at any time in your AttackScout account.

== Screenshots ==

1. The dashboard widget: grade, main points to fix, deadlines, PHP and WordPress versions.
2. Settings > AttackScout: API key, "Run the audit" button and what it sends.

== Changelog ==

= 0.1.0 =
* First version: dashboard widget, settings page, audit on demand, "Create a free key" link, links to the fixes and to monitoring, translations in French, Spanish, German, Italian and Portuguese.
* Before the opening of the service (November 30, 2026), an unreachable API gives a clear message instead of an error.

== Upgrade Notice ==

= 0.1.0 =
First version.
