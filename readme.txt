=== Greyrock Wordfence-Cloudflare Synchroniser ===
Contributors: greyscalezone
Tags: wordfence, cloudflare, firewall, security, multisite
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.1.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Synchronises current and historical Wordfence firewall blocks with Cloudflare.

== Description ==

Greyrock Wordfence-Cloudflare Synchroniser sends qualifying Wordfence firewall blocks to Cloudflare so hostile traffic can be stopped at Cloudflare's network edge before it reaches the WordPress server.

The plugin supports two Cloudflare destinations.

= Zone Access Rules =

Creates Cloudflare IP block rules for one zone.

Use this mode when Wordfence blocks should protect one Cloudflare zone.

= Account IP List =

Adds IP addresses to a reusable Cloudflare account-level IP list.

Use this mode when several domains or Cloudflare zones should share the same list.

An Account IP List does not block traffic by itself. You must create a Cloudflare Custom Rule with the Block action in every zone that should use the list.

Example rule:

`ip.src in $wordfence_hot_blocklist`

The recommended list name is:

`wordfence_hot_blocklist`

= Current and historical Wordfence blocks =

The plugin can synchronise:

* Current Wordfence blocks when the installed Wordfence version exposes its active-block interface.
* Historical Wordfence Web Application Firewall events recorded as `blocked:waf`.

Historical synchronisation is configurable:

* Lookback period: 1, 3, 6, 12 or 24 hours.
* Minimum blocked events per IP address: 1 through 100.
* Default lookback: 24 hours.
* Default threshold: 1 event.

Repeated events from the same address are deduplicated before synchronisation.

Invalid, private and reserved IP addresses are rejected during historical-event processing.

= Multisite support =

When network activated:

* Network Admin can provide shared Cloudflare settings.
* Individual sites may inherit the network configuration.
* Site-specific overrides can be permitted by Network Admin.
* Network Admin provides a Synchronise Network Now action.
* Network Admin provides a combined Synchronisation Log.
* Individual sites retain their own synchronisation logs and manual IP block pages.
* Sites using independent settings retain their own site-level controls.

= Manual management and diagnostics =

The plugin provides:

* Cloudflare configuration validation.
* A diagnostic add-and-remove test.
* Manual account-list add and remove controls.
* A required reason for manually added account-list entries.
* Manual site-level IP blocking.
* Synchronisation logs.
* Cleanup and reconciliation where Cloudflare-entry ownership is isolated to one site.

= Wordfence compatibility =

Wordfence does not provide a stable public API for every block source used by this plugin.

Greyrock checks whether the installed Wordfence active-block interface is available before using it. When that interface is unavailable, historical WAF synchronisation continues through the Wordfence event table.

A future Wordfence release could change its internal class or database schema. Such a change may require a Greyrock compatibility update.

= Independence and trademarks =

Greyrock Wordfence-Cloudflare Synchroniser is developed independently by Greyscale Zone.

This plugin is not affiliated with, endorsed by or sponsored by Wordfence or Cloudflare. Wordfence and Cloudflare are trademarks of their respective owners.

== External services ==

This plugin connects to the Cloudflare API when an administrator:

* Validates saved Cloudflare settings.
* Runs a diagnostic block test.
* Manually adds or removes an IP address.
* Runs synchronisation, cleanup or reconciliation.
* Allows a scheduled synchronisation or cleanup event to run.

The Cloudflare API is required because the plugin's purpose is to create and remove Cloudflare firewall entries.

Depending on the configured mode and operation, the plugin sends some or all of the following data to Cloudflare:

* The Cloudflare API token in an HTTPS Authorization header.
* Cloudflare Account ID.
* Cloudflare Zone ID.
* Cloudflare account-list name or internal list identifier.
* Public IPv4 or IPv6 addresses blocked by Wordfence.
* A short comment describing the Wordfence or manual block reason.
* Cloudflare list-item or firewall-rule identifiers when removing entries.

The plugin retrieves Cloudflare account lists, list items and firewall access rules so it can validate settings, avoid duplicates, reconcile state and remove entries.

Communication is sent directly from the WordPress server to Cloudflare over HTTPS using the WordPress HTTP API.

The plugin does not send WordPress post content, user passwords, email addresses or the Cloudflare token to Greyscale Zone. It does not provide Greyscale Zone with telemetry or usage analytics.

By configuring and using Cloudflare functions in this plugin, the administrator directs the plugin to transmit the described information to Cloudflare.

Cloudflare Terms of Service:

https://www.cloudflare.com/terms/

Cloudflare Privacy Policy:

https://www.cloudflare.com/privacypolicy/

Cloudflare API documentation:

https://developers.cloudflare.com/api/

== Privacy ==

The plugin stores its configuration in the WordPress database. This includes the Cloudflare API token and Cloudflare identifiers entered by an administrator.

