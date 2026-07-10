# Greyrock Wordfence-Cloudflare Synchroniser

Greyrock Wordfence-Cloudflare Synchroniser synchronises IP addresses blocked by Wordfence with Cloudflare so unwanted traffic can be stopped at Cloudflare's network edge before it reaches the WordPress server.

![Version](https://img.shields.io/badge/version-1.1.5-blue)
![Tested with WordPress 7.0.1](https://img.shields.io/badge/WordPress-7.0.1-tested-blueviolet)
![Licence](https://img.shields.io/badge/licence-GPLv2-blue)

> **Important:** This plugin is not affiliated with Wordfence or Cloudflare.

## Name and identity

**Greyrock Wordfence-Cloudflare Synchroniser** is developed by Greyscale Zone.

The name *Greyrock* reflects the defensive principle of remaining unresponsive and unrewarding to hostile or manipulative behaviour. Automated attackers similarly depend on finding systems that respond predictably or expose useful weaknesses. The plugin applies that concept by moving Wordfence block intelligence to Cloudflare's network edge.

The existing technical identifiers are intentionally retained for compatibility:

- plugin directory: `wordfence-cloudflare-firewall-sync`;
- main plugin file: `index.php`;
- GitHub repository: `greyrock-wordfence-cloudflare-synchroniser`;
- release ZIP: `greyrock-wordfence-cloudflare-synchroniser.zip`;
- WordPress option names, hooks, database table names and text domain.

Retaining these identifiers allows WordPress to recognise an upgrade as the same installed plugin rather than a different plugin.

## What the plugin does

The plugin reads current Wordfence blocks and historical Wordfence WAF events recorded as `blocked:waf`, then sends qualifying public IP addresses to Cloudflare using one of two modes.

The plugin does not assume that a particular private Wordfence PHP method is available. When the installed Wordfence version does not expose its active-block API, Greyrock continues using the verified historical WAF event table instead of terminating synchronization.

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
2. Download `greyrock-wordfence-cloudflare-synchroniser.zip`.
3. In WordPress, open **Plugins → Add Plugin → Upload Plugin**.
4. Select the ZIP file.
5. Install and activate the plugin.
6. Open **Firewall Sync** in the WordPress administration menu.

### Repository installation

Clone the repository:

```bash
git clone https://github.com/Linus-007/greyrock-wordfence-cloudflare-synchroniser.git
```

Copy the contents of `src/` into:

```text
wp-content/plugins/wordfence-cloudflare-firewall-sync/
```

The installed directory should contain:

```text
wordfence-cloudflare-firewall-sync/
├── index.php
├── uninstall.php
├── assets/
├── includes/
└── languages/
```

## Multisite administration

When the plugin is network activated:

- **Network Admin** manages the shared Cloudflare configuration.
- **Synchronise Network Now** processes every site that inherits the Network Admin configuration.
- Sites using independent site-specific settings are excluded from the network action.
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
WordPress Admin → Firewall Sync
```

For multisite network settings:

```text
Network Admin → Firewall Sync
```

Complete the setup in this order:

1. Select **Zone Access Rules** or **Account IP List**.
2. Paste the restricted Cloudflare API token.
3. Enter the identifiers required by the selected mode.
4. In Account IP List mode, enter the actual Cloudflare List Name.
5. Select the synchronisation interval.
6. Save the settings.
7. Select **Validate Saved Cloudflare Configuration**.
8. Run a test block with an IP address that is not already intentionally blocked.
9. Confirm the address was added and removed.
10. Select **Sync Now**.
11. Verify the resulting rule or list entry in Cloudflare.

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
ip.src in $Greyrock-Wordfence-Blocks
```

### Wordfence block data is unavailable

Confirm that Wordfence is installed, active and functioning on the current WordPress site.

## Release building

Validate the source:

```bash
make validate
```

Build the WordPress-ready ZIP:

```bash
make build
```

Create a versioned local release:

```bash
make release VERSION=1.1.2
```

Create and push the Git tag to the writable `fork` remote:

```bash
make tag-release VERSION=1.1.2
```

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
