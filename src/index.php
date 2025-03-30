<?php
/**
 * Plugin Name: Wordfence Cloudflare Firewall Sync
 * Description: Sync Wordfence IP blocks to Cloudflare's WAF at the DNS level
 * Version: 1.0.0
 * Author: Mike Orozco
 * Author URI: https://mikeorozco.dev
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
