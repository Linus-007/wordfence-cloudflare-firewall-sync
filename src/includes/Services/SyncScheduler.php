<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Services;

/*
 * Direct database access is intentional for synchronization-state queries
 * against the plugin's own operational log table. These values must remain
 * current and therefore are not object-cached.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */

use WPCF\FirewallSync\Cloudflare\Client;
use WPCF\FirewallSync\Config;

final class SyncScheduler {
  public const RESULT_SUCCESS = 'success';
  public const RESULT_FAILURE = 'failure';
  public const RESULT_NOT_DUE = 'not_due';
  public const RESULT_DISABLED = 'disabled';

  private const HOOK = 'firewall_sync_cron_event';
  private const CLEANUP_HOOK = 'firewall_sync_cleanup_event';
  private const DELETE_BATCH_SIZE = 100;

  private const LOCK_OPTION = 'firewall_sync_is_running';
  private const LOCK_TTL_SECONDS = 900;
  private const LAST_ATTEMPT_OPTION =
    'firewall_sync_last_attempt_timestamp';

  private static string $lastErrorMessage = '';

  public static function register(): void {
    add_action(
      self::HOOK,
      [self::class, 'run_scheduled_sync']
    );

    add_action(
      self::CLEANUP_HOOK,
      [self::class, 'run_cleanup']
    );

    add_filter(
      'cron_schedules',
      [self::class, 'custom_intervals']
    );

    self::schedule_events();
  }

  /**
   * Create or correct the synchronization and cleanup schedules.
   *
   * Synchronization follows the selected method and interval. Cleanup is
   * separate maintenance and remains hourly in every scheduling mode.
   */
  private static function schedule_events(): void {
    $options = Config::get_effective_options();
    $method = Config::get_schedule_method($options);

    if ($method === Config::SCHEDULER_WP_CRON) {
      self::ensure_event(
        self::HOOK,
        self::interval_key(
          Config::get_sync_interval_minutes($options)
        )
      );
    } else {
      wp_clear_scheduled_hook(self::HOOK);
    }

    self::ensure_event(self::CLEANUP_HOOK, 'hourly');
  }

  private static function ensure_event(
    string $hook,
    string $recurrence
  ): void {
    $next = wp_next_scheduled($hook);
    $current_recurrence = wp_get_schedule($hook);

    if (
      $next !== false
      && $current_recurrence !== $recurrence
    ) {
      wp_clear_scheduled_hook($hook);
      $next = false;
    }

    if ($next === false) {
      wp_schedule_event(time(), $recurrence, $hook);
    }
  }

  private static function interval_key(int $minutes): string {
    return match ($minutes) {
      1 => 'every_minute',
      5 => 'every_5_minutes',
      15 => 'every_15_minutes',
      default => 'hourly',
    };
  }

  public static function custom_intervals(array $schedules): array {
    $schedules['every_minute'] = [
      'interval' => MINUTE_IN_SECONDS,
      'display' => __(
        'Every Minute',
        'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
      ),
    ];

    $schedules['every_5_minutes'] = [
      'interval' => 5 * MINUTE_IN_SECONDS,
      'display' => __(
        'Every 5 Minutes',
        'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
      ),
    ];

    $schedules['every_15_minutes'] = [
      'interval' => 15 * MINUTE_IN_SECONDS,
      'display' => __(
        'Every 15 Minutes',
        'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
      ),
    ];

    return $schedules;
  }

  /**
   * Run a WP-Cron synchronization only when WP-Cron is selected.
   */
  public static function run_scheduled_sync(): void {
    self::run_if_due(Config::SCHEDULER_WP_CRON);
  }

  /**
   * Run only when the required scheduling method is selected and the
   * configured synchronization interval has elapsed.
   */
  public static function run_if_due(
    string $required_method = Config::SCHEDULER_EXTERNAL
  ): string {
    self::$lastErrorMessage = '';

    $options = Config::get_effective_options();

    if (Config::get_schedule_method($options) !== $required_method) {
      return self::RESULT_DISABLED;
    }

    if (!self::is_due($options)) {
      return self::RESULT_NOT_DUE;
    }

    return self::run_now()
      ? self::RESULT_SUCCESS
      : self::RESULT_FAILURE;
  }

