<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Services;

use WPCF\FirewallSync\Cloudflare\Client;
use WPCF\FirewallSync\Services\BlockLogger;

final class SyncScheduler {
  private const HOOK = 'firewall_sync_cron_event';
  private const DELETE_BATCH_SIZE = 100;
  private const CLEANUP_HOOK = 'firewall_sync_cleanup_event';

  public static function register(): void {
    add_action(self::HOOK, [self::class, 'run_now']);
    add_action(self::CLEANUP_HOOK, [self::class, 'run_cleanup']);
    add_filter('cron_schedules', [self::class, 'custom_intervals']);
    
    $minutes = max(5, (int) ($options['sync_interval'] ?? 60));
    $interval_key = $minutes === 5 ? 'every_5_minutes' : ($minutes === 15 ? 'every_15_minutes' : 'hourly');

    if (!wp_next_scheduled(self::HOOK)) {
      wp_schedule_event(time(), $interval_key, self::HOOK);
    }

    if (!wp_next_scheduled(self::CLEANUP_HOOK)) {
      wp_schedule_event(time(), $interval_key, self::CLEANUP_HOOK);
    }
  }

  public static function custom_intervals(array $schedules): array {
    $schedules['every_5_minutes'] = [
      'interval' => 300,
      'display' => __('Every 5 Minutes', 'wordfence-cloudflare-sync')
    ];

    $schedules['every_15_minutes'] = [
      'interval' => 900,
      'display' => __('Every 15 Minutes', 'wordfence-cloudflare-sync')
    ];

    return $schedules;
  }

  public static function run_now(): bool {
    $options = get_option('firewall_sync_options');
    $token = $options['cloudflare_api_token'] ?? '';
    $zone = $options['cloudflare_zone_id'] ?? '';

    if (empty($token) || empty($zone)) {
      return false;
    }

    if (!class_exists('\wfBlock')) {
      return false;
    }

    $client = new Client($token, $zone);
    $blocks = \wfBlock::getBlocks();

    foreach ($blocks as $block) {
      $ip = $block['ip'] ?? null;
      $reason = $block['reason'] ?? __('Unknown', 'wordfence-cloudflare-sync');
      $expiration = (int) ($block['expirationUnix'] ?? 0);
      $is_permanent = $block['permanent'] ?? false;

      if (
        !$ip ||
        (!$is_permanent && time() > $expiration) ||
        BlockLogger::has_synced($ip)
      ) {
        continue;
      }

      $success = $client->create_block($ip);

      if ($success) {
        BlockLogger::log($ip, 'sync: ' . $reason, $expires);
      }
    }

    update_option('firewall_sync_last_run', current_time('mysql'));
    delete_option('firewall_sync_is_running');

    return true;
  }

  public static function run_cleanup(): void {
    global $wpdb;
    
    $options = get_option('firewall_sync_options');
    $token = $options['cloudflare_api_token'] ?? '';
    $zone = $options['cloudflare_zone_id'] ?? '';

    if (empty($token) || empty($zone)) {
      return;
    }

    $client = new Client($token, $zone);
    
    $table = $wpdb->prefix . BlockLogger::TABLE;

    do {
      $rows = $wpdb->get_results(
        $wpdb->prepare(
          "SELECT ip FROM {$table} WHERE expires_at IS NOT NULL AND expires_at < NOW() LIMIT %d",
          self::DELETE_BATCH_SIZE
        ),
        ARRAY_A
      );

      foreach ($rows as $row) {
        $ip = $row['ip'] ?? null;

        if ($ip) {
          $client->delete_block($ip);

          $wpdb->delete($table, ['ip' => $ip], ['%s']);
        }
      }
    } while (count($rows) === self::DELETE_BATCH_SIZE);
  }

  public static function deactivate(): void {
    $timestamp = wp_next_scheduled(self::HOOK);

    if ($timestamp) {
      wp_unschedule_event($timestamp, self::HOOK);
    }
  }
}