The plugin stores synchronised public IP addresses, block reasons, timestamps, expiration information and retry state in a site-specific WordPress database table.

IP addresses may constitute personal data under some privacy laws. Site administrators are responsible for establishing a lawful basis, retention policy and appropriate disclosure for their use of Wordfence, Cloudflare and this plugin.

Historical entries receive an expiration based on the configured lookback period. Temporary active Wordfence blocks may use the Wordfence expiration time.

Manually added Cloudflare account-list entries remain until an authorised administrator or another authorised Cloudflare operation removes them.

Uninstalling the plugin removes its local plugin options and tables according to the included uninstall routine. It does not automatically remove every entry previously sent to Cloudflare. Administrators should review the Cloudflare destination when permanently discontinuing the plugin.

== Installation ==

1. Install and activate Wordfence.
2. Install and activate Greyrock Wordfence-Cloudflare Synchroniser.
3. Open Greyrock Synchroniser in WordPress administration.
4. Select Zone Access Rules or Account IP List mode.
5. Create a restricted Cloudflare API token.
6. Enter the Cloudflare identifiers required for the selected mode.
7. Save the settings.
8. Validate the saved Cloudflare configuration.
9. Run the diagnostic test block.
10. Configure the historical WAF lookback and event threshold.
11. Run synchronisation.

For multisite installations, network activate the plugin and configure shared settings from Network Admin when appropriate.

= Zone Access Rules mode =

Required settings:

* Cloudflare API Token
* Cloudflare Zone ID

Required Cloudflare token permissions:

* Zone - Firewall Services: Edit
* Zone - Zone: Read

= Account IP List mode =

Required settings:

* Cloudflare API Token
* Cloudflare Account ID
* Cloudflare List Name

Required Cloudflare token permission:

* Account - Account Rule Lists: Edit

The plugin resolves the Cloudflare list's internal identifier automatically.

After configuring the list, create a Cloudflare Custom Rule with the Block action in every zone that should use it.

== Frequently Asked Questions ==

= Does the plugin require a Cloudflare Global API Key? =

No. Use a restricted Cloudflare API token.

= Does an Account IP List block traffic by itself? =

No. Create a Cloudflare Custom Rule with the Block action in every zone that should use the list.

= Can one Cloudflare list protect several domains? =

Yes. Account IP List mode can maintain one reusable account-level list. Each Cloudflare zone must have a Custom Rule that references the list.

= Does the plugin synchronise historical Wordfence firewall events? =

Yes. It reads qualifying Wordfence WAF events recorded as `blocked:waf` within the configured lookback period.

= What historical lookback periods are available? =

1, 3, 6, 12 and 24 hours.

= Can I require several Wordfence block events before an IP is sent to Cloudflare? =

Yes. The historical block threshold accepts whole numbers from 1 through 100.

= What happens if Wordfence does not expose its active-block interface? =

The plugin continues using historical Wordfence WAF events instead of terminating synchronisation.

= Does the plugin support WordPress multisite? =

Yes. It supports shared Network Admin settings, optional site-specific overrides, network synchronisation for inheriting sites and a combined Network Admin synchronisation log.

= Why are cleanup and reconciliation unavailable on some inheriting sites? =

An inheriting site may share a Cloudflare destination with other sites. Its local log cannot determine whether another site still requires the same Cloudflare entry.

= Does uninstalling the plugin remove Cloudflare entries? =

No. Review and remove unwanted Cloudflare rules or list entries separately after uninstalling the plugin.

= Is the plugin affiliated with Wordfence or Cloudflare? =

No.

== Changelog ==

= 1.1.6 =

* Added WordPress.org-compatible licensing and metadata.
* Added Cloudflare external-service documentation.
* Added privacy and data-retention documentation.
* Aligned the translation domain with the proposed WordPress.org slug.
* Added separate GitHub-compatible and WordPress.org-compatible release packages.
* Included `readme.txt` in release packages.

= 1.1.5 =

* Added configurable historical Wordfence WAF lookback.
* Added a configurable minimum historical-event threshold.
* Added numeric validation for historical settings.
* Added Network Admin synchronisation for sites inheriting shared settings.
* Added a combined Network Admin synchronisation log.
* Added reasons for manually added Cloudflare account-list entries.
* Prevented fatal errors when Wordfence does not expose `wfBlock::getBlocks()`.
* Updated compatibility metadata for WordPress 7.0.

= 1.1.2 =

* Added Cloudflare Account IP List mode.
* Added automatic list-identifier resolution from the visible list name.
* Added manual account-list add and remove controls.
* Added Cloudflare configuration validation and diagnostic block testing.
* Corrected Cloudflare account-list item deletion.
* Updated Greyrock branding and release packaging.

== Upgrade Notice ==

= 1.1.6 =

Adds WordPress.org metadata, external-service disclosure, privacy documentation and separate submission packaging.