  public static function is_due(?array $options = null): bool {
    $options = $options ?? Config::get_effective_options();
    $last_attempt = self::get_last_attempt_timestamp();

    if ($last_attempt <= 0) {
      return true;
    }

    $interval = (
      Config::get_sync_interval_minutes($options)
      * MINUTE_IN_SECONDS
    );

    return time() >= ($last_attempt + $interval);
  }

  public static function seconds_until_due(
    ?array $options = null
  ): int {
    $options = $options ?? Config::get_effective_options();
    $last_attempt = self::get_last_attempt_timestamp();

    if ($last_attempt <= 0) {
      return 0;
    }

    $interval = (
      Config::get_sync_interval_minutes($options)
      * MINUTE_IN_SECONDS
    );

    return max(
      0,
      ($last_attempt + $interval) - time()
    );
  }

  public static function get_last_attempt_timestamp(): int {
    return (int) get_option(self::LAST_ATTEMPT_OPTION, 0);
  }

  /**
   * Force an immediate synchronization regardless of scheduling mode.
   */
  public static function run_now(): bool {
    self::$lastErrorMessage = '';

    if (!self::acquire_lock()) {
      return false;
    }

    update_option(
      self::LAST_ATTEMPT_OPTION,
      time(),
      false
    );

    try {
      return self::execute_sync();
    } finally {
      self::release_lock();
    }
  }

  private static function acquire_lock(): bool {
    $started_at = time();

    if (
      add_option(
        self::LOCK_OPTION,
        (string) $started_at,
        '',
        false
      )
    ) {
      return true;
    }

    $existing_started_at = (int) get_option(
      self::LOCK_OPTION,
      0
    );

    if (
      $existing_started_at <= 0
      || ($started_at - $existing_started_at)
        > self::LOCK_TTL_SECONDS
    ) {
      delete_option(self::LOCK_OPTION);

      if (
        add_option(
          self::LOCK_OPTION,
          (string) $started_at,
          '',
          false
        )
      ) {
        return true;
      }
    }

    self::$lastErrorMessage = __(
      'Synchronization is already running.',
      'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
    );

    return false;
  }

  private static function release_lock(): void {
    delete_option(self::LOCK_OPTION);
  }

  private static function execute_sync(): bool {
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
        'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
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
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        );

        return false;
      }
    } elseif (empty($zone)) {
      self::$lastErrorMessage = __(
        'Cloudflare Zone ID is required.',
        'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
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
        self::$lastErrorMessage =
          $client->get_last_error_message();

        return false;
      }

      $list_id = $resolved_list_id;
    }

    /*
     * Wordfence has changed the internal wfBlock API between releases.
     * Active-block retrieval is optional so historical wp_wfhits records
     * remain available without causing a fatal error.
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
     * Key the batch by IP so active blocks and historical WAF events cannot
     * create duplicate Cloudflare operations.
     */
    $batch_by_ip = [];

    foreach ($blocks as $block) {
      $ip = (string) ($block['ip'] ?? '');
      $reason = $block['reason']
        ?? __(
          'Unknown',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        );
      $expiration = (int) ($block['expirationUnix'] ?? 0);
      $is_permanent = !empty($block['permanent']);

      if (
        $ip === ''
        || !filter_var($ip, FILTER_VALIDATE_IP)
        || (
          !$is_permanent
          && $expiration > 0
          && time() > $expiration
        )
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
          $latest_event + (
            $lookback_hours * HOUR_IN_SECONDS
          ),
          wp_timezone()
        );
      }

      $batch_by_ip[$ip] = [
        'ip' => $ip,
        'reason' => sprintf(
          /* translators: %d: number of blocked WAF events */
          _n(
            'Wordfence historical WAF block: %d event',
            'Wordfence historical WAF block: %d events',
            $event_count,
            'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
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

    if (!empty($failed)) {
      $client_error = $client->get_last_error_message();

      self::$lastErrorMessage = $client_error !== ''
        ? $client_error
        : sprintf(
          /* translators: %d: number of failed IP addresses */
          __(
            '%d IP address could not be synchronized.',
            'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
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
         * Retain the ownership record when deletion fails so a later cleanup
         * can retry instead of losing track of the Cloudflare entry.
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
   * Replace schedules using the currently effective configuration.
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
