<?php

declare(strict_types=1);

namespace WPCF\FirewallSync;

if (!defined('WP_UNINSTALL_PLUGIN')) {
  exit;
}

/*
 * Plugin uninstall intentionally removes the plugin-owned table.
 * The table name uses the trusted WordPress prefix plus a fixed suffix.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
 */

/**
 * Remove this plugin's site-specific table and options from the current site.
 */
function grey_rock_block_synchroniser_for_wordfence_and_cloudflare_uninstall_site(): void {
  global $wpdb;

  $table = $wpdb->prefix . 'wpcf_sync_blocks';

  $wpdb->query(
    "DROP TABLE IF EXISTS `{$table}`"
  );

  delete_option('firewall_sync_options');
  delete_option('firewall_sync_last_run');
  delete_option('firewall_sync_last_attempt_timestamp');
  delete_option('firewall_sync_is_running');
  delete_option('firewall_sync_version');

  wp_clear_scheduled_hook('firewall_sync_cron_event');
  wp_clear_scheduled_hook('firewall_sync_cleanup_event');

  delete_transient('firewall_sync_reconcile_result');
}

if (is_multisite()) {
  foreach (get_sites(['fields' => 'ids']) as $blog_id) {
    switch_to_blog((int) $blog_id);

    try {
      grey_rock_block_synchroniser_for_wordfence_and_cloudflare_uninstall_site();
    } finally {
      restore_current_blog();
    }
  }

  delete_site_option('firewall_sync_network_options');
  delete_site_option('firewall_sync_network_version');
} else {
  grey_rock_block_synchroniser_for_wordfence_and_cloudflare_uninstall_site();
}
