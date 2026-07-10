<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Services;

use WPCF\FirewallSync\Cloudflare\Client;
use WPCF\FirewallSync\Config;
use WPCF\FirewallSync\Plugin;

final class SyncScheduler {
  private const HOOK = 'firewall_sync_cron_event';
  private const DELETE_BATCH_SIZE = 100;
  private const CLEANUP_HOOK = 'firewall_sync_cleanup_event';

  private static string $lastErrorMessage = '';

  public static function register(): void {
    add_action(self::HOOK, [self::class, 'run_now']);
    add_action(self::CLEANUP_HOOK, [self::class, 'run_cleanup']);
    add_filter('cron_schedules', [self::class, 'custom_intervals']);

    self::schedule_events();
  }

  /**
   * Create missing synchronization and cleanup cron events.
   */
  private static function schedule_events(): void {
    $options = Config::get_effective_options();

    $minutes = max(5, (int) ($options['sync_interval'] ?? 60));
    $interval_key = $minutes === 5
      ? 'every_5_minutes'
      : ($minutes === 15 ? 'every_15_minutes' : 'hourly');

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
      'display' => __('Every 5 Minutes', Plugin::get_text_domain()),
    ];

    $schedules['every_15_minutes'] = [
      'interval' => 900,
      'display' => __('Every 15 Minutes', Plugin::get_text_domain()),
    ];

