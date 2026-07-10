<?php

declare(strict_types=1);

namespace WPCF\FirewallSync;

final class Config {
  public const SITE_OPTION = 'firewall_sync_options';
  public const NETWORK_OPTION = 'firewall_sync_network_options';

  public const SOURCE_NETWORK = 'network';
  public const SOURCE_SITE = 'site';

  /**
   * Return the network-level configuration.
   *
   * On a single-site installation, get_site_option() falls back to the
   * ordinary Options API.
   */
  public static function get_network_options(): array {
    $options = get_site_option(self::NETWORK_OPTION, []);

    return is_array($options) ? $options : [];
  }

  /**
   * Return the current site's stored configuration.
   */
  public static function get_site_options(): array {
    $options = get_option(self::SITE_OPTION, []);

    return is_array($options) ? $options : [];
  }

  /**
   * Determine whether the network administrator permits individual sites
   * to use their own Cloudflare configuration.
   */
  public static function site_overrides_allowed(): bool {
    if (!is_multisite()) {
      return true;
    }

    $network_options = self::get_network_options();

    return !array_key_exists('allow_site_overrides', $network_options)
      || !empty($network_options['allow_site_overrides']);
  }

  /**
   * Return the configuration source selected for the current site.
   *
   * Existing installations do not yet have configuration_source stored.
   * When existing site configuration is present, retain it as a site
   * override so an upgrade does not silently replace working credentials.
   */
  public static function get_site_source(): string {
    if (!is_multisite()) {
      return self::SOURCE_SITE;
    }

    if (!self::site_overrides_allowed()) {
      return self::SOURCE_NETWORK;
    }

    $site_options = self::get_site_options();
    $source = $site_options['configuration_source'] ?? null;

    if (in_array($source, [self::SOURCE_NETWORK, self::SOURCE_SITE], true)) {
      return $source;
    }

    return self::has_existing_site_configuration($site_options)
      ? self::SOURCE_SITE
      : self::SOURCE_NETWORK;
  }

  /**
   * Return the settings that should be displayed in the current
   * administrative context.
   */
  public static function get_admin_options(): array {
    if (is_multisite() && is_network_admin()) {
      return self::get_network_options();
    }

    if (is_multisite() && self::uses_network_options()) {
      return self::get_network_options();
    }

    return self::get_site_options();
  }

  /**
   * Sanitize Cloudflare configuration fields.
   */
  public static function sanitize_options(array $input, bool $network = false): array {
    $output = [];

    $output['cloudflare_mode'] = in_array(
      $input['cloudflare_mode'] ?? 'zone_access_rules',
      ['zone_access_rules', 'account_list'],
      true
    )
      ? $input['cloudflare_mode']
      : 'zone_access_rules';

    foreach ([
      'cloudflare_api_token',
      'cloudflare_zone_id',
      'cloudflare_account_id',
      'cloudflare_list_id',
      'cloudflare_list_name',
    ] as $field) {
      $output[$field] = trim(
        sanitize_text_field((string) ($input[$field] ?? ''))
      );
    }

    $interval = (int) ($input['sync_interval'] ?? 60);
    $output['sync_interval'] = in_array($interval, [5, 15, 60], true)
      ? (string) $interval
      : '60';

    $lookback_hours = filter_var(
      $input['historical_lookback_hours'] ?? 24,
      FILTER_VALIDATE_INT
    );

    $output['historical_lookback_hours'] = in_array(
      $lookback_hours,
      [1, 3, 6, 12, 24],
      true
    )
      ? (string) $lookback_hours
      : '24';

    $minimum_events = filter_var(
      $input['historical_minimum_events'] ?? 1,
      FILTER_VALIDATE_INT
    );

    $output['historical_minimum_events'] = (
      $minimum_events !== false
      && $minimum_events >= 1
      && $minimum_events <= 100
    )
      ? (string) $minimum_events
      : '1';

    if ($network) {
      $output['allow_site_overrides'] =
        !empty($input['allow_site_overrides']) ? '1' : '0';
    } else {
      $source = $input['configuration_source'] ?? self::SOURCE_NETWORK;

      $output['configuration_source'] = in_array(
        $source,
        [self::SOURCE_NETWORK, self::SOURCE_SITE],
        true
      )
        ? $source
        : self::SOURCE_NETWORK;
    }

    return $output;
  }

  /**
   * Save Network Admin defaults.
   */
  public static function update_network_options(array $input): bool {
    return update_site_option(
      self::NETWORK_OPTION,
      self::sanitize_options($input, true)
    );
  }

  /**
   * Save the current site's configuration and inheritance selection.
   *
   * When a site switches to network inheritance, retain its previously
   * stored site-specific Cloudflare values. This allows the site to switch
   * back to its own configuration without re-entering credentials.
   */
  public static function update_site_options(array $input): bool {
    $existing = self::get_site_options();
    $default_source = is_multisite()
      ? self::SOURCE_NETWORK
      : self::SOURCE_SITE;

    $source = $input['configuration_source'] ?? $default_source;

    if (is_multisite() && $source === self::SOURCE_NETWORK) {
      $existing['configuration_source'] = self::SOURCE_NETWORK;

      return update_option(self::SITE_OPTION, $existing);
    }

    /*
     * Disabled fields are not included in an HTML form submission. Use a
     * previously stored site configuration when one exists. Otherwise,
     * initialize the new site override from the network defaults that were
     * displayed on the settings page.
     */
    $base = self::has_existing_site_configuration($existing)
      ? $existing
      : self::get_network_options();

    $merged = array_merge($base, $input);
    $merged['configuration_source'] = self::SOURCE_SITE;

    return update_option(
      self::SITE_OPTION,
      self::sanitize_options($merged, false)
    );
  }

  /**
   * Return the Cloudflare configuration that applies to the current site.
   */
  public static function get_effective_options(): array {
    if (self::get_site_source() === self::SOURCE_NETWORK) {
      return self::strip_control_fields(self::get_network_options());
    }

    return self::strip_control_fields(self::get_site_options());
  }

  /**
   * Return true when the current site is inheriting Network Admin settings.
   */
  public static function uses_network_options(): bool {
    return self::get_site_source() === self::SOURCE_NETWORK;
  }

  /**
   * Remove settings that control inheritance rather than Cloudflare itself.
   */
  private static function strip_control_fields(array $options): array {
    unset(
      $options['allow_site_overrides'],
      $options['configuration_source']
    );

    return $options;
  }

  /**
   * Detect settings from releases that predate multisite configuration
   * inheritance.
   */
  private static function has_existing_site_configuration(array $options): bool {
    foreach ([
      'cloudflare_api_token',
      'cloudflare_zone_id',
      'cloudflare_account_id',
      'cloudflare_list_id',
      'cloudflare_list_name',
      'cloudflare_mode',
      'sync_interval',
      'historical_lookback_hours',
      'historical_minimum_events',
    ] as $key) {
      if (isset($options[$key]) && $options[$key] !== '') {
        return true;
      }
    }

    return false;
  }
}
