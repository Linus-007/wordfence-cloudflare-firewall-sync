<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Admin;

use WPCF\FirewallSync\Config;
use WPCF\FirewallSync\Plugin;

final class Settings {
  public static function register(): void {
    add_action('admin_menu', [self::class, 'add_site_settings_page']);
    add_action('network_admin_menu', [self::class, 'add_network_settings_page']);
    add_action('admin_enqueue_scripts', [self::class, 'enqueue_styles']);
    add_action('load-toplevel_page_firewall-sync-settings', [self::class, 'add_help_tabs']);
  }

  public static function add_help_tabs(): void {
    $screen = get_current_screen();

    if (!$screen) {
      return;
    }

    $screen->add_help_tab([
      'id' => 'cloudflare-token-help',
      'title' => __('API Token Permissions', Plugin::get_text_domain()),
      'content' =>
        '<p>' .
        esc_html__(
          'Use a restricted Cloudflare API token. A Global API Key is not required.',
          Plugin::get_text_domain()
        ) .
        '</p>' .
        '<p><strong>' .
        esc_html__('Zone Access Rules mode', Plugin::get_text_domain()) .
        '</strong></p>' .
        '<ul>
          <li><code>Zone → Firewall Services: Edit</code></li>
          <li><code>Zone → Zone: Read</code></li>
        </ul>' .
        '<p><strong>' .
        esc_html__('Account IP List mode', Plugin::get_text_domain()) .
        '</strong></p>' .
        '<ul>
          <li><code>Account → Account Filter Lists: Edit</code></li>
        </ul>' .
        '<p>' .
        esc_html__(
          'Restrict the token to the required account or zones. DNS editing permission is not required.',
          Plugin::get_text_domain()
        ) .
        '</p>' .
        '<p><a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" rel="noopener noreferrer">' .
        esc_html__('Open Cloudflare API Tokens', Plugin::get_text_domain()) .
        '</a></p>',
    ]);

    $screen->add_help_tab([
      'id' => 'cloudflare-identifiers-help',
      'title' => __('Cloudflare Identifiers', Plugin::get_text_domain()),
      'content' =>
        '<p>' .
        esc_html__(
          'Zone Access Rules mode requires a Zone ID. Account IP List mode requires an Account ID and List ID.',
          Plugin::get_text_domain()
        ) .
        '</p>' .
        '<p>' .
        esc_html__(
          'The List ID is used by the API. The List Name is used in Custom Rule expressions and is entered without the dollar sign.',
          Plugin::get_text_domain()
        ) .
        '</p>',
    ]);

    $screen->add_help_tab([
      'id' => 'cloudflare-list-rule-help',
      'title' => __('Account List Security Rule', Plugin::get_text_domain()),
      'content' =>
        '<p><strong>' .
        esc_html__(
          'An Account IP List does not block traffic by itself.',
          Plugin::get_text_domain()
        ) .
        '</strong></p>' .
        '<p>' .
        esc_html__(
          'Create a Cloudflare Custom Rule with the Block action in every zone that should use the list.',
          Plugin::get_text_domain()
        ) .
        '</p>' .
        '<p><code>ip.src in $greyrock_wordfence_blocks</code></p>' .
        '<p><code>ip.src in $greyrock_wordfence_blocks and http.host eq &quot;example.com&quot;</code></p>',
    ]);
  }

  public static function add_site_settings_page(): void {
    add_menu_page(
      __('Greyrock Synchroniser', Plugin::get_text_domain()),
      __('Greyrock Synchroniser', Plugin::get_text_domain()),
      'manage_options',
      'firewall-sync-settings',
      [self::class, 'render_settings'],
      'dashicons-shield-alt',
      81
    );

    add_submenu_page(
      'firewall-sync-settings',
      __('Block Log', Plugin::get_text_domain()),
      __('Block Log', Plugin::get_text_domain()),
      'manage_options',
      'firewall-sync-log',
      [self::class, 'render_log']
    );

    add_submenu_page(
      'firewall-sync-settings',
      __('Manual IP Block', Plugin::get_text_domain()),
      __('Manual IP Block', Plugin::get_text_domain()),
      'manage_options',
      'firewall-manual-block',
      [self::class, 'render_manual_block']
    );
  }

