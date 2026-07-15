=== Grey Rock Block Synchroniser for Wordfence and Cloudflare ===
Contributors: greyscalezone
Tags: wordfence, cloudflare, firewall, security, multisite
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Make your WordPress site boring to repeat attackers by synchronising qualifying Wordfence firewall blocks with Cloudflare.

== Description ==

Grey Rock Block Synchroniser for Wordfence and Cloudflare sends qualifying Wordfence firewall blocks to Cloudflare so hostile traffic can be stopped at Cloudflare's network edge before it reaches the WordPress server.

= Why Grey Rock? =

The name Grey Rock is inspired by the grey rock method: becoming uninteresting and unrewarding to someone seeking attention or a reaction.

Grey Rock applies that concept to hostile website traffic. Wordfence identifies qualifying blocked IP addresses, and Grey Rock synchronises them with Cloudflare. Cloudflare can then stop those addresses at the network edge before their requests reach WordPress.

The objective is simple: make your website boring to repeat attackers. Instead of allowing the same hostile traffic to keep reaching the server, Grey Rock helps the site respond with less exposure, less interaction and fewer consumed server resources.

Grey Rock does not replace Wordfence, Cloudflare or a layered security program. It connects them so qualifying Wordfence blocks can be enforced earlier, closer to the source of the traffic.

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

= Scheduling =

Grey Rock supports three scheduling methods:

* WordPress WP-Cron schedules synchronization inside WordPress.
* External scheduler removes Grey Rock's synchronization WP-Cron event and permits a system scheduler to invoke WP-CLI.
* Manual synchronization only disables automatic synchronization while retaining the GUI buttons and forced WP-CLI commands.

Available intervals are every minute, every 5 minutes, every 15 minutes and hourly.

WP-Cron is request-driven. Selecting every minute makes synchronization eligible every minute but does not guarantee execution at an exact minute boundary.

External scheduling does not require Docker. An ordinary WordPress installation can use:

`wp --path=/var/www/html grey-rock-block-synchroniser-for-wordfence-and-cloudflare sync-site --due`

A multisite network can use:

`wp --path=/var/www/html grey-rock-block-synchroniser-for-wordfence-and-cloudflare sync-network --due`

`sync-network` processes only sites inheriting Network Admin settings. A selected multisite site can use `sync-site` with WP-CLI's `--url` parameter.

An external scheduler may check every minute. The `--due` command reads the GUI interval and exits successfully without synchronizing when the interval has not elapsed or External scheduler is not selected.

The GUI buttons and `--force` commands run immediately regardless of scheduling method or interval. Every attempt, including a manual or failed attempt, resets the due interval.

A site-level atomic lock prevents overlapping synchronization. An abandoned lock becomes stale after 15 minutes.

Selecting External scheduler or Manual synchronization only removes only Grey Rock's synchronization event. It does not disable WordPress cron globally.

Cleanup is separate maintenance and remains scheduled hourly in all three modes.

Complete systemd, traditional cron, hosting control-panel and optional Docker Compose examples are provided in the GitHub README.

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

Grey Rock checks whether the installed Wordfence active-block interface is available before using it. When that interface is unavailable, historical WAF synchronisation continues through the Wordfence event table.

A future Wordfence release could change its internal class or database schema. Such a change may require a Grey Rock compatibility update.

= Independence and trademarks =

Grey Rock Block Synchroniser for Wordfence and Cloudflare is developed independently by Greyscale Zone.

This plugin is not affiliated with, endorsed by or sponsored by Wordfence or Cloudflare. Wordfence and Cloudflare are trademarks of their respective owners.

== External services ==

This plugin connects to the Cloudflare API when an administrator:

* Validates saved Cloudflare settings.
* Runs a diagnostic block test.
* Manually adds or removes an IP address.
* Runs synchronisation, cleanup or reconciliation.
* Allows a WP-Cron or external-scheduler synchronization, or an hourly cleanup event, to run.

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
2. Install and activate Grey Rock Block Synchroniser for Wordfence and Cloudflare.
3. Open Grey Rock Block Synchroniser in WordPress administration.
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

* Cloudflare Account ID
* Cloudflare API Token
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

= Does the every-minute interval run at an exact time? =

Not necessarily when WordPress WP-Cron is selected. WP-Cron is request-driven and may run late. Use External scheduler with a reliable system scheduler when timing must not depend on WordPress traffic.

= Is Docker required for external scheduling? =

No. The plugin provides ordinary WP-CLI commands. Docker Compose is only an optional deployment-specific wrapper.

= How does an external scheduler use the GUI interval? =

The scheduler may invoke `sync-site --due` or `sync-network --due` every minute. Grey Rock reads the selected GUI interval and exits successfully without synchronizing until that interval has elapsed.

= What is the difference between --due and --force? =

`--due` works only with External scheduler and obeys the configured interval. `--force` runs immediately regardless of the scheduling method or interval.

= What happens after a manual or failed synchronization attempt? =

Grey Rock records the start of every attempt. The next `--due` invocation waits until the configured interval has elapsed, even when the previous attempt failed or was started manually.

= How are overlapping runs prevented? =

Each site acquires an atomic synchronization lock. An abandoned lock becomes stale after 15 minutes.

= Does External scheduler disable all WordPress cron events? =

No. It removes only Grey Rock's synchronization event. Hourly Grey Rock cleanup remains scheduled, and other WordPress cron events are unaffected.

