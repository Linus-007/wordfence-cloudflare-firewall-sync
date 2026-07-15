<?php
/**
 * Plugin Name: Grey Rock Block Synchroniser for Wordfence and Cloudflare
 * Plugin URI: https://github.com/Linus-007/grey-rock-block-synchroniser-for-wordfence-and-cloudflare
 * Description: Synchronises current and historical Wordfence firewall blocks with Cloudflare Zone Access Rules or an account-level IP list.
 * Version: 1.2.1
 * Requires at least: 6.0
 * Tested up to: 7.0
 * Requires PHP: 8.1
 * Author: Greyscale Zone
 * Author URI: https://greyscale.zone/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: grey-rock-block-synchroniser-for-wordfence-and-cloudflare
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
