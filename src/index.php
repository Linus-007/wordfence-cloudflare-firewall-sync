<?php
/**
 * Plugin Name: Greyrock Wordfence-Cloudflare Synchroniser
 * Plugin URI: https://greyscale.zone/
 * Description: Synchronises Wordfence IP blocks with Cloudflare Zone Access Rules or a Cloudflare account-level IP list.
 * Version: 1.1.5
 * Requires at least: 6.0
 * Tested up to: 7.0
 * Requires PHP: 8.1
 * Author: Greyscale Zone
 * Author URI: https://greyscale.zone/
 * Text Domain: wordpress-cloudflare-sync
 * Domain Path: /languages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

spl_autoload_register(function (string $class): void {
  $prefix = 'WPCF\\FirewallSync\\';
  $base_dir = __DIR__ . '/includes/';

  if (strpos($class, $prefix) !== 0) return;

  $relative_class = str_replace($prefix, '', $class);
  $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

  if (file_exists($file)) {
    require $file;
  }
});

add_action('plugins_loaded', static function (): void {
  if (class_exists('WPCF\\FirewallSync\\Plugin')) {
    \WPCF\FirewallSync\Plugin::init();
  }
});

register_activation_hook(__FILE__, ['WPCF\\FirewallSync\\Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['WPCF\\FirewallSync\\Plugin', 'deactivate']);
