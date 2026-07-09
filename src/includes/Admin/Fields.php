<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Admin;

use WPCF\FirewallSync\Plugin;
use WPCF\FirewallSync\Cloudflare\Client;
use WPCF\FirewallSync\Services\SyncScheduler;
use WPCF\FirewallSync\Services\Reconciler;

final class Fields {
  public static function register(): void {
    add_action('admin_init', [self::class, 'register_settings']);
    add_action('admin_post_firewall_sync_validate_cf_credentials', [self::class, 'handle_validate']);
    add_action('admin_post_firewall_sync_test_block', [self::class, 'handle_test_block']);
    add_action('update_option_firewall_sync_options', [self::class, 'maybe_handle_manual_block'], 10, 2);
    add_action('admin_post_firewall_sync_now', [self::class, 'handle_sync_now']);
    add_action('admin_post_firewall_sync_cleanup_now', [self::class, 'handle_cleanup_now']);
    add_action('admin_post_firewall_sync_reconcile', [self::class, 'handle_reconcile']);
    add_action('admin_post_firewall_sync_manual_block', [self::class, 'handle_manual_block']);
  }

  public static function register_settings(): void {
    register_setting('firewall_sync_options_group', 'firewall_sync_options', [
      'type' => 'array',
      'sanitize_callback' => [self::class, 'sanitize'],
      'default' => []
    ]);

    add_settings_section(
      'firewall_sync_main_section',
      __('Cloudflare Configuration', Plugin::get_text_domain()),
      null,
      'firewall-sync-settings',
    );

    self::add_text_field('cloudflare_api_token', 'Cloudflare API Token', '');
    self::add_text_field('cloudflare_zone_id', __('Cloudflare Zone ID', Plugin::get_text_domain()));
    self::add_text_field('sync_interval', __('Sync Interval (minutes)', Plugin::get_text_domain()), 'e.g., 15, 30, 60');
    self::add_button_field('validate_cf_credentials', __('Validate Cloudflare Credentials', Plugin::get_text_domain()));
    self::add_button_field('test_block', __('Run Test Block', Plugin::get_text_domain()));
  }

  private static function add_text_field(string $name, string $label, string $placeholder = ''): void {
    add_settings_field(
      $name,
      $label,
      function () use ($name, $placeholder): void {
        $options = get_option('firewall_sync_options');
        $value = $options[$name] ?? '';

        $type = $name === 'cloudflare_api_token' ? 'password' : 'text';

        printf(
          '<input type="%4$s" id="%1$s" name="firewall_sync_options[%1$s]" value="%2$s" placeholder="%3$s" class="regular-text" autocomplete="off">',
          esc_attr($name),
          esc_attr($value),
          esc_attr($placeholder),
          esc_attr($type)
        );

        if ($name === 'cloudflare_api_token') {
          echo '<p class="description">';

          echo sprintf(
            esc_html__(
                'Need help generating your token? %1$sFollow the Cloudflare documentation%2$s.',
                Plugin::get_text_domain()
            ),
            '<a href="https://developers.cloudflare.com/cloudflare-one/api-terraform/scoped-api-tokens/" target="_blank" rel="noopener noreferrer">',
            '</a>'
          );

          echo '</p>';
        } else if ($name === 'cloudflare_zone_id') {
          echo '<p class="description">';

          echo sprintf(
            esc_html__(
                'Need help finding your zone ID? %1$sFollow the Cloudflare documentation%2$s.',
                Plugin::get_text_domain()
            ),
            '<a href="https://developers.cloudflare.com/fundamentals/setup/find-account-and-zone-ids/" target="_blank" rel="noopener noreferrer">',
            '</a>'
          );

          echo '</p>';
        }
      },
      'firewall-sync-settings',
      'firewall_sync_main_section'
    );
  }

  private static function add_button_field(string $name, string $label): void {
    add_settings_field(
      $name,
      $label,
      function () use ($name, $label): void {
        $url = admin_url('admin-post.php');
        $action = 'firewall_sync_' . $name;
        printf(
          '<form method="post" action="%1$s" style="margin:0;">%2$s<input type="hidden" name="action" value="%3$s">%4$s</form>',
          esc_url($url),
          wp_nonce_field($action, $action . '_nonce', true, false),
          esc_attr($action),
          get_submit_button($label, 'secondary', $action, false)
        );
      },
      'firewall-sync-settings',
      'firewall_sync_main_section'
    );
  }

  public static function sanitize(array $input): array {
    return array_map('sanitize_text_field', $input);
  }

  public static function maybe_handle_manual_block(array $old_value, array $new_value): void {
    // Manual blocks are handled by handle_manual_block().
  }

