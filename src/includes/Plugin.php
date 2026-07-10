<?php

declare(strict_types=1);

namespace WPCF\FirewallSync;

use WPCF\FirewallSync\Admin\Settings;
use WPCF\FirewallSync\Admin\Fields;
use WPCF\FirewallSync\Services\SyncScheduler;
use WPCF\FirewallSync\Services\BlockLogger;
use WPCF\FirewallSync\Services\MigrationManager;

final class Plugin {
  public static string $VERSION;
  public static string $TEXTDOMAIN;

  public static function init(): void {
    self::get_version();
    self::get_text_domain();
    self::define_constants();
    self::maybe_run_upgrade();
    self::register_multisite_hooks();
    self::load_admin();
    self::load_services();

    load_plugin_textdomain(
      self::get_text_domain(),
      false,
      dirname(plugin_basename(__DIR__ . '/../index.php')) . '/languages'
    );
  }

  public static function get_version(): string {
    if (!isset(self::$VERSION)) {
      $plugin_file = plugin_dir_path(__DIR__ . '/../index.php') . 'index.php';
      $plugin_data = get_file_data($plugin_file, ['Version' => 'Version']);
      self::$VERSION = $plugin_data['Version'] ?? '0.0.0';
    }

    return self::$VERSION;
  }

  public static function get_text_domain(): string {
    if (!isset(self::$TEXTDOMAIN)) {
      $plugin_file = plugin_dir_path(__DIR__ . '/../index.php') . 'index.php';
      $plugin_data = get_file_data($plugin_file, ['Text Domain' => 'Text Domain']);
      self::$TEXTDOMAIN = $plugin_data['Text Domain'];
    }

    return self::$TEXTDOMAIN;
  }

  private static function define_constants(): void {
    if (!defined('WPCF_FS_VERSION')) {
      define('WPCF_FS_VERSION', self::get_version());
    }

    if (!defined('WPCF_FS_PLUGIN_DIR')) {
      define('WPCF_FS_PLUGIN_DIR', plugin_dir_path(__DIR__ . '/../index.php'));
    }

    if (!defined('WPCF_FS_PLUGIN_URL')) {
      define('WPCF_FS_PLUGIN_URL', plugin_dir_url(__DIR__ . '/../index.php'));
    }
  }

  /**
   * Register lifecycle handling for sites created after network activation.
   */
  private static function register_multisite_hooks(): void {
    if (!is_multisite()) {
      return;
    }

    add_action(
      'wp_initialize_site',
      [self::class, 'initialize_new_site'],
      200,
      2
    );
  }

  /**
   * Initialize plugin data and schedules for a newly created network site.
   *
   * This runs only when the plugin is active across the network. A plugin
   * activated separately on individual sites should not initialize every
   * new site automatically.
   */
  public static function initialize_new_site(
    \WP_Site $new_site,
    array $args
  ): void {
    if (!self::is_network_active()) {
      return;
    }

    switch_to_blog((int) $new_site->blog_id);

    try {
      self::run_site_activation();
    } finally {
      restore_current_blog();
    }
  }

  /**
   * Run database and option migrations when the installed plugin version
   * changes. WordPress does not run activation hooks during ordinary plugin
   * updates, so upgrade migrations must also be checked during initialization.
   */
  private static function maybe_run_upgrade(): void {
    if (is_multisite() && self::is_network_active()) {
      $network_version = get_site_option(
        'firewall_sync_network_version'
      );

      $network_version = is_string($network_version)
        ? $network_version
        : null;

      if ($network_version === self::get_version()) {
        return;
      }

      foreach (get_sites(['fields' => 'ids']) as $blog_id) {
        switch_to_blog((int) $blog_id);

        try {
          self::run_site_activation();
        } finally {
          restore_current_blog();
        }
      }

      update_site_option(
        'firewall_sync_network_version',
        self::get_version()
      );

      return;
    }

    $stored_version = get_option('firewall_sync_version');
    $stored_version = is_string($stored_version)
      ? $stored_version
      : null;

    if ($stored_version !== self::get_version()) {
      self::run_site_activation();
    }
  }

  /**
   * Determine whether this plugin is active across the multisite network.
   */
  private static function is_network_active(): bool {
    if (!is_multisite()) {
      return false;
    }

    $active_plugins = get_site_option(
      'active_sitewide_plugins',
      []
    );

    if (!is_array($active_plugins)) {
      return false;
    }

    $plugin_basename = plugin_basename(
      WPCF_FS_PLUGIN_DIR . 'index.php'
    );

    return array_key_exists($plugin_basename, $active_plugins);
  }

  private static function load_admin(): void {
    if (is_admin()) {
      Settings::register();
      Fields::register();
    }
  }

  private static function load_services(): void {
    SyncScheduler::register();
  }

  public static function activate(bool $network_wide = false): void {
    self::define_constants();

    if (is_multisite() && $network_wide) {
      foreach (get_sites(['fields' => 'ids']) as $blog_id) {
        switch_to_blog((int) $blog_id);

        try {
          self::run_site_activation();
        } finally {
          restore_current_blog();
        }
      }

      update_site_option(
        'firewall_sync_network_version',
        self::get_version()
      );

      return;
    }

    self::run_site_activation();
  }

  public static function run_site_activation(): void {
    $stored_version = get_option('firewall_sync_version');
    $stored_version = is_string($stored_version) ? $stored_version : null;

    if ($stored_version !== self::get_version()) {
      MigrationManager::run($stored_version);
      update_option('firewall_sync_version', self::get_version());
    }

    SyncScheduler::register();
  }

  public static function deactivate(bool $network_wide = false): void {
    if (is_multisite() && $network_wide) {
      foreach (get_sites(['fields' => 'ids']) as $blog_id) {
        switch_to_blog((int) $blog_id);

        try {
          SyncScheduler::deactivate();
        } finally {
          restore_current_blog();
        }
      }

      return;
    }

    SyncScheduler::deactivate();
  }
}