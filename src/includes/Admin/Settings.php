<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Admin;

final class Settings {
    public static function register(): void {
      add_action('admin_menu', [self::class, 'add_settings_page']);
    }

    public static function add_settings_page(): void {
      add_menu_page(
        __('Firewall Sync Settings', 'wordfence-cloudflare-sync'),
        __('Firewall Sync', 'wordfence-cloudflare-sync'),
        'manage_options',
        'firewall-sync-settings',
        [self::class, 'render'],
        'dashicons-shield-alt',
        81
      );
    }

    public static function render(): void {
      $sync_disabled = get_option('firewall_sync_is_running') ? 'disabled' : '';
      $last_sync = get_option('firewall_sync_last_run');
      $log_table = new LogTable();
      $log_table->prepare_items();

      if ($last_sync) {
        $last_sync_time = date('Y-m-d H:i:s', $last_sync);
      } else {
        $last_sync_time = __('Never', 'wordfence-cloudflare-sync');
      }
      ?>
        <div class="wrap">
          <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

          <?php settings_errors('firewall_sync_messages'); ?>

          <form method="post" action="options.php">
            <?php
              settings_fields('firewall_sync_options_group');
              do_settings_sections('firewall-sync-settings');
              submit_button();
              ?>
          </form>

          <hr>

          <h2><?php __('Last Sync Time', 'wordpress-cloudflare-sync'); ?></h2>
          <p><?php echo esc_html($last_sync_time); ?></p>

          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 10px;">
            <?php wp_nonce_field('firewall_sync_now', 'firewall_sync_now_nonce'); ?>
            <input type="hidden" name="action" value="firewall_sync_now">
            <?php submit_button(__('Sync Now', 'wordfence-cloudflare-sync'), 'primary', 'firewall_sync_now', false, ['disabled' => $sync_disabled]); ?>
          </form>

          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 10px;">
            <?php wp_nonce_field('firewall_sync_cleanup_now', 'firewall_sync_cleanup_now_nonce'); ?>
            <input type="hidden" name="action" value="firewall_sync_cleanup_now">
            <?php submit_button('Run Cleanup Now', 'secondary', 'firewall_sync_cleanup_now', false); ?>
          </form>

          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 10px;">
            <?php wp_nonce_field('firewall_sync_reconcile', 'firewall_sync_reconcile_nonce'); ?>
            <input type="hidden" name="action" value="firewall_sync_reconcile">
            <?php submit_button('Run Reconciliation', 'secondary', 'firewall_sync_reconcile', false); ?>
          </form>

          <details style="margin-top: 20px;">
            <summary style="font-size: 1.2em; cursor: pointer;">
              <?php __('View Block Log', 'wordpress-cloudflare-sync'); ?>
            </summary>
            <div style="margin-top: 10px;">
              <?php $log_table->display(); ?>
            </div>
          </details>

          <?php if ($result = get_transient('firewall_sync_reconcile_result')) {
            delete_transient('firewall_sync_reconcile_result');

            echo '<h2>' . __('Reconciliation Results', 'wordfence-cloudflare-sync') . '</h2>';

            echo '<h3>' . __('Missing in Cloudflare', 'wordfence-cloudflare-sync') . '</h3>';
            echo '<ul>';

            foreach ($result['missing_in_cf'] as $ip) {
                echo '<li>' . esc_html($ip) . '</li>';
            }

            echo '</ul>';

            echo '<h3>' . __('Orphaned in Cloudflare', 'wordfence-cloudflare-sync') . '</h3>';
            echo '<ul>';

            foreach ($result['orphaned_in_cf'] as $ip) {
                echo '<li>' . esc_html($ip) . '</li>';
            }

            echo '</ul>';
          } ?>
        </div>
      <?php
    }
}