  public static function handle_validate(): void {
    if (!current_user_can('manage_options')) {
      wp_die(__('You do not have sufficient permissions to access this page.', Plugin::get_text_domain()));
    }

    check_admin_referer('firewall_sync_validate_cf_credentials_nonce');

    $options = get_option('firewall_sync_options');

    $client = new Client($options['cloudflare_api_token'] ?? '', $options['cloudflare_zone_id'] ?? '');

    $result = $client->validate();
    $msg = $result
      ? __('Cloudflare credentials validated successfully', Plugin::get_text_domain())
      : __('Failed to validate Cloudflare credentials', Plugin::get_text_domain());

    add_settings_error('firewall_sync_messages', 'validate', $msg, $result ? 'updated' : 'error');
    wp_redirect(admin_url('admin.php?page=firewall-sync-settings'));
    exit;
  }

  public static function handle_test_block(): void {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    check_admin_referer('firewall_sync_test_block_nonce');

    $options = get_option('firewall_sync_options');
    $client = new Client($options['cloudflare_api_token'] ?? '', $options['cloudflare_zone_id'] ?? '');
    $ip = sanitize_text_field($_POST['firewall_sync_options']['manual_block_ip'] ?? '');

    $create = $client->create_block($ip);
    $delete = $create ? $client->delete_block($ip) : false;

    $msg = ($create && $delete)
      ? 'Test block created and deleted successfully'
      : 'Failed to create or delete test block';

    add_settings_error('firewall_sync_messages', 'test', $msg, $create && $delete ? 'updated' : 'error');
    wp_redirect(admin_url('admin.php?page=firewall-sync-settings'));
    exit;
  }

  public static function handle_sync_now(): void {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    check_admin_referer('firewall_sync_now', 'firewall_sync_now_nonce');

    $success = SyncScheduler::run_now();

    add_settings_error(
        'firewall_sync_messages',
        'sync_now',
        $success
          ? __('Sync completed successfully.', Plugin::get_text_domain())
          : __('Sync failed.', Plugin::get_text_domain()),
        $success ? 'updated' : 'error'
    );

    wp_redirect(admin_url('admin.php?page=firewall-sync-settings'));
    exit;
  }

  public static function handle_cleanup_now(): void {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    check_admin_referer('firewall_sync_cleanup_now', 'firewall_sync_cleanup_now_nonce');

    SyncScheduler::run_cleanup();

    add_settings_error('firewall_sync_messages', 'cleanup_now', __('Cleanup completed successfully.', Plugin::get_text_domain()), 'updated');
    wp_redirect(admin_url('admin.php?page=firewall-sync-settings'));

    exit;
  }

  public static function handle_reconcile(): void {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    check_admin_referer('firewall_sync_reconcile', 'firewall_sync_reconcile_nonce');

    $options = get_option('firewall_sync_options');
    $client = new Client(
        $options['cloudflare_api_token'] ?? '',
        $options['cloudflare_zone_id'] ?? ''
    );

    $result = Reconciler::run($client);
    set_transient('firewall_sync_reconcile_result', $result, 60);

    wp_redirect(admin_url('admin.php?page=firewall-sync-settings'));
    exit;
  }

  public static function handle_manual_block(): void {
    if (!current_user_can('manage_options')) {
      wp_die('Unauthorized');
    }

    check_admin_referer('firewall_sync_manual_block', 'firewall_sync_manual_block_nonce');

    $ip = $_POST['manual_ip'] ?? '';
    $reason = $_POST['manual_reason'] ?? 'manual';

    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
      add_settings_error('firewall_sync_manual_block', 'invalid_ip', __('Invalid IP address.', Plugin::get_text_domain()), 'error');
      wp_redirect(admin_url('admin.php?page=firewall-sync-manual'));
      exit;
    }

    $options = get_option('firewall_sync_options');
    $client = new \WPCF\FirewallSync\Cloudflare\Client(
      $options['cloudflare_api_token'] ?? '',
      $options['cloudflare_zone_id'] ?? ''
    );
    $success = $client->create_block($ip);

    if ($success) {
      BlockLogger::log($ip, 'manual: ' . $reason);
      add_settings_error('firewall_sync_manual_block', 'success', __('IP blocked successfully.', Plugin::get_text_domain()), 'updated');
    } else {
      add_settings_error('firewall_sync_manual_block', 'fail', __('Failed to block IP.', Plugin::get_text_domain()), 'error');
    }

    wp_redirect(admin_url('admin.php?page=firewall-sync-manual'));
    exit;
  }
}