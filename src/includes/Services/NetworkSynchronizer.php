<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Services;

use WPCF\FirewallSync\Config;

final class NetworkSynchronizer {
  /**
   * Synchronize every multisite site inheriting Network Admin settings.
   *
   * When $due_only is true, only externally scheduled sites whose configured
   * interval has elapsed are synchronized.
   */
  public static function run(bool $due_only = false): array {
    $summary = [
      'processed' => 0,
      'successful' => 0,
      'not_due' => 0,
      'disabled' => 0,
      'failed' => [],
    ];

    if (!is_multisite()) {
      return $summary;
    }

    foreach (get_sites(['fields' => 'ids']) as $blog_id) {
      switch_to_blog((int) $blog_id);

      try {
        if (!Config::uses_network_options()) {
          continue;
        }

        $summary['processed']++;

        if ($due_only) {
          $result = SyncScheduler::run_if_due(
            Config::SCHEDULER_EXTERNAL
          );
        } else {
          $result = SyncScheduler::run_now()
            ? SyncScheduler::RESULT_SUCCESS
            : SyncScheduler::RESULT_FAILURE;
        }

        if ($result === SyncScheduler::RESULT_SUCCESS) {
          $summary['successful']++;
          continue;
        }

        if ($result === SyncScheduler::RESULT_NOT_DUE) {
          $summary['not_due']++;
          continue;
        }

        if ($result === SyncScheduler::RESULT_DISABLED) {
          $summary['disabled']++;
          continue;
        }

        $site_name = get_bloginfo('name');
        $site_url = home_url('/');
        $error = SyncScheduler::get_last_error_message();

        $summary['failed'][] = [
          'site_id' => (int) $blog_id,
          'site_name' => $site_name !== ''
            ? $site_name
            : $site_url,
          'site_url' => $site_url,
          'error' => $error !== ''
            ? $error
            : __(
              'Synchronization failed.',
              'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
            ),
        ];
      } finally {
        restore_current_blog();
      }
    }

    return $summary;
  }
}