  public static function add_network_settings_page(): void {
    if (!is_multisite()) {
      return;
    }

    add_menu_page(
      __('Greyrock Synchroniser', Plugin::get_text_domain()),
      __('Greyrock Synchroniser', Plugin::get_text_domain()),
      'manage_network_options',
      'firewall-sync-settings',
      [self::class, 'render_settings'],
      'dashicons-shield-alt',
      81
    );
  }

  public static function enqueue_styles(string $hook_suffix): void {
    if (strpos($hook_suffix, 'firewall-sync') === false) {
      return;
    }

    wp_enqueue_style(
      'firewall-sync-admin',
      WPCF_FS_PLUGIN_URL . 'assets/admin.css',
      [],
      WPCF_FS_VERSION
    );
  }

  public static function render_log(): void {
    if (is_network_admin()) {
      wp_die(
        esc_html__(
          'Block logs are site-specific. Open Greyrock Synchroniser within an individual site.',
          Plugin::get_text_domain()
        )
      );
    }

    $log_table = new LogTable();
    $log_table->prepare_items();
    ?>
    <div class="wrap">
      <h1><?php echo esc_html(__('Greyrock Synchronisation Log', Plugin::get_text_domain())); ?></h1>
      <?php $log_table->display(); ?>
    </div>
    <?php
  }

