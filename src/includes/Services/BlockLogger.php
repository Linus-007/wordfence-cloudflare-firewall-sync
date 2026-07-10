<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Services;

final class BlockLogger {
  public const TABLE = 'wpcf_sync_blocks';
  public const MAX_FAILURES = 3;

  public static function create_table(): void {
    global $wpdb;

    $table_name = $wpdb->prefix . self::TABLE;
    $charset_collate = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE {$table_name} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      ip VARCHAR(45) NOT NULL,
      reason VARCHAR(255) DEFAULT 'sync',
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      synced_at DATETIME DEFAULT NULL,
      expires_at DATETIME DEFAULT NULL,
      fail_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
      PRIMARY KEY (id),
      UNIQUE KEY ip (ip),
      KEY expires_at (expires_at),
      KEY created_at (created_at),
      KEY synchronization_state (synced_at, fail_count)
    ) {$charset_collate};";

    dbDelta($sql);
  }

  /**
   * Record a successful synchronization.
   *
   * Existing failed rows are converted into successful rows so a retry can
   * recover without violating the unique IP constraint.
   */
  public static function log(
    string $ip,
    string $reason = 'sync',
    ?string $expires_at = null
  ): void {
    global $wpdb;

    $table = $wpdb->prefix . self::TABLE;
    $now = current_time('mysql');

    $wpdb->query(
      $wpdb->prepare(
        "INSERT INTO {$table}
          (ip, reason, created_at, synced_at, expires_at, fail_count)
         VALUES
          (%s, %s, %s, %s, %s, 0)
         ON DUPLICATE KEY UPDATE
          reason = VALUES(reason),
          created_at = VALUES(created_at),
          synced_at = VALUES(synced_at),
          expires_at = VALUES(expires_at),
          fail_count = 0",
        $ip,
        $reason,
        $now,
        $now,
        $expires_at
      )
    );
  }

  public static function get_logs(
    int $limit = 20,
    int $offset = 0
  ): array {
    global $wpdb;

    $table = $wpdb->prefix . self::TABLE;

    return $wpdb->get_results(
      $wpdb->prepare(
        "SELECT ip, reason, created_at
         FROM {$table}
         ORDER BY created_at DESC
         LIMIT %d OFFSET %d",
        $limit,
        $offset
      ),
      ARRAY_A
    );
  }

  public static function count(): int {
    global $wpdb;

    $table = $wpdb->prefix . self::TABLE;

    return (int) $wpdb->get_var(
      "SELECT COUNT(*) FROM {$table}"
    );
  }

  /**
   * Return true only when the IP completed synchronization successfully.
   */
  public static function has_synced(string $ip): bool {
    global $wpdb;

    $table = $wpdb->prefix . self::TABLE;

    return (bool) $wpdb->get_var(
      $wpdb->prepare(
        "SELECT 1
         FROM {$table}
         WHERE ip = %s
           AND synced_at IS NOT NULL
           AND fail_count = 0
         LIMIT 1",
        $ip
      )
    );
  }

  /**
   * Return only IPs that were successfully synchronized.
   */
  public static function get_all_ips(): array {
    global $wpdb;

    $table = $wpdb->prefix . self::TABLE;

    return $wpdb->get_col(
      "SELECT ip
       FROM {$table}
       WHERE synced_at IS NOT NULL
         AND fail_count = 0"
    );
  }

  /**
   * Record a failed synchronization attempt.
   */
  public static function mark_failed(
    string $ip,
    string $reason = 'sync',
    ?string $expires_at = null
  ): void {
    global $wpdb;

    $table = $wpdb->prefix . self::TABLE;
    $now = current_time('mysql');

    $wpdb->query(
      $wpdb->prepare(
        "INSERT INTO {$table}
          (ip, reason, created_at, synced_at, expires_at, fail_count)
         VALUES
          (%s, %s, %s, NULL, %s, 1)
         ON DUPLICATE KEY UPDATE
          reason = VALUES(reason),
          created_at = VALUES(created_at),
          synced_at = NULL,
          expires_at = VALUES(expires_at),
          fail_count = LEAST(fail_count + 1, %d)",
        $ip,
        $reason,
        $now,
        $expires_at,
        self::MAX_FAILURES
      )
    );
  }

  /**
   * Stop automatic retries after the configured failure limit.
   */
  public static function is_blacklisted(string $ip): bool {
    global $wpdb;

    $table = $wpdb->prefix . self::TABLE;

    return (bool) $wpdb->get_var(
      $wpdb->prepare(
        "SELECT 1
         FROM {$table}
         WHERE ip = %s
           AND synced_at IS NULL
           AND fail_count >= %d
         LIMIT 1",
        $ip,
        self::MAX_FAILURES
      )
    );
  }
}
