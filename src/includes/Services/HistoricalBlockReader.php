<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Services;

final class HistoricalBlockReader {
  private const WORDFENCE_ACTION = 'blocked:waf';

  /**
   * Read historical Wordfence WAF blocks from the shared hits table.
   *
   * @return array<int, array{
   *   ip: string,
   *   event_count: int,
   *   latest_event: int
   * }>
   */
  public static function get_candidates(
    int $lookback_hours,
    int $minimum_events
  ): array {
    global $wpdb;

    $lookback_hours = self::validated_lookback_hours(
      $lookback_hours
    );

    $minimum_events = self::validated_minimum_events(
      $minimum_events
    );

    $table = $wpdb->base_prefix . 'wfhits';

    $table_exists = $wpdb->get_var(
      $wpdb->prepare(
        'SHOW TABLES LIKE %s',
        $wpdb->esc_like($table)
      )
    );

    if ($table_exists !== $table) {
      return [];
    }

    $cutoff = time() - ($lookback_hours * HOUR_IN_SECONDS);

    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT
          HEX(IP) AS ip_hex,
          COUNT(*) AS event_count,
          MAX(ctime) AS latest_event
        FROM {$table}
        WHERE action = %s
          AND ctime >= %f
        GROUP BY IP
        HAVING COUNT(*) >= %d
        ORDER BY latest_event DESC",
        self::WORDFENCE_ACTION,
        (float) $cutoff,
        $minimum_events
      ),
      ARRAY_A
    );

    if (!is_array($rows)) {
      return [];
    }

    $candidates = [];

    foreach ($rows as $row) {
      $ip = self::decode_wordfence_ip(
        (string) ($row['ip_hex'] ?? '')
      );

      if ($ip === null || !self::is_public_ip($ip)) {
        continue;
      }

      $candidates[] = [
        'ip' => $ip,
        'event_count' => (int) ($row['event_count'] ?? 0),
        'latest_event' => (int) floor(
          (float) ($row['latest_event'] ?? 0)
        ),
      ];
    }

    return $candidates;
  }

  private static function decode_wordfence_ip(
    string $hex
  ): ?string {
    if (
      $hex === ''
      || strlen($hex) !== 32
      || !ctype_xdigit($hex)
    ) {
      return null;
    }

    $binary = hex2bin($hex);

    if ($binary === false) {
      return null;
    }

    $ip = inet_ntop($binary);

    if ($ip === false) {
      return null;
    }

    if (stripos($ip, '::ffff:') === 0) {
      $mapped_ipv4 = substr($ip, 7);

      return filter_var(
        $mapped_ipv4,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_IPV4
      )
        ? $mapped_ipv4
        : null;
    }

    return filter_var($ip, FILTER_VALIDATE_IP)
      ? $ip
      : null;
  }

  private static function is_public_ip(string $ip): bool {
    return filter_var(
      $ip,
      FILTER_VALIDATE_IP,
      FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
  }

  private static function validated_lookback_hours(
    int $hours
  ): int {
    return in_array($hours, [1, 3, 6, 12, 24], true)
      ? $hours
      : 24;
  }

  private static function validated_minimum_events(
    int $minimum_events
  ): int {
    if ($minimum_events < 1 || $minimum_events > 100) {
      return 1;
    }

    return $minimum_events;
  }
}