  public static function render_settings(): void {
    $network_context = is_multisite() && is_network_admin();

    if ($network_context && !current_user_can('manage_network_options')) {
      wp_die(
        esc_html__(
          'You do not have permission to administer network firewall settings.',
          Plugin::get_text_domain()
        )
      );
    }

    if (!$network_context && !current_user_can('manage_options')) {
      wp_die(
        esc_html__(
          'You do not have permission to administer firewall settings.',
          Plugin::get_text_domain()
        )
      );
    }

    $scope = $network_context ? 'network' : 'site';
    ?>
    <div class="wrap">
      <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

      <?php settings_errors('firewall_sync_messages'); ?>

      <?php if ($network_context): ?>
        <p>
          <?php
          echo esc_html__(
            'These settings are the network defaults. Individual sites may inherit them or use site-specific settings when overrides are permitted.',
            Plugin::get_text_domain()
          );
          ?>
        </p>
      <?php elseif (is_multisite() && Config::uses_network_options()): ?>
        <div class="notice notice-info inline">
          <p>
            <?php
            echo esc_html__(
              'This site inherits its Cloudflare configuration from Network Admin. Synchronization adds Wordfence blocks to the shared destination. Cleanup and reconciliation are unavailable because another site may still require the same Cloudflare entry.',
              Plugin::get_text_domain()
            );
            ?>
          </p>
        </div>
      <?php endif; ?>

      <?php self::render_setup_guide(); ?>

      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('firewall_sync_save_settings', 'firewall_sync_save_settings_nonce'); ?>
        <input type="hidden" name="action" value="firewall_sync_save_settings">
        <input type="hidden" name="firewall_sync_scope" value="<?php echo esc_attr($scope); ?>">

        <?php do_settings_sections('firewall-sync-settings'); ?>
        <?php submit_button(__('Save Settings', Plugin::get_text_domain())); ?>
      </form>

      <hr>

      <h2><?php echo esc_html(__('Cloudflare Tests', Plugin::get_text_domain())); ?></h2>

      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="firewall-sync-form">
        <?php wp_nonce_field('firewall_sync_validate_cf_credentials', 'firewall_sync_validate_cf_credentials_nonce'); ?>
        <input type="hidden" name="action" value="firewall_sync_validate_cf_credentials">
        <input type="hidden" name="firewall_sync_scope" value="<?php echo esc_attr($scope); ?>">
        <?php
        submit_button(
          __('Validate Saved Cloudflare Configuration', Plugin::get_text_domain()),
          'secondary',
          'submit',
          false
        );
        ?>
      </form>

      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="firewall-sync-form">
        <?php wp_nonce_field('firewall_sync_test_block', 'firewall_sync_test_block_nonce'); ?>
        <input type="hidden" name="action" value="firewall_sync_test_block">
        <input type="hidden" name="firewall_sync_scope" value="<?php echo esc_attr($scope); ?>">

        <p>
          <label for="firewall_sync_test_ip">
            <strong><?php echo esc_html(__('Test IP address', Plugin::get_text_domain())); ?></strong>
          </label>
        </p>

        <p>
          <input
            type="text"
            id="firewall_sync_test_ip"
            name="firewall_sync_test_ip"
            class="regular-text"
            required
          >
        </p>

        <p class="description">
          <?php
          echo esc_html__(
            'The plugin will add this address to Cloudflare and immediately attempt to remove it.',
            Plugin::get_text_domain()
          );
          ?>
        </p>

        <?php
        submit_button(
          __('Run Test Block', Plugin::get_text_domain()),
          'secondary',
          'submit',
          false
        );
        ?>
      </form>

      <?php if (!$network_context): ?>
        <?php
        $sync_disabled = get_option('firewall_sync_is_running') ? 'disabled' : '';
        $last_sync = get_option('firewall_sync_last_run');
        $last_sync_time = $last_sync
          ? date_i18n(
            get_option('date_format') . ' ' . get_option('time_format'),
            strtotime((string) $last_sync)
          )
          : __('Never', Plugin::get_text_domain());
        ?>

        <hr>

        <h2><?php echo esc_html(__('Last Sync Time', Plugin::get_text_domain())); ?></h2>
        <p><?php echo esc_html($last_sync_time); ?></p>

        <div class="firewall-sync-actions">
          <h2><?php echo esc_html(__('Site Actions', Plugin::get_text_domain())); ?></h2>

          <?php
          self::render_action_button(
            'firewall_sync_now',
            __('Sync Now', Plugin::get_text_domain()),
            'primary',
            $sync_disabled
          );

          if (!is_multisite() || !Config::uses_network_options()) {
            self::render_action_button(
              'firewall_sync_cleanup_now',
              __('Run Cleanup Now', Plugin::get_text_domain())
            );

            self::render_action_button(
              'firewall_sync_reconcile',
              __('Run Reconciliation Now', Plugin::get_text_domain())
            );
          }
          ?>
        </div>

        <?php $result = get_transient('firewall_sync_reconcile_result'); ?>

        <?php if (is_array($result)): ?>
          <?php delete_transient('firewall_sync_reconcile_result'); ?>

          <h2><?php echo esc_html(__('Reconciliation Results', Plugin::get_text_domain())); ?></h2>

          <?php if (empty($result['missing_in_cf']) && empty($result['orphaned_in_cf'])): ?>
            <p>
              <?php
              echo esc_html__(
                'Reconciliation completed with no differences.',
                Plugin::get_text_domain()
              );
              ?>
            </p>
          <?php else: ?>
            <h3><?php echo esc_html(__('Missing in Cloudflare', Plugin::get_text_domain())); ?></h3>
            <ul>
              <?php foreach ($result['missing_in_cf'] ?? [] as $ip): ?>
                <li><?php echo esc_html((string) $ip); ?></li>
              <?php endforeach; ?>
            </ul>

            <h3><?php echo esc_html(__('Orphaned in Cloudflare', Plugin::get_text_domain())); ?></h3>
            <ul>
              <?php foreach ($result['orphaned_in_cf'] ?? [] as $ip): ?>
                <li><?php echo esc_html((string) $ip); ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php
  }

