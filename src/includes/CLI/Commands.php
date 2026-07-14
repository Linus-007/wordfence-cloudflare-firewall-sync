<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\CLI;

use WPCF\FirewallSync\Services\NetworkSynchronizer;
use WPCF\FirewallSync\Services\SyncScheduler;

final class Commands {
  private const COMMAND =
    'grey-rock-block-synchroniser-for-wordfence-and-cloudflare';

  public static function register(): void {
    \WP_CLI::add_command(
      self::COMMAND . ' sync-site',
      [self::class, 'sync_site']
    );

    \WP_CLI::add_command(
      self::COMMAND . ' sync-network',
      [self::class, 'sync_network']
    );
  }

  /**
   * Synchronize the selected WordPress site.
   *
   * ## OPTIONS
   *
   * [--due]
   * : Run only when External scheduler is selected and the GUI interval
   *   has elapsed.
   *
   * [--force]
   * : Run immediately regardless of scheduling method or interval.
   *
   * ## EXAMPLES
   *
   *     wp grey-rock-block-synchroniser-for-wordfence-and-cloudflare sync-site --due
   *     wp grey-rock-block-synchroniser-for-wordfence-and-cloudflare sync-site --force
   */
  public static function sync_site(
    array $args,
    array $assoc_args
  ): void {
    $mode = self::requested_mode($assoc_args);

    if ($mode === 'force') {
      if (!SyncScheduler::run_now()) {
        \WP_CLI::error(self::failure_message());
      }

      \WP_CLI::success(
        'Site synchronization completed successfully.'
      );

      return;
    }

    $result = SyncScheduler::run_if_due();

    if ($result === SyncScheduler::RESULT_SUCCESS) {
      \WP_CLI::success(
        'Due site synchronization completed successfully.'
      );

      return;
    }

    if ($result === SyncScheduler::RESULT_NOT_DUE) {
      \WP_CLI::log(
        sprintf(
          'Skipped: synchronization is not due for another %d second(s).',
          SyncScheduler::seconds_until_due()
        )
      );

      return;
    }

    if ($result === SyncScheduler::RESULT_DISABLED) {
      \WP_CLI::log(
        'Skipped: the GUI scheduling method is not External scheduler.'
      );

      return;
    }

    \WP_CLI::error(self::failure_message());
  }

  /**
   * Synchronize multisite sites inheriting Network Admin settings.
   *
   * ## OPTIONS
   *
   * [--due]
   * : Run only externally scheduled sites whose GUI interval has elapsed.
   *
   * [--force]
   * : Run all inheriting sites immediately.
   *
   * ## EXAMPLES
   *
   *     wp grey-rock-block-synchroniser-for-wordfence-and-cloudflare sync-network --due
   *     wp grey-rock-block-synchroniser-for-wordfence-and-cloudflare sync-network --force
   */
  public static function sync_network(
    array $args,
    array $assoc_args
  ): void {
    if (!is_multisite()) {
      \WP_CLI::error(
        'The sync-network command requires WordPress multisite.'
      );
    }

    $mode = self::requested_mode($assoc_args);
    $summary = NetworkSynchronizer::run($mode === 'due');

    if ($summary['processed'] === 0) {
      \WP_CLI::error(
        'No sites inherit the Network Admin configuration.'
      );
    }

    foreach ($summary['failed'] as $failure) {
      \WP_CLI::warning(
        sprintf(
          '%1$s (%2$s): %3$s',
          $failure['site_name'],
          $failure['site_url'],
          $failure['error']
        )
      );
    }

    if (!empty($summary['failed'])) {
      \WP_CLI::error(
        sprintf(
          'Network synchronization failed for %d site(s).',
          count($summary['failed'])
        )
      );
    }

    \WP_CLI::success(
      sprintf(
        'Network synchronization complete: %1$d successful, %2$d not due, %3$d disabled.',
        $summary['successful'],
        $summary['not_due'],
        $summary['disabled']
      )
    );
  }

  private static function requested_mode(
    array $assoc_args
  ): string {
    $due = array_key_exists('due', $assoc_args);
    $force = array_key_exists('force', $assoc_args);

    if ($due === $force) {
      \WP_CLI::error(
        'Specify exactly one of --due or --force.'
      );
    }

    return $force ? 'force' : 'due';
  }

  private static function failure_message(): string {
    $message = SyncScheduler::get_last_error_message();

    return $message !== ''
      ? $message
      : 'Synchronization failed.';
  }
}
