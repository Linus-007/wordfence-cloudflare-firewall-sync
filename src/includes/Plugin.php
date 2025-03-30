<?php

declare(strict_types=1);

namespace WPCF\FirewallSync;

use WPCF\FirewallSync\Cloudflare\Client;
use WPCF\FirewallSync\Admin\Settings;
use WPCF\FirewallSync\Admin\Fields;
use WPCF\FirewallSync\Services\SyncScheduler;
use WPCF\FirewallSync\Services\BlockLogger;
use WPCF\FirewallSync\Services\MigrationManager;

final class Plugin {
  public static string $VERSION;

  public static function init(): void {
    self::get_version();
    self::define_constants();
    self::load_admin();
    self::load_services();

    load_plugin_textdomain(
      'wordfence-cloudflare-sync',
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

  private static function define_constants(): void {
    if (!defined('WPCF_FS_VERSION')) {
      define('WPCF_FS_VERSION', self::$VERSION);
    }

    if (!defined('WPCF_FS_PLUGIN_DIR')) {
      define('WPCF_FS_PLUGIN_DIR', __DIR__ . '/../index.php');
    }

    if (!defined('WPCF_FS_PLUGIN_URL')) {
      define('WPCF_FS_PLUGIN_URL', plugin_dir_url(__DIR__ . '/../index.php'));
    }
  }

  private static function load_admin(): void {
    if (is_admin()) {
      Settings::init();
      Fields::init();
    }
  }

  private static function load_services(): void {
    SyncScheduler::register();
    BlockLogger::create_table();
  }

  public static function activate(): void {
    self::define_constants();

    if (is_multisite() && isset($_GET['networkwide']) && $_GET['networkwide'] === '1') {
      foreach(get_sites(['fields' => 'ids']) as $blog_id) {
        switch_to_blog($blog_id);
        self::run_site_activation();
        restore_current_blog();
      }
    } else {
      self::run_site_activation();
    }
  }

  public static function run_site_activation(): void {
    $stored_version = get_option('firewall_sync_version');

    if ($stored_version === false) {
      BlockLogger::create_table();
    }

    if ($stored_version !== self::get_version()) {
      MigrationManager::run($stored_version);
      update_option('firewall_sync_version', self::get_version());
    }

    SyncScheduler::run_now();

    $options = get_option('firewall_sync_options');

    if (!isset($options['cloudflare_api_token']) || !isset($options['cloudflare_zone_id'])) {
      return;
    }

    $client = new Client($options['cloudflare_api_token'], $options['cloudflare_zone_id']);

    SyncScheduler::cleanup_expired($client);
    SyncScheduler::register();
  }

  public static function deactivate(): void {
    $options = get_option('firewall_sync_options');

    if (!isset($options['cloudflare_api_token']) || !isset($options['cloudflare_zone_id'])) {
      return;
    }

    $client = new Client($options['cloudflare_api_token'], $options['cloudflare_zone_id']);

    SyncScheduler::cleanup_expired($client);
    SyncScheduler::deactivate();
  }

  public static function run_migrations(?string $from_version): void {
    if ($from_version === null || version_compare($from_version, '1.0.0', '<')) {
      // Future migration logic here...
    }
  }
}