  private static function render_setup_guide(): void {
    ?>
    <div class="firewall-sync-guide">
      <h2>
        <?php echo esc_html__(
          'Cloudflare Setup Guide',
          Plugin::get_text_domain()
        ); ?>
      </h2>

      <p>
        <?php echo esc_html__(
          'Complete these steps before relying on synchronisation. The plugin cannot create the account-list security rule for each domain.',
          Plugin::get_text_domain()
        ); ?>
      </p>

      <ol>
        <li>
          <?php echo esc_html__(
            'Choose Zone Access Rules for one zone or Account IP List for a reusable list.',
            Plugin::get_text_domain()
          ); ?>
        </li>
        <li>
          <?php echo esc_html__(
            'Create a restricted Cloudflare API token with the permissions shown below.',
            Plugin::get_text_domain()
          ); ?>
        </li>
        <li>
          <?php echo esc_html__(
            'Enter the required identifiers and save the settings.',
            Plugin::get_text_domain()
          ); ?>
        </li>
        <li>
          <?php echo esc_html__(
            'Validate the saved configuration and run a test block.',
            Plugin::get_text_domain()
          ); ?>
        </li>
        <li>
          <?php echo esc_html__(
            'For Account IP List mode, create a Block Custom Rule in every Cloudflare zone that should use the list.',
            Plugin::get_text_domain()
          ); ?>
        </li>
      </ol>

      <div class="firewall-sync-guide-grid">
        <section>
          <h3>
            <?php echo esc_html__(
              'Zone Access Rules mode',
              Plugin::get_text_domain()
            ); ?>
          </h3>

          <ul>
            <li><code>Zone → Firewall Services: Edit</code></li>
            <li><code>Zone → Zone: Read</code></li>
          </ul>

          <p>
            <?php echo esc_html__(
              'Required setting: Cloudflare Zone ID.',
              Plugin::get_text_domain()
            ); ?>
          </p>
        </section>

        <section>
          <h3>
            <?php echo esc_html__(
              'Account IP List mode',
              Plugin::get_text_domain()
            ); ?>
          </h3>

          <ul>
            <li><code>Account → Account Filter Lists: Edit</code></li>
          </ul>

          <p>
            <?php echo esc_html__(
              'Required settings: Account ID and List ID. The List Name is used in Cloudflare rule expressions.',
              Plugin::get_text_domain()
            ); ?>
          </p>
        </section>
      </div>

      <div class="notice notice-warning inline firewall-sync-list-warning">
        <p>
          <strong>
            <?php echo esc_html__(
              'Account IP Lists do not block traffic by themselves.',
              Plugin::get_text_domain()
            ); ?>
          </strong>
        </p>

        <p>
          <?php echo esc_html__(
            'Create a Cloudflare Custom Rule with the Block action:',
            Plugin::get_text_domain()
          ); ?>
        </p>

        <p><code>ip.src in $greyrock_wordfence_blocks</code></p>

        <p>
          <?php echo esc_html__(
            'To apply the list to one hostname only:',
            Plugin::get_text_domain()
          ); ?>
        </p>

        <p>
          <code>ip.src in $greyrock_wordfence_blocks and http.host eq "example.com"</code>
        </p>

        <p>
          <?php echo esc_html__(
            'Enter the List Name in this plugin without the dollar sign. Use the dollar sign only in the Cloudflare rule expression.',
            Plugin::get_text_domain()
          ); ?>
        </p>
      </div>
    </div>
    <?php
  }

  public static function render_action_button(
    string $action,
    string $label,
    string $type = 'secondary',
    string $disabled = ''
  ): void {
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="firewall-sync-form">
      <?php wp_nonce_field($action, $action . '_nonce'); ?>
      <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
      <?php submit_button($label, $type, $action, false, ['disabled' => $disabled]); ?>
    </form>
    <?php
  }

  public static function render_manual_block(): void {
    if (is_network_admin()) {
      wp_die(
        esc_html__(
          'Manual blocks are site-specific. Open Greyrock Synchroniser within an individual site.',
          Plugin::get_text_domain()
        )
      );
    }
    ?>
    <div class="wrap">
      <h1><?php echo esc_html(__('Manually Block an IP Address', Plugin::get_text_domain())); ?></h1>

      <?php settings_errors('firewall_sync_manual_block'); ?>

      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('firewall_sync_manual_block', 'firewall_sync_manual_block_nonce'); ?>

        <input type="hidden" name="action" value="firewall_sync_manual_block">

        <table class="form-table">
          <tr>
            <th scope="row">
              <label for="manual_ip">
                <?php echo esc_html(__('IP Address', Plugin::get_text_domain())); ?>
              </label>
            </th>
            <td>
              <input type="text" name="manual_ip" id="manual_ip" class="regular-text" required>
            </td>
          </tr>
          <tr>
            <th scope="row">
              <label for="manual_reason">
                <?php echo esc_html(__('Reason', Plugin::get_text_domain())); ?>
              </label>
            </th>
            <td>
              <input type="text" name="manual_reason" id="manual_reason" class="regular-text">
            </td>
          </tr>
        </table>

        <?php submit_button(__('Block IP', Plugin::get_text_domain())); ?>
      </form>
    </div>
    <?php
  }
}