= How do I return to WordPress WP-Cron? =

Disable the external system timer or cron job, select WordPress WP-Cron in Grey Rock settings, select the required interval and save the settings.

= Does uninstalling the plugin remove Cloudflare entries? =

No. Review and remove unwanted Cloudflare rules or list entries separately after uninstalling the plugin.

= Is the plugin affiliated with Wordfence or Cloudflare? =

No.

== Screenshots ==

1. Configure Cloudflare Account IP List mode, synchronisation interval, historical WAF lookback, and block threshold from Network Admin.
2. Validate the Cloudflare configuration, test blocking, manage list entries manually, and synchronise the multisite network.
3. Review recent site-specific synchronisation activity across the WordPress multisite network.
4. View the Cloudflare Account IP List created and maintained by Grey Rock Block Synchroniser.
5. Review synchronised and manually managed IP addresses stored in the configured Cloudflare list.
6. Configure a Cloudflare Custom Rule to block requests from addresses contained in the synchronised IP list.

== Changelog ==

= 1.2.1 =

* Corrected GitHub release automation to use the authoritative plugin entry point and Makefile release process.
* Added continuous integration across PHP 8.1, 8.2, 8.3 and 8.4.
* Corrected repository documentation for the plugin directory, main plugin file and release process.
* Updated the security policy to identify 1.2.x as the supported release series.
* Updated release commands so the release ZIP is built and verified before a release tag is created.
* No synchronisation, Wordfence or Cloudflare runtime behaviour changed.

= 1.2.0 =

* Hardened synchronization locking with unique ownership tokens, atomic stale-lock replacement and owner-only release.
* Added centralized validation for publicly routable IPv4 and IPv6 addresses.
* Added centralized validation and normalization for Cloudflare API tokens, Zone IDs, Account IDs, List IDs and List Names.
* Rejected private, reserved, loopback, link-local, documentation, benchmarking, multicast and unspecified addresses.
* Unified Cloudflare JSON parsing and required valid response envelopes with `success: true`.
* Added strict Cloudflare response-result and pagination validation.
* Corrected current-block retrieval so HTTP failures and malformed responses fail closed.
* Limited Cloudflare response bodies to 1 MiB before JSON decoding.
* Added explicit HTTPS transport settings, including TLS verification, a 30-second timeout, a three-redirect limit and unsafe-URL rejection.
* Normalized and length-limited Cloudflare comments, access-rule notes and synchronization-log reasons.
* Hardened Cloudflare identifiers returned by the API before using or caching them.
* Audited all administrative actions for capability checks, action-specific nonces, sanitized input and safe redirects.

= 1.1.12 =

* Added an every-minute synchronization interval.
* Added WordPress WP-Cron, external scheduler and manual-only scheduling modes.
* Added plugin-specific WP-CLI commands for due and forced site or network synchronization.
* Added synchronization due-time enforcement and overlap locking.
* Separated hourly cleanup maintenance from the synchronization interval.
* Centralized multisite network synchronization for the GUI and WP-CLI.
* Added deployment-neutral scheduling documentation, including standard WP-CLI, systemd, traditional cron, hosting control-panel and optional Docker Compose examples.

= 1.1.11 =

* Added a Why Grey Rock explanation to the plugin directory description.
* Updated the short description to explain the goal of making the site less rewarding to repeat attackers.
* Added six screenshot captions for the WordPress.org Plugin Directory.

= 1.1.10 =

* Moved Cloudflare Account ID before Cloudflare API Token.
* Left-aligned the Cloudflare Tests controls.
* Moved Validate Saved Cloudflare Configuration above the test IP field.
* Moved Run Test Block directly below the test IP field.
* Updated documentation to match the administration interface.


= 1.1.9 =

* Renamed the plugin to Grey Rock Block Synchroniser for Wordfence and Cloudflare.
* Changed the plugin slug and text domain to `grey-rock-block-synchroniser-for-wordfence-and-cloudflare`.
* Renamed the main plugin file to `grey-rock-block-synchroniser-for-wordfence-and-cloudflare.php`.
* Moved administration JavaScript to `assets/admin.js`.
* Enqueued administration JavaScript through WordPress.
* Updated repository URLs, release packaging and translation metadata.


= 1.1.8 =

* Changed all branding from Grey Rock to Grey Rock.
* Changed the plugin slug and text domain to `grey-rock-block-synchroniser-for-wordfence-and-cloudflare`.
* Changed the release ZIP and plugin directory name to `grey-rock-block-synchroniser-for-wordfence-and-cloudflare`.
* Renamed the translation template to match the new text domain.
* Changed PHP global prefixes from `grey_rock_` to `grey_rock_`.
* Updated repository and documentation URLs for the Grey Rock name.


= 1.1.7 =

* Added direct-access protection to the synchronisation log table.
* Corrected log-table pagination input handling and output escaping.
* Added required translator comments for placeholder strings.
* Sanitised submitted settings arrays before configuration processing.
* Documented nonce verification for the shared scope helper.
* Removed obsolete manual translation-domain loading.

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
* Updated Grey Rock branding and release packaging.

== Upgrade Notice ==

= 1.2.1 =

Maintenance release correcting release automation, continuous integration, repository documentation and supported-version metadata. Runtime synchronisation behaviour is unchanged.

= 1.2.0 =

Security-focused release that hardens synchronization locking, public-IP and Cloudflare credential validation, API response handling, HTTP transport and stored reason text.
