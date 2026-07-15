# Grey Rock Block Synchroniser for Wordfence and Cloudflare

Grey Rock Block Synchroniser for Wordfence and Cloudflare synchronises IP addresses blocked by Wordfence with Cloudflare so unwanted traffic can be stopped at Cloudflare's network edge before it reaches the WordPress server.

![Version](https://img.shields.io/badge/version-1.2.1-blue)
![Tested with WordPress 7.0.1](https://img.shields.io/badge/WordPress-tested%20with%207.0.1-blueviolet)
![Licence](https://img.shields.io/badge/licence-GPLv2-blue)

> **Important:** This plugin is not affiliated with Wordfence or Cloudflare.

## Name and identity

**Grey Rock Block Synchroniser for Wordfence and Cloudflare** is developed by Greyscale Zone.

The name *Grey Rock* reflects the defensive principle of remaining unresponsive and unrewarding to hostile or manipulative behaviour. Automated attackers similarly depend on finding systems that respond predictably or expose useful weaknesses. The plugin applies that concept by moving Wordfence block intelligence to Cloudflare's network edge.

The public package uses these technical identifiers:

- plugin directory: `grey-rock-block-synchroniser-for-wordfence-and-cloudflare`;
- main plugin file: `grey-rock-block-synchroniser-for-wordfence-and-cloudflare.php`;
- GitHub repository: `grey-rock-block-synchroniser-for-wordfence-and-cloudflare`;
- release ZIP: `grey-rock-block-synchroniser-for-wordfence-and-cloudflare.zip`.

Existing WordPress option names, hooks, database table names and the `WPCF\FirewallSync` PHP namespace remain unchanged for backwards compatibility.

## What the plugin does

The plugin reads current Wordfence blocks and historical Wordfence WAF events recorded as `blocked:waf`, then sends qualifying public IP addresses to Cloudflare using one of two modes.

The plugin does not assume that a particular private Wordfence PHP method is available. When the installed Wordfence version does not expose its active-block API, Grey Rock continues using the verified historical WAF event table instead of terminating synchronization.

Historical synchronization is configurable:

- lookback period: 1, 3, 6, 12 or 24 hours;
- minimum blocked events per IP address: 1 through 100;
- default lookback: 24 hours;
- default threshold: 1 event.

Repeated events from the same address are deduplicated before synchronization. Private, reserved and invalid addresses are rejected.

### Zone Access Rules

Creates Cloudflare blocks for one zone.

Use this mode when the Wordfence blocks should protect one Cloudflare zone.

### Account IP List

Adds addresses to a reusable account-level Cloudflare IP list.

Use this mode when several domains or Cloudflare zones should share the same list.

**An Account IP List does not block traffic by itself.** You must create a Cloudflare Custom Rule in every zone that should use the list.

## Compatibility

- Requires WordPress 6.0 or later.
- Tested with WordPress 7.0.1.
- Requires PHP 8.1 or later.

## Requirements

You need:

- WordPress;
- Wordfence installed and active;
- a Cloudflare account containing the relevant domain or domains;
- a restricted Cloudflare API token;
- the Cloudflare identifiers required by the selected mode.

A Cloudflare Global API Key is not required and should not be used.

## Installation

### GitHub release

1. Open the repository Releases page.
2. Download `grey-rock-block-synchroniser-for-wordfence-and-cloudflare.zip`.
3. In WordPress, open **Plugins → Add Plugin → Upload Plugin**.
4. Select the ZIP file.
5. Install and activate the plugin.
6. Open **Firewall Sync** in the WordPress administration menu.

### Repository installation

Clone the repository:

```bash
git clone https://github.com/Linus-007/grey-rock-block-synchroniser-for-wordfence-and-cloudflare.git
```

Copy the contents of `src/` into:

```text
wp-content/plugins/grey-rock-block-synchroniser-for-wordfence-and-cloudflare/
```

The installed directory should contain:

```text
grey-rock-block-synchroniser-for-wordfence-and-cloudflare/
├── grey-rock-block-synchroniser-for-wordfence-and-cloudflare.php
├── readme.txt
├── uninstall.php
├── assets/
├── includes/
└── languages/
```

## Multisite administration

When the plugin is network activated:

- **Network Admin** manages the shared Cloudflare configuration, scheduling method and synchronization interval.
- **Synchronise Network Now** processes every site that inherits the Network Admin configuration.
- `sync-network --due` and `sync-network --force` process only sites that inherit the Network Admin configuration.
- Sites using independent site-specific settings are excluded from network synchronization and continue to use their own controls.
- Saving Network Admin scheduling settings reschedules every inheriting site.
- Selecting **External scheduler** or **Manual synchronization only** removes Grey Rock's synchronization WP-Cron event from every inheriting site.
- Hourly cleanup remains scheduled separately from synchronization.
- Each site retains its own **Synchronisation Log** and **Manual IP Block** pages.
- Network Admin provides a combined **Synchronisation Log** containing recent records from all sites.
- Manual Account List Management requires a reason when adding an address and stores that reason with the Cloudflare list item.
- Cleanup and reconciliation remain available only where the Cloudflare destination and synchronization ownership records are isolated to one site.

## Cloudflare API token permissions

Create a dedicated restricted API token for this plugin.

Open:

```text
Cloudflare Dashboard → My Profile → API Tokens → Create Token → Create Custom Token
```

### Zone Access Rules mode

Add these permissions:

| Scope | Resource | Permission |
|---|---|---|
| Zone | Firewall Services | Edit |
| Zone | Zone | Read |

Restrict the token to the specific zone or zones the plugin should manage.

The plugin does not require DNS editing permission.

Required plugin settings:

- Cloudflare API Token
- Cloudflare Zone ID

### Account IP List mode

Add this permission:

| Scope | Resource | Permission |
|---|---|---|
| Account | Account Rule Lists | Edit |

Restrict the token to the Cloudflare account containing the list.

Cloudflare documentation may refer to this capability as **Account Rule Lists Write**. In the Cloudflare dashboard it is commonly displayed as **Account Rule Lists: Edit**.

The token must be able to:

- read the list;
- read existing list items;
- add IP addresses;
- remove IP addresses.

Required plugin settings:

- Cloudflare API Token
- Cloudflare Account ID

Recommended setting:

- Cloudflare List Name

## Finding Cloudflare identifiers

Cloudflare identifiers are normally 32-character hexadecimal values.

### Zone ID

1. Open the required domain in the Cloudflare dashboard.
2. Open the zone **Overview** page.
3. Locate **Zone ID** in the API section.
4. Paste it into **Cloudflare Zone ID**.

### Account ID

1. Open the required Cloudflare account.
2. Open an account or zone overview page.
3. Locate **Account ID** in the API section.
4. Paste it into **Cloudflare Account ID**.

### List ID and List Name

1. Open the Cloudflare dashboard.
2. Select the required account.
3. Open **Manage Account → Configurations → Lists**.
4. Create or open an IP list.
6. Copy the actual list name into **Cloudflare List Name**.

The List ID and List Name are different values.

| Value | Example | Used for |
|---|---|---|
| List ID | `0123456789abcdef0123456789abcdef` | Plugin API requests |
| List Name | `wordfence_hot_blocklist` | Cloudflare Custom Rule expressions |

Enter the List Name in the plugin without a `$` character.

Cloudflare list names use:

- lowercase letters;
- numbers;
- underscores.

Do not use spaces, hyphens or capital letters.

Example:

```text
wordfence_hot_blocklist
```

## Making an Account IP List block traffic

The plugin populates the list. It does not automatically create the per-zone security rule.

For every Cloudflare zone that should use the list, open:

```text
Cloudflare zone → Security → WAF → Custom rules
```

Create a Custom Rule with the **Block** action.

### Block listed IP addresses throughout the zone

```text
ip.src in $wordfence_hot_blocklist
```

Replace `wordfence_hot_blocklist` with the actual Cloudflare List Name.

The `$` character tells Cloudflare that the name refers to a list variable.

### Block listed IP addresses for one hostname

```text
ip.src in $wordfence_hot_blocklist
and http.host eq "example.com"
```

### Block listed IP addresses for several hostnames

```text
ip.src in $wordfence_hot_blocklist
and http.host in {"example.com" "www.example.com"}
```

### Use one list with several domains

The same account-level list can be referenced by Custom Rules in several Cloudflare zones within the same account.

Example for `example.com`:

```text
ip.src in $wordfence_hot_blocklist
```

Example for `example.net`:

```text
ip.src in $wordfence_hot_blocklist
```

The plugin updates the account list once. Every Custom Rule that references the list uses the updated contents.

## Configuring the plugin

Open:

```text
WordPress Admin → Grey Rock Block Synchroniser
```

For multisite network settings:

```text
Network Admin → Grey Rock Block Synchroniser
```

Complete the setup in this order:

1. Select **Zone Access Rules** or **Account IP List**.
2. Enter the Cloudflare Account ID when using Account IP List mode.
3. Paste the restricted Cloudflare API token.
4. Enter the remaining identifiers required by the selected mode.
5. In Account IP List mode, enter the actual Cloudflare List Name.
6. Select **WordPress WP-Cron**, **External scheduler** or **Manual synchronization only**.
7. Select the synchronization interval.
8. Save the settings.
9. Select **Validate Saved Cloudflare Configuration**.
10. Run a test block with an IP address that is not already intentionally blocked.
11. Confirm the address was added and removed.
12. Select **Sync Now**.
13. Verify the resulting rule or list entry in Cloudflare.

## Scheduling

Grey Rock separates the synchronization policy configured in WordPress from the mechanism that wakes the plugin.

The GUI remains authoritative for the scheduling method and synchronization interval. Available intervals are:

- every minute;
- every 5 minutes;
- every 15 minutes; and
- every hour.

### WordPress WP-Cron

**WordPress WP-Cron** is the default and requires no server-level configuration.

Grey Rock registers its synchronization event at the selected interval. WP-Cron is request-driven, so an event becomes eligible at the selected time but may run later when the site receives traffic or WordPress otherwise processes cron events.

Selecting a one-minute interval does not guarantee execution on an exact minute boundary.

### External scheduler

**External scheduler** removes Grey Rock's synchronization WP-Cron event. A system scheduler then invokes a plugin-specific WP-CLI command.

The plugin does not require Docker. WP-CLI can run against an ordinary WordPress installation, a multisite network, a managed-hosting cron facility or a containerized deployment.

Single-site installation:

```bash
wp --path=/var/www/html \
  grey-rock-block-synchroniser-for-wordfence-and-cloudflare \
  sync-site --due
```

One selected site in a multisite network:

```bash
wp --path=/var/www/html \
  --url=https://example.com \
  grey-rock-block-synchroniser-for-wordfence-and-cloudflare \
  sync-site --due
```

All multisite sites inheriting Network Admin settings:

```bash
wp --path=/var/www/html \
  grey-rock-block-synchroniser-for-wordfence-and-cloudflare \
  sync-network --due
```

Replace `/var/www/html` with the actual WordPress installation path.

The external scheduler may invoke `--due` every minute regardless of the GUI interval. Grey Rock reads the saved interval and exits without synchronizing when the interval has not elapsed.

A scheduler that runs less frequently than the GUI interval becomes the limiting interval.

A skipped `--due` invocation exits successfully. For `sync-site`, WP-CLI reports that the site is not due or that External scheduler is not selected. For `sync-network`, the completion summary reports successful, not-due and disabled site counts.

Add `--quiet` to unattended commands to suppress routine informational output.

### Due-time behavior

Grey Rock records the start of every synchronization attempt.

This means:

- a successful scheduled run resets the due interval;
- a failed synchronization attempt also resets the due interval;
- selecting **Sync Now** or **Synchronise Network Now** resets the due interval; and
- a `--force` command resets the due interval.

The next `--due` invocation waits until the selected GUI interval has elapsed since that recorded attempt.

### Forced and manual synchronization

The following commands run immediately and ignore both the scheduling method and the configured interval:

```bash
wp --path=/var/www/html \
  grey-rock-block-synchroniser-for-wordfence-and-cloudflare \
  sync-site --force
```

```bash
wp --path=/var/www/html \
  grey-rock-block-synchroniser-for-wordfence-and-cloudflare \
  sync-network --force
```

These commands use the same synchronization services as **Sync Now** and **Synchronise Network Now**.

### Manual synchronization only

**Manual synchronization only** removes Grey Rock's synchronization WP-Cron event and disables automatic synchronization.

The GUI synchronization buttons and `--force` commands remain available.

### Synchronization locking

Every site-level synchronization acquires an atomic WordPress option lock before processing Wordfence and Cloudflare data.

The lock prevents a WP-Cron event, external scheduler, WP-CLI command or GUI action from running the same site's synchronization concurrently.

An abandoned lock becomes stale after 15 minutes. A later synchronization attempt may then replace it.

In multisite network synchronization, each site maintains its own lock and due-time record.

### Cleanup scheduling

Cleanup is separate from synchronization.

Grey Rock keeps its cleanup event scheduled hourly in all three scheduling modes:

- WordPress WP-Cron;
- External scheduler; and
- Manual synchronization only.

Selecting External scheduler removes only Grey Rock's synchronization WP-Cron event. It does not disable the hourly cleanup event and does not disable WordPress cron globally.

### Standard Linux systemd example

The following complete units run multisite network synchronization every minute. The plugin still obeys the interval selected in the GUI.

Determine the actual WP-CLI path first:

```bash
command -v wp
```

Example service file:

`/etc/systemd/system/grey-rock-block-synchroniser-for-wordfence-and-cloudflare.service`

```ini
[Unit]
Description=Grey Rock Block Synchroniser for Wordfence and Cloudflare
Documentation=https://github.com/Linus-007/grey-rock-block-synchroniser-for-wordfence-and-cloudflare
Wants=network-online.target
After=network-online.target
ConditionPathExists=/var/www/html/wp-config.php

[Service]
Type=oneshot
User=www-data
Group=www-data
WorkingDirectory=/var/www/html
ExecStart=/usr/local/bin/wp --path=/var/www/html grey-rock-block-synchroniser-for-wordfence-and-cloudflare sync-network --due --quiet
TimeoutStartSec=5min
StandardOutput=journal
StandardError=journal
SyslogIdentifier=grey-rock-block-synchroniser-for-wordfence-and-cloudflare
```

Replace `/usr/local/bin/wp`, `/var/www/html`, `www-data` and the group when the server uses different values.

Example timer file:

`/etc/systemd/system/grey-rock-block-synchroniser-for-wordfence-and-cloudflare.timer`

```ini
[Unit]
Description=Run Grey Rock Block Synchroniser every minute

[Timer]
OnCalendar=*-*-* *:*:00
AccuracySec=1s
RandomizedDelaySec=0
Persistent=true
Unit=grey-rock-block-synchroniser-for-wordfence-and-cloudflare.service

[Install]
WantedBy=timers.target
```

Validate and enable the units:

```bash
sudo systemd-analyze verify \
  /etc/systemd/system/grey-rock-block-synchroniser-for-wordfence-and-cloudflare.service \
  /etc/systemd/system/grey-rock-block-synchroniser-for-wordfence-and-cloudflare.timer

sudo systemctl daemon-reload

sudo systemctl enable --now \
  grey-rock-block-synchroniser-for-wordfence-and-cloudflare.timer
```

Verify operation:

```bash
sudo systemctl list-timers \
  grey-rock-block-synchroniser-for-wordfence-and-cloudflare.timer \
  --all

sudo systemctl status \
  grey-rock-block-synchroniser-for-wordfence-and-cloudflare.timer

sudo journalctl \
  -u grey-rock-block-synchroniser-for-wordfence-and-cloudflare.service \
  --since "10 minutes ago"
```

For a single-site installation, use the same complete service file but replace `sync-network` with `sync-site`.

For one selected site in a multisite network, replace the service's `ExecStart` line with:

```ini
ExecStart=/usr/local/bin/wp --path=/var/www/html --url=https://example.com grey-rock-block-synchroniser-for-wordfence-and-cloudflare sync-site --due --quiet
```

### Traditional cron or hosting control-panel example

A system crontab or hosting control panel can invoke the same deployment-neutral WP-CLI command.

Multisite network:

```cron
* * * * * cd /var/www/html && /usr/local/bin/wp --path=/var/www/html grey-rock-block-synchroniser-for-wordfence-and-cloudflare sync-network --due --quiet
```

Single site:

```cron
* * * * * cd /var/www/html && /usr/local/bin/wp --path=/var/www/html grey-rock-block-synchroniser-for-wordfence-and-cloudflare sync-site --due --quiet
```

Do not configure both systemd and traditional cron for the same installation.

### Optional Docker Compose wrapper

Docker is not required by the plugin. A containerized installation may wrap the same WP-CLI command with Docker Compose.

Example command:

```bash
/usr/bin/docker compose \
  -f /srv/wordpress/compose/docker-compose.yml \
  exec -T wordpress \
  wp grey-rock-block-synchroniser-for-wordfence-and-cloudflare \
  sync-network --due --quiet --allow-root
```

A complete Docker Compose systemd service example is:

```ini
[Unit]
Description=Grey Rock Block Synchroniser for Wordfence and Cloudflare
Documentation=https://github.com/Linus-007/grey-rock-block-synchroniser-for-wordfence-and-cloudflare
Wants=network-online.target
After=network-online.target docker.service
Requires=docker.service
ConditionPathExists=/srv/wordpress/compose/docker-compose.yml

[Service]
Type=oneshot
User=wordpress
Group=wordpress
SupplementaryGroups=docker
WorkingDirectory=/srv/wordpress/compose
ExecStart=/usr/bin/docker compose -f /srv/wordpress/compose/docker-compose.yml exec -T wordpress wp grey-rock-block-synchroniser-for-wordfence-and-cloudflare sync-network --due --quiet --allow-root
TimeoutStartSec=5min
StandardOutput=journal
StandardError=journal
SyslogIdentifier=grey-rock-block-synchroniser-for-wordfence-and-cloudflare
```

Use the same complete timer file shown in the standard systemd example.

Replace the example user, group, Compose path and service name with the values used by the deployment.

### Switching scheduling methods safely

To move from WP-Cron to an external scheduler:

1. Create and test the external command manually.
2. In Network Admin or Site Admin, select **External scheduler**.
3. Save the settings.
4. Confirm that `--due` runs successfully.
5. Enable the system timer or cron job.

To return to WP-Cron:

1. Disable the system timer or remove the cron job.
2. Select **WordPress WP-Cron** in the plugin settings.
3. Select the required interval.
4. Save the settings.
5. Confirm that Grey Rock's synchronization event is scheduled again.

To disable automatic synchronization completely:

1. Disable any external system scheduler.
2. Select **Manual synchronization only**.
3. Save the settings.

### Verifying the saved policy

Display the saved network scheduling policy:

```bash
wp --path=/var/www/html eval '
$options = get_site_option(
  "firewall_sync_network_options",
  []
);

echo "schedule_method="
  . ($options["schedule_method"] ?? "wp_cron")
  . PHP_EOL;

echo "sync_interval="
  . ($options["sync_interval"] ?? "60")
  . PHP_EOL;
'
```

List Grey Rock cron events for a site:

```bash
wp --path=/var/www/html \
  --url=https://example.com \
  cron event list \
  --fields=hook,next_run_relative,recurrence
```

In External scheduler or Manual synchronization only mode, `firewall_sync_cron_event` should be absent. `firewall_sync_cleanup_event` should remain scheduled hourly.

## Testing the configuration

### Validate Saved Cloudflare Configuration

Validation confirms that:

- the API token is accepted;
- the token can access the selected zone or account list;
- the supplied identifiers exist;
- the required read permissions are available.

A successful validation does not prove that an Account IP List Custom Rule exists. Verify the Custom Rule separately in Cloudflare.

### Run Test Block

The test:

1. adds the supplied IP address to Cloudflare;
2. immediately removes it;
3. reports whether both operations succeeded.

Do not use:

- your current public IP address;
- an address already intentionally blocked;
- a production address that must remain blocked.

In Account IP List mode, the plugin will not remove a pre-existing list entry used for testing.

## Multisite operation

| Configuration | Synchronisation | Cleanup | Reconciliation | Log |
|---|---:|---:|---:|---|
| Network configuration inherited by a site | Yes, additive | Disabled | Disabled | Per site |
| Site-specific override | Yes | Enabled | Enabled | Per site |
| Single-site installation | Yes | Enabled | Enabled | Per site |

### Inherited network configuration

Each site reads its own Wordfence block list and adds addresses to the shared Cloudflare destination.

Cleanup and reconciliation are disabled because one site's local log cannot determine whether another site still requires the same Cloudflare entry.

### Site-specific configuration

A site-specific override uses its own Cloudflare settings and local ownership records. Cleanup and reconciliation remain available.

## Manual account-list management

When **Account IP List** mode is selected, the settings page provides:

- **Add IP Address**
- **Remove IP Address**

Enter a valid IPv4 or IPv6 address.

A manually added address remains in the configured Cloudflare account list until it is removed manually or by another authorised Cloudflare operation.

Removing an address that is already absent is treated as a successful final state.

The manual controls manage the configured list only. Cloudflare blocks the listed addresses only when a Custom Rule references the list, for example:

    ip.src in $wordfence_hot_blocklist

## Cleanup and retry behaviour

- Failed addresses are retried.
- Automatic retries stop after three failed attempts.
- A successful retry resets the failure count.
- Temporary Wordfence expiration times are recorded.
- Expired entries are removed from Cloudflare during cleanup.
- Local ownership records are removed only after Cloudflare confirms deletion.
- An entry already absent from Cloudflare is treated as successfully removed.

## Troubleshooting

### HTTP 401 or 403

Check that:

- the API token was copied completely;
- the correct account or zone is included in the token resources;
- the selected plugin mode matches the token permissions;
- the token has not expired or been revoked.

### HTTP 404

Check:

- Zone ID;
- Account ID;
- List ID;
- account and zone restrictions on the token.

### The Account IP List contains addresses, but traffic is not blocked

Create a Cloudflare Custom Rule using the actual List Name:

```text
ip.src in $wordfence_hot_blocklist
```

The hidden internal List ID is handled automatically and is not used in a Custom Rule expression.

### The list expression is rejected

Correct:

```text
ip.src in $wordfence_hot_blocklist
```

Incorrect because `$` is missing:

```text
ip.src in wordfence_hot_blocklist
```

Incorrect because the name contains capitals and hyphens:

```text
ip.src in $wordfence_hot_blocklist
```

### Wordfence block data is unavailable

Confirm that Wordfence is installed, active and functioning on the current WordPress site.


## External services and privacy

The plugin communicates directly with the Cloudflare API over HTTPS using the WordPress HTTP API.

It sends the Cloudflare API token for authentication, configured account or zone identifiers, qualifying public IP addresses, block reasons and Cloudflare rule or list-item identifiers required for the requested operation.

It does not send telemetry or usage analytics to Greyscale Zone.

The complete external-service, privacy, retention and uninstall disclosures are maintained in `readme.txt`.

## Release building

The repository Makefile is the authoritative interface for validation, version checking, package creation and release tagging.

Validate the repository:

    make validate

Build and verify the release ZIP:

    make release VERSION=x.y.z

Create and push the annotated release tag only after the release build succeeds:

    make tag-release VERSION=x.y.z

The generated release file is:

    dist/grey-rock-block-synchroniser-for-wordfence-and-cloudflare.zip

## Changelog

### 1.2.1

- Corrected GitHub release automation to use the authoritative plugin entry point and Makefile release process.
- Added continuous integration across PHP 8.1, 8.2, 8.3 and 8.4.
- Corrected repository documentation for the plugin directory, main plugin file and release process.
- Updated the security policy to identify 1.2.x as the supported release series.
- Updated release commands so the release ZIP is built and verified before a release tag is created.
- Made no changes to synchronisation, Wordfence or Cloudflare runtime behaviour.

### 1.2.0

- Hardened synchronization locking with unique ownership tokens, atomic stale-lock replacement and owner-only release.
- Centralized publicly routable IPv4 and IPv6 validation across Wordfence, administration and Cloudflare operations.
- Centralized validation and normalization for Cloudflare API tokens and resource identifiers.
- Unified Cloudflare JSON parsing and required valid success envelopes, result structures and pagination data.
- Corrected current-block retrieval so HTTP and response-shape failures fail closed.
- Limited Cloudflare response bodies to 1 MiB before decoding.
- Centralized Cloudflare HTTP transport policy with TLS verification, timeout, redirect and unsafe-URL controls.
- Normalized and length-limited Cloudflare comments, access-rule notes and synchronization-log reasons.
- Audited administrative actions for capabilities, nonces, sanitized request input and safe redirects.

### 1.1.12

- Added an every-minute synchronization interval.
- Added WordPress WP-Cron, external scheduler and manual-only scheduling modes.
- Added plugin-specific WP-CLI commands for due and forced site or network synchronization.
- Added synchronization due-time enforcement and overlap locking.
- Separated hourly cleanup maintenance from the synchronization interval.
- Centralized multisite network synchronization for the GUI and WP-CLI.
- Updated scheduling, WP-CLI and multisite documentation.

## Contributing

Please:

- use British English for user-facing text and documentation;
- never store or log Cloudflare API tokens;
- validate PHP syntax and repository whitespace;
- document changes affecting Cloudflare permissions or configuration.

## Licence

GPLv2.

## Disclaimer

This software is supplied without warranty. Test it in a non-production environment before relying on it for production security controls.