    return $schedules;
  }

  public static function run_now(): bool {
    self::$lastErrorMessage = '';

    $options = Config::get_effective_options();
    $token = $options['cloudflare_api_token'] ?? '';
    $zone = $options['cloudflare_zone_id'] ?? '';
    $mode = $options['cloudflare_mode'] ?? 'zone_access_rules';
    $account_id = $options['cloudflare_account_id'] ?? '';
    $list_name = $options['cloudflare_list_name'] ?? '';
    $legacy_list_id = $options['cloudflare_list_id'] ?? '';

    if (empty($token)) {
      self::$lastErrorMessage = __(
        'Cloudflare API Token is required.',
        Plugin::get_text_domain()
      );

      return false;
    }

    if ($mode === 'account_list') {
      if (
        empty($account_id)
        || (empty($list_name) && empty($legacy_list_id))
      ) {
        self::$lastErrorMessage = __(
          'Cloudflare Account ID and List Name are required.',
          Plugin::get_text_domain()
        );

        return false;
      }
    } elseif (empty($zone)) {
      self::$lastErrorMessage = __(
        'Cloudflare Zone ID is required.',
        Plugin::get_text_domain()
      );

      return false;
    }

    $client = new Client($token, $zone);
    $list_id = '';

    if ($mode === 'account_list') {
      $resolved_list_id = $client->resolve_account_list_id(
        $account_id,
        $list_name,
        $legacy_list_id
      );

      if ($resolved_list_id === null) {
        self::$lastErrorMessage = $client->get_last_error_message();

        return false;
      }

      $list_id = $resolved_list_id;
    }

    /*
     * Wordfence has changed the internal wfBlock API between releases.
     * Some versions define wfBlock without providing getBlocks(). Treat
     * active-block retrieval as optional so historical wp_wfhits records
     * can still be synchronized without causing a fatal error.
     */
    $blocks = [];

    if (
      class_exists('\wfBlock')
      && method_exists('\wfBlock', 'getBlocks')
    ) {
      $wordfence_blocks = \wfBlock::getBlocks();

      if (is_array($wordfence_blocks)) {
        $blocks = $wordfence_blocks;
      }
    }

    /*
     * Key the batch by IP so active Wordfence blocks and historical WAF
     * events cannot create duplicate Cloudflare operations.
     */
    $batch_by_ip = [];

    foreach ($blocks as $block) {
      $ip = (string) ($block['ip'] ?? '');
      $reason = $block['reason']
        ?? __('Unknown', Plugin::get_text_domain());
      $expiration = (int) ($block['expirationUnix'] ?? 0);
      $is_permanent = !empty($block['permanent']);

      if (
        $ip === ''
        || !filter_var($ip, FILTER_VALIDATE_IP)
        || (!$is_permanent && $expiration > 0 && time() > $expiration)
        || BlockLogger::has_synced($ip)
        || BlockLogger::is_blacklisted($ip)
      ) {
        continue;
      }

      $expires_at = null;

      if (!$is_permanent && $expiration > 0) {
        $expires_at = wp_date(
          'Y-m-d H:i:s',
          $expiration,
          wp_timezone()
        );
      }

      $batch_by_ip[$ip] = [
        'ip' => $ip,
        'reason' => (string) $reason,
        'expires_at' => $expires_at,
      ];
    }

    $lookback_hours = (int) (
      $options['historical_lookback_hours'] ?? 24
    );

    $minimum_events = (int) (
      $options['historical_minimum_events'] ?? 1
    );

    $historical_blocks = HistoricalBlockReader::get_candidates(
      $lookback_hours,
      $minimum_events
    );

    foreach ($historical_blocks as $historical_block) {
      $ip = (string) ($historical_block['ip'] ?? '');
      $event_count = (int) (
        $historical_block['event_count'] ?? 0
      );

      $latest_event = (int) (
        $historical_block['latest_event'] ?? 0
      );

      if (
        $ip === ''
        || isset($batch_by_ip[$ip])
        || BlockLogger::has_synced($ip)
        || BlockLogger::is_blacklisted($ip)
      ) {
        continue;
      }

      $expires_at = null;

      if ($latest_event > 0) {
        $expires_at = wp_date(
          'Y-m-d H:i:s',
          $latest_event + ($lookback_hours * HOUR_IN_SECONDS),
          wp_timezone()
        );
      }

      $batch_by_ip[$ip] = [
        'ip' => $ip,
        'reason' => sprintf(
          /* translators: %d: number of Wordfence blocked WAF events */
          _n(
            'Wordfence historical WAF block: %d event',
            'Wordfence historical WAF block: %d events',
            $event_count,
            Plugin::get_text_domain()
          ),
          $event_count
        ),
        'expires_at' => $expires_at,
      ];
    }

    $batch = array_values($batch_by_ip);

    if ($mode === 'account_list') {
      $failed = $client->batch_add_ips_to_account_list(
        $account_id,
        $list_id,
        $batch
      );
    } else {
      $cloudflare_batch = array_map(
        static function (array $entry): array {
          return [
            'ip' => $entry['ip'],
            'reason' => $entry['reason'],
          ];
        },
        $batch
      );

      $failed = $client->batch_block($cloudflare_batch);
    }

    foreach ($batch as $entry) {
      $log_reason = 'sync: ' . $entry['reason'];

      if (in_array($entry['ip'], $failed, true)) {
        BlockLogger::mark_failed(
          $entry['ip'],
          $log_reason,
          $entry['expires_at']
        );

        continue;
      }

      BlockLogger::log(
        $entry['ip'],
        $log_reason,
        $entry['expires_at']
      );
    }

    update_option(
      'firewall_sync_last_run',
      current_time('mysql')
    );

    delete_option('firewall_sync_is_running');

    if (!empty($failed)) {
      $client_error = $client->get_last_error_message();

      self::$lastErrorMessage = $client_error !== ''
        ? $client_error
        : sprintf(
          /* translators: %d: number of failed IP addresses */
          __(
            '%d IP address could not be synchronized.',
            Plugin::get_text_domain()
          ),
          count($failed)
        );

      return false;
    }

    return true;
  }

  public static function get_last_error_message(): string {
    return self::$lastErrorMessage;
  }

  public static function run_cleanup(): void {
    global $wpdb;

    /*
     * A site inheriting Network Admin settings may share its Cloudflare
     * destination with other sites. Its local log cannot determine whether
     * another site still requires an address, so it must not delete entries
     * from that shared destination.
     */
    if (is_multisite() && Config::uses_network_options()) {
      return;
    }

    $options = Config::get_effective_options();
    $token = $options['cloudflare_api_token'] ?? '';
    $zone = $options['cloudflare_zone_id'] ?? '';
    $mode = $options['cloudflare_mode'] ?? 'zone_access_rules';
    $account_id = $options['cloudflare_account_id'] ?? '';
    $list_name = $options['cloudflare_list_name'] ?? '';
    $legacy_list_id = $options['cloudflare_list_id'] ?? '';

    if (empty($token)) {
      return;
    }

    if ($mode === 'account_list') {
      if (
        empty($account_id)
        || (empty($list_name) && empty($legacy_list_id))
      ) {
        return;
      }
    } elseif (empty($zone)) {
      return;
    }

    $client = new Client($token, $zone);
    $list_id = '';

    if ($mode === 'account_list') {
      $resolved_list_id = $client->resolve_account_list_id(
        $account_id,
        $list_name,
        $legacy_list_id
      );

      if ($resolved_list_id === null) {
        return;
      }

      $list_id = $resolved_list_id;
    }

    $table = $wpdb->prefix . BlockLogger::TABLE;
    $current_time = current_time('mysql');

    $last_id = 0;

    do {
      $rows = $wpdb->get_results(
        $wpdb->prepare(
          "SELECT id, ip
           FROM {$table}
           WHERE id > %d
             AND synced_at IS NOT NULL
             AND fail_count = 0
             AND expires_at IS NOT NULL
             AND expires_at < %s
           ORDER BY id ASC
           LIMIT %d",
          $last_id,
          $current_time,
          self::DELETE_BATCH_SIZE
        ),
        ARRAY_A
      );

      foreach ($rows as $row) {
        $row_id = (int) ($row['id'] ?? 0);
        $ip = $row['ip'] ?? null;

        if ($row_id > $last_id) {
          $last_id = $row_id;
        }

        if (!$ip) {
          continue;
        }

        $deleted = $mode === 'account_list'
          ? $client->remove_ip_from_account_list(
            $account_id,
            $list_id,
            $ip
          )
          : $client->delete_block($ip);

        /*
         * Retain the ownership record when Cloudflare deletion fails. A
         * later cleanup run can retry instead of losing track of the entry.
         * Cursor-based pagination prevents a failed row from being selected
         * repeatedly during the same cleanup invocation.
         */
        if ($deleted) {
          $wpdb->delete(
            $table,
            ['id' => $row_id],
            ['%d']
          );
        }
      }
    } while (count($rows) === self::DELETE_BATCH_SIZE);
  }

  /**
   * Replace existing schedules using the currently effective interval.
   */
  public static function reschedule(): void {
    self::deactivate();
    self::schedule_events();
  }

  public static function deactivate(): void {
    wp_clear_scheduled_hook(self::HOOK);
    wp_clear_scheduled_hook(self::CLEANUP_HOOK);
  }
}
