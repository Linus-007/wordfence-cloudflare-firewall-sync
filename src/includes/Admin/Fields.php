<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Admin;

use WPCF\FirewallSync\Cloudflare\Client;
use WPCF\FirewallSync\Config;
use WPCF\FirewallSync\Plugin;
use WPCF\FirewallSync\Services\BlockLogger;
use WPCF\FirewallSync\Services\Reconciler;
use WPCF\FirewallSync\Services\SyncScheduler;

final class Fields {
  public static function register(): void {
    add_action('admin_init', [self::class, 'register_settings']);

    add_action(
      'admin_post_firewall_sync_save_settings',
      [self::class, 'handle_save_settings']
    );

    add_action(
      'admin_post_firewall_sync_validate_cf_credentials',
      [self::class, 'handle_validate']
    );

    add_action(
      'admin_post_firewall_sync_test_block',
      [self::class, 'handle_test_block']
    );

    add_action(
      'admin_post_firewall_sync_manual_list_ip',
      [self::class, 'handle_manual_list_ip']
    );

    add_action(
      'admin_post_firewall_sync_now',
      [self::class, 'handle_sync_now']
    );

    add_action(
      'admin_post_firewall_sync_network_sync_now',
      [self::class, 'handle_network_sync_now']
    );

    add_action(
      'admin_post_firewall_sync_cleanup_now',
      [self::class, 'handle_cleanup_now']
    );

    add_action(
      'admin_post_firewall_sync_reconcile',
      [self::class, 'handle_reconcile']
    );

    add_action(
      'admin_post_firewall_sync_manual_block',
      [self::class, 'handle_manual_block']
    );
  }

  public static function register_settings(): void {
    add_settings_section(
      'firewall_sync_main_section',
      is_network_admin()
        ? __('Network Cloudflare Configuration', Plugin::get_text_domain())
        : __('Cloudflare Configuration', Plugin::get_text_domain()),
      null,
      'firewall-sync-settings'
    );

    if (is_multisite() && is_network_admin()) {
      self::add_allow_site_overrides_field();
    } elseif (is_multisite()) {
      self::add_configuration_source_field();
    }

    self::add_mode_field();

    self::add_text_field(
      'cloudflare_api_token',
      __('Cloudflare API Token', Plugin::get_text_domain()),
      __('Paste the restricted API token created for this plugin.', Plugin::get_text_domain())
    );

    self::add_text_field(
      'cloudflare_account_id',
      __('Cloudflare Account ID', Plugin::get_text_domain()),
      __('Required for Account IP List mode', Plugin::get_text_domain())
    );

    self::add_text_field(
      'cloudflare_list_name',
      __('Cloudflare List Name', Plugin::get_text_domain()),
      __('Recommended: wordfence_hot_blocklist', Plugin::get_text_domain())
    );

    self::add_text_field(
      'cloudflare_zone_id',
      __('Cloudflare Zone ID', Plugin::get_text_domain()),
      __('Required for Zone Access Rules mode', Plugin::get_text_domain())
    );

    self::add_sync_interval_field();
    self::add_historical_lookback_field();
    self::add_historical_minimum_events_field();
  }

  private static function add_allow_site_overrides_field(): void {
    add_settings_field(
      'allow_site_overrides',
      __('Site-specific settings', Plugin::get_text_domain()),
      static function (): void {
        $options = Config::get_network_options();
        $checked = !array_key_exists('allow_site_overrides', $options)
          || !empty($options['allow_site_overrides']);

        printf(
          '<label><input type="checkbox" name="firewall_sync_options[allow_site_overrides]" value="1"%1$s> %2$s</label>',
          checked($checked, true, false),
          esc_html__(
            'Allow individual sites to use their own Cloudflare configuration.',
            Plugin::get_text_domain()
          )
        );
      },
      'firewall-sync-settings',
      'firewall_sync_main_section'
    );
  }

  private static function add_configuration_source_field(): void {
    add_settings_field(
      'configuration_source',
      __('Configuration source', Plugin::get_text_domain()),
      static function (): void {
        $allowed = Config::site_overrides_allowed();
        $source = Config::get_site_source();

        if (!$allowed) {
          echo '<input type="hidden" name="firewall_sync_options[configuration_source]" value="network">';

          echo '<p>';
          echo esc_html__(
            'Network Admin requires this site to use the network configuration.',
            Plugin::get_text_domain()
          );
          echo '</p>';

          return;
        }

        printf(
          '<label><input type="radio" name="firewall_sync_options[configuration_source]" value="network"%1$s> %2$s</label><br>',
          checked($source, Config::SOURCE_NETWORK, false),
          esc_html__(
            'Use the Network Admin configuration',
            Plugin::get_text_domain()
          )
        );

        printf(
          '<label><input type="radio" name="firewall_sync_options[configuration_source]" value="site"%1$s> %2$s</label>',
          checked($source, Config::SOURCE_SITE, false),
          esc_html__(
            'Use a site-specific configuration',
            Plugin::get_text_domain()
          )
        );

        echo '<p class="description">';
        echo esc_html__(
          'Save after changing the source. Site-specific fields become editable when this site uses its own configuration.',
          Plugin::get_text_domain()
        );
        echo '</p>';
      },
      'firewall-sync-settings',
      'firewall_sync_main_section'
    );
  }

  private static function add_mode_field(): void {
    add_settings_field(
      'cloudflare_mode',
      __('Cloudflare Mode', Plugin::get_text_domain()),
      static function (): void {
        $options = Config::get_admin_options();
        $value = $options['cloudflare_mode'] ?? 'zone_access_rules';
        $disabled = self::configuration_fields_disabled();

        printf(
          '<select id="cloudflare_mode" name="firewall_sync_options[cloudflare_mode]"%1$s>
            <option value="zone_access_rules"%2$s>%3$s</option>
            <option value="account_list"%4$s>%5$s</option>
          </select>',
          disabled($disabled, true, false),
          selected($value, 'zone_access_rules', false),
          esc_html__('Zone Access Rules', Plugin::get_text_domain()),
          selected($value, 'account_list', false),
          esc_html__('Account IP List', Plugin::get_text_domain())
        );

        echo '<p class="description">';
        echo esc_html__(
          'Zone Access Rules applies blocks to one zone. Account IP List stores addresses in a shared Cloudflare account list.',
          Plugin::get_text_domain()
        );
        echo '</p>';
      },
      'firewall-sync-settings',
      'firewall_sync_main_section'
    );
  }

  private static function add_text_field(
    string $name,
    string $label,
    string $placeholder = ''
  ): void {
    add_settings_field(
      $name,
      $label,
      static function () use ($name, $placeholder): void {
        $options = Config::get_admin_options();
        $value = $options[$name] ?? '';
        $type = $name === 'cloudflare_api_token' ? 'password' : 'text';
        $disabled = self::configuration_fields_disabled();

        printf(
          '<input type="%1$s" id="%2$s" name="firewall_sync_options[%2$s]" value="%3$s" placeholder="%4$s" class="regular-text" autocomplete="off"%5$s>',
          esc_attr($type),
          esc_attr($name),
          esc_attr((string) $value),
          esc_attr($placeholder),
          disabled($disabled, true, false)
        );

        $descriptions = [
          'cloudflare_api_token' => __(
            'Zone Access Rules mode needs Zone → Firewall Services: Edit and Zone → Zone: Read. Account IP List mode needs Account → Account Rule Lists: Edit. DNS editing permission is not required.',
            Plugin::get_text_domain()
          ),
          'cloudflare_zone_id' => __(
            'Find this on the Cloudflare zone Overview page. This is an identifier, not the domain name.',
            Plugin::get_text_domain()
          ),
          'cloudflare_account_id' => __(
            'Use the Account ID for the Cloudflare account that owns the IP list.',
            Plugin::get_text_domain()
          ),
          'cloudflare_list_name' => __(
            'Enter the visible Cloudflare list name without the dollar sign. The plugin finds the hidden internal List ID automatically. Cloudflare Free accounts permit one custom list, so the recommended name is wordfence_hot_blocklist. Use it in a Cloudflare Custom Rule as: ip.src in $wordfence_hot_blocklist.',
            Plugin::get_text_domain()
          ),
        ];

        if (isset($descriptions[$name])) {
          echo '<p class="description">';
          echo esc_html($descriptions[$name]);
          echo '</p>';
        }
      },
      'firewall-sync-settings',
      'firewall_sync_main_section'
    );
  }

  private static function add_historical_lookback_field(): void {
    add_settings_field(
      'historical_lookback_hours',
      __('Historical WAF lookback', Plugin::get_text_domain()),
      static function (): void {
        $options = Config::get_admin_options();
        $value = (string) (
          $options['historical_lookback_hours'] ?? '24'
        );

        $disabled = self::configuration_fields_disabled();

        printf(
          '<select id="historical_lookback_hours" name="firewall_sync_options[historical_lookback_hours]"%1$s>
            <option value="1"%2$s>%3$s</option>
            <option value="3"%4$s>%5$s</option>
            <option value="6"%6$s>%7$s</option>
            <option value="12"%8$s>%9$s</option>
            <option value="24"%10$s>%11$s</option>
          </select>',
          disabled($disabled, true, false),
          selected($value, '1', false),
          esc_html__('1 hour', Plugin::get_text_domain()),
          selected($value, '3', false),
          esc_html__('3 hours', Plugin::get_text_domain()),
          selected($value, '6', false),
          esc_html__('6 hours', Plugin::get_text_domain()),
          selected($value, '12', false),
          esc_html__('12 hours', Plugin::get_text_domain()),
          selected($value, '24', false),
          esc_html__('24 hours', Plugin::get_text_domain())
        );

        echo '<p class="description">';
        echo esc_html__(
          'Also import Wordfence Live Traffic events recorded as blocked:waf during this period.',
          Plugin::get_text_domain()
        );
        echo '</p>';
      },
      'firewall-sync-settings',
      'firewall_sync_main_section'
    );
  }

  private static function add_historical_minimum_events_field(): void {
    add_settings_field(
      'historical_minimum_events',
      __('Historical block threshold', Plugin::get_text_domain()),
      static function (): void {
        $options = Config::get_admin_options();
        $value = (string) (
          $options['historical_minimum_events'] ?? '1'
        );

        $disabled = self::configuration_fields_disabled();

        printf(
          '<input type="number" id="historical_minimum_events" name="firewall_sync_options[historical_minimum_events]" value="%1$s" min="1" max="100" step="1" class="small-text" inputmode="numeric"%2$s>',
          esc_attr($value),
          disabled($disabled, true, false)
        );

        echo '<p class="description">';
        echo esc_html__(
          'Minimum blocked:waf events required from one IP address during the lookback period. Whole numbers from 1 through 100 are accepted.',
          Plugin::get_text_domain()
        );
        echo '</p>';
      },
      'firewall-sync-settings',
      'firewall_sync_main_section'
    );
  }

  private static function add_sync_interval_field(): void {
    add_settings_field(
      'sync_interval',
      __('Sync Interval', Plugin::get_text_domain()),
      static function (): void {
        $options = Config::get_admin_options();
        $value = (string) ($options['sync_interval'] ?? '60');
        $disabled = self::configuration_fields_disabled();

        printf(
          '<select id="sync_interval" name="firewall_sync_options[sync_interval]"%1$s>
            <option value="5"%2$s>%3$s</option>
            <option value="15"%4$s>%5$s</option>
            <option value="60"%6$s>%7$s</option>
          </select>',
          disabled($disabled, true, false),
          selected($value, '5', false),
          esc_html__('Every 5 minutes', Plugin::get_text_domain()),
          selected($value, '15', false),
          esc_html__('Every 15 minutes', Plugin::get_text_domain()),
          selected($value, '60', false),
          esc_html__('Every hour', Plugin::get_text_domain())
        );
      },
      'firewall-sync-settings',
      'firewall_sync_main_section'
    );
  }

  private static function configuration_fields_disabled(): bool {
    return is_multisite()
      && !is_network_admin()
      && Config::uses_network_options();
  }

  public static function handle_save_settings(): void {
    check_admin_referer(
      'firewall_sync_save_settings',
      'firewall_sync_save_settings_nonce'
    );

    $scope = self::posted_scope();
    $input = isset($_POST['firewall_sync_options'])
      && is_array($_POST['firewall_sync_options'])
        ? wp_unslash($_POST['firewall_sync_options'])
        : [];

    if ($scope === 'network') {
      self::require_network_capability();

      Config::update_network_options($input);
      self::reschedule_network_inheriting_sites();

      self::redirect_with_message(
        'network',
        __('Network firewall settings saved.', Plugin::get_text_domain()),
        'updated'
      );
    }

    self::require_site_capability();

    if (is_multisite() && !Config::site_overrides_allowed()) {
      $input['configuration_source'] = Config::SOURCE_NETWORK;
    }

    Config::update_site_options($input);
    SyncScheduler::reschedule();

    self::redirect_with_message(
      'site',
      __('Site firewall settings saved.', Plugin::get_text_domain()),
      'updated'
    );
  }

  public static function handle_validate(): void {
    check_admin_referer(
      'firewall_sync_validate_cf_credentials',
      'firewall_sync_validate_cf_credentials_nonce'
    );

    $scope = self::posted_scope();
    $options = self::options_for_scope($scope);

    $client = new Client(
      $options['cloudflare_api_token'] ?? '',
      $options['cloudflare_zone_id'] ?? ''
    );

    $mode = $options['cloudflare_mode'] ?? 'zone_access_rules';

    $result = $mode === 'account_list'
      ? $client->validate_account_list(
        $options['cloudflare_account_id'] ?? '',
        $options['cloudflare_list_name'] ?? '',
        $options['cloudflare_list_id'] ?? ''
      )
      : $client->validate();

    self::redirect_with_message(
      $scope,
      $result
        ? __(
          'Cloudflare configuration validated successfully.',
          Plugin::get_text_domain()
        )
        : self::client_error_message(
          $client,
          __(
            'Cloudflare configuration validation failed.',
            Plugin::get_text_domain()
          )
        ),
      $result ? 'updated' : 'error'
    );
  }

  public static function handle_test_block(): void {
    check_admin_referer(
      'firewall_sync_test_block',
      'firewall_sync_test_block_nonce'
    );

    $scope = self::posted_scope();
    $options = self::options_for_scope($scope);
    $ip = sanitize_text_field(
      wp_unslash($_POST['firewall_sync_test_ip'] ?? '')
    );

    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
      self::redirect_with_message(
        $scope,
        __('Enter a valid IPv4 or IPv6 address.', Plugin::get_text_domain()),
        'error'
      );
    }

    $client = new Client(
      $options['cloudflare_api_token'] ?? '',
      $options['cloudflare_zone_id'] ?? ''
    );

    $mode = $options['cloudflare_mode'] ?? 'zone_access_rules';

    if ($mode === 'account_list') {
      $account_id = $options['cloudflare_account_id'] ?? '';
      $list_id = $client->resolve_account_list_id(
        $account_id,
        $options['cloudflare_list_name'] ?? '',
        $options['cloudflare_list_id'] ?? ''
      );

      if ($list_id === null) {
        self::redirect_with_message(
          $scope,
          self::client_error_message(
            $client,
            __(
              'The Cloudflare account list could not be resolved.',
              Plugin::get_text_domain()
            )
          ),
          'error'
        );
      }

      $already_exists = $client->account_list_contains_ip(
        $account_id,
        $list_id,
        $ip
      );

      if ($already_exists) {
        self::redirect_with_message(
          $scope,
          __(
            'The test IP address already exists in the Cloudflare account list. It was not modified.',
            Plugin::get_text_domain()
          ),
          'error'
        );
      }

      $create = $client->add_ip_to_account_list(
        $account_id,
        $list_id,
        $ip,
        'Greyrock Wordfence-Cloudflare Synchroniser test'
      );

      $delete = $create
        ? $client->remove_ip_from_account_list(
          $account_id,
          $list_id,
          $ip
        )
        : false;
    } else {
      $create = $client->create_block(
        $ip,
        'Greyrock Wordfence-Cloudflare Synchroniser test'
      );

      $delete = $create ? $client->delete_block($ip) : false;
    }

    self::redirect_with_message(
      $scope,
      $create && $delete
        ? __(
          'The test block was created and removed successfully.',
          Plugin::get_text_domain()
        )
        : self::client_error_message(
          $client,
          __(
            'The test block could not be created or removed.',
            Plugin::get_text_domain()
          )
        ),
      $create && $delete ? 'updated' : 'error'
    );
  }

  public static function handle_manual_list_ip(): void {
    check_admin_referer(
      'firewall_sync_manual_list_ip',
      'firewall_sync_manual_list_ip_nonce'
    );

    $scope = self::posted_scope();

    if ($scope === 'network') {
      self::require_network_capability();
    } else {
      self::require_site_capability();
    }

    $options = self::options_for_scope($scope);
    $mode = $options['cloudflare_mode'] ?? 'zone_access_rules';

    if ($mode !== 'account_list') {
      self::redirect_with_message(
        $scope,
        __(
          'Manual account-list management is available only in Account IP List mode.',
          Plugin::get_text_domain()
        ),
        'error'
      );
    }

    $ip = sanitize_text_field(
      wp_unslash($_POST['firewall_sync_manual_list_ip'] ?? '')
    );

    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
      self::redirect_with_message(
        $scope,
        __('Enter a valid IPv4 or IPv6 address.', Plugin::get_text_domain()),
        'error'
      );
    }

    $operation = sanitize_key(
      wp_unslash($_POST['firewall_sync_list_operation'] ?? '')
    );

    if (!in_array($operation, ['add', 'remove'], true)) {
      self::redirect_with_message(
        $scope,
        __('Select a valid list operation.', Plugin::get_text_domain()),
        'error'
      );
    }

    $reason = sanitize_text_field(
      wp_unslash(
        $_POST['firewall_sync_manual_list_reason'] ?? ''
      )
    );

    $reason = substr($reason, 0, 200);

    if ($operation === 'add' && $reason === '') {
      self::redirect_with_message(
        $scope,
        __(
          'Enter a reason before adding the IP address.',
          Plugin::get_text_domain()
        ),
        'error'
      );
    }

    $client = new Client(
      $options['cloudflare_api_token'] ?? '',
      $options['cloudflare_zone_id'] ?? ''
    );

    $account_id = $options['cloudflare_account_id'] ?? '';
    $list_name = ltrim(
      trim((string) ($options['cloudflare_list_name'] ?? '')),
      '$'
    );

    $list_id = $client->resolve_account_list_id(
      $account_id,
      $list_name,
      $options['cloudflare_list_id'] ?? ''
    );

    if ($list_id === null) {
      self::redirect_with_message(
        $scope,
        self::client_error_message(
          $client,
          __(
            'The Cloudflare account list could not be resolved.',
            Plugin::get_text_domain()
          )
        ),
        'error'
      );
    }

    if ($operation === 'add') {
      $already_exists = $client->account_list_contains_ip(
        $account_id,
        $list_id,
        $ip
      );

      if ($already_exists) {
        self::redirect_with_message(
          $scope,
          sprintf(
            __(
              'IP address %1$s already exists in Cloudflare list %2$s.',
              Plugin::get_text_domain()
            ),
            $ip,
            $list_name
          ),
          'updated'
        );
      }

      $success = $client->add_ip_to_account_list(
        $account_id,
        $list_id,
        $ip,
        'Manual Greyrock block: ' . $reason
      );

      $success_message = sprintf(
        __(
          'IP address %1$s was added to Cloudflare list %2$s.',
          Plugin::get_text_domain()
        ),
        $ip,
        $list_name
      );

      $failure_message = __(
        'The IP address could not be added to the Cloudflare list.',
        Plugin::get_text_domain()
      );
    } else {
      $success = $client->remove_ip_from_account_list(
        $account_id,
        $list_id,
        $ip
      );

      $success_message = sprintf(
        __(
          'IP address %1$s was removed from Cloudflare list %2$s.',
          Plugin::get_text_domain()
        ),
        $ip,
        $list_name
      );

      $failure_message = __(
        'The IP address could not be removed from the Cloudflare list.',
        Plugin::get_text_domain()
      );
    }

    self::redirect_with_message(
      $scope,
      $success
        ? $success_message
        : self::client_error_message(
          $client,
          $failure_message
        ),
      $success ? 'updated' : 'error'
    );
  }

  public static function handle_network_sync_now(): void {
    self::require_network_capability();

    check_admin_referer(
      'firewall_sync_network_sync_now',
      'firewall_sync_network_sync_now_nonce'
    );

    $successful_sites = 0;
    $failed_sites = [];
    $processed_sites = 0;

    foreach (get_sites(['fields' => 'ids']) as $blog_id) {
      switch_to_blog((int) $blog_id);

      try {
        /*
         * Network synchronization applies only to sites inheriting the
         * Network Admin configuration. Sites with independent settings
         * continue to use their own site-level controls and schedules.
         */
        if (!Config::uses_network_options()) {
          continue;
        }

        $processed_sites++;

        if (SyncScheduler::run_now()) {
          $successful_sites++;
          continue;
        }

        $site_name = get_bloginfo('name');
        $site_url = home_url('/');
        $error = SyncScheduler::get_last_error_message();

        $failed_sites[] = sprintf(
          '%1$s (%2$s): %3$s',
          $site_name !== '' ? $site_name : $site_url,
          $site_url,
          $error !== ''
            ? $error
            : __('Synchronization failed.', Plugin::get_text_domain())
        );
      } finally {
        restore_current_blog();
      }
    }

    if ($processed_sites === 0) {
      self::redirect_with_message(
        'network',
        __(
          'No sites currently inherit the Network Admin configuration.',
          Plugin::get_text_domain()
        ),
        'error'
      );
    }

    if (!empty($failed_sites)) {
      self::redirect_with_message(
        'network',
        sprintf(
          /* translators: 1: successful site count, 2: failed site details */
          __(
            'Network synchronization completed for %1$d site(s). Failures: %2$s',
            Plugin::get_text_domain()
          ),
          $successful_sites,
          implode(' | ', $failed_sites)
        ),
        'error'
      );
    }

    self::redirect_with_message(
      'network',
      sprintf(
        /* translators: %d: number of synchronized sites */
        __(
          'Network synchronization completed successfully for %d site(s).',
          Plugin::get_text_domain()
        ),
        $successful_sites
      ),
      'updated'
    );
  }

  public static function handle_sync_now(): void {
    self::require_site_capability();

    check_admin_referer(
      'firewall_sync_now',
      'firewall_sync_now_nonce'
    );

    $success = SyncScheduler::run_now();

    self::redirect_with_message(
      'site',
      $success
        ? __(
          'Synchronization completed successfully.',
          Plugin::get_text_domain()
        )
        : (
          SyncScheduler::get_last_error_message() !== ''
            ? SyncScheduler::get_last_error_message()
            : __('Synchronization failed.', Plugin::get_text_domain())
        ),
      $success ? 'updated' : 'error'
    );
  }

  public static function handle_cleanup_now(): void {
    self::require_site_capability();

    check_admin_referer(
      'firewall_sync_cleanup_now',
      'firewall_sync_cleanup_now_nonce'
    );

    if (is_multisite() && Config::uses_network_options()) {
      self::redirect_with_message(
        'site',
        __(
          'Cleanup is unavailable while this site inherits a shared Network Admin configuration.',
          Plugin::get_text_domain()
        ),
        'error'
      );
    }

    SyncScheduler::run_cleanup();

    self::redirect_with_message(
      'site',
      __('Cleanup completed.', Plugin::get_text_domain()),
      'updated'
    );
  }

  public static function handle_reconcile(): void {
    self::require_site_capability();

    check_admin_referer(
      'firewall_sync_reconcile',
      'firewall_sync_reconcile_nonce'
    );

    if (is_multisite() && Config::uses_network_options()) {
      self::redirect_with_message(
        'site',
        __(
          'Reconciliation is unavailable while this site inherits a shared Network Admin configuration.',
          Plugin::get_text_domain()
        ),
        'error'
      );
    }

    $options = Config::get_effective_options();

    $client = new Client(
      $options['cloudflare_api_token'] ?? '',
      $options['cloudflare_zone_id'] ?? ''
    );

    $result = Reconciler::run($client, $options);

    set_transient(
      'firewall_sync_reconcile_result',
      $result,
      60
    );

    wp_safe_redirect(
      admin_url('admin.php?page=firewall-sync-settings')
    );
    exit;
  }

  public static function handle_manual_block(): void {
    self::require_site_capability();

    check_admin_referer(
      'firewall_sync_manual_block',
      'firewall_sync_manual_block_nonce'
    );

    $ip = sanitize_text_field(
      wp_unslash($_POST['manual_ip'] ?? '')
    );

    $reason = sanitize_text_field(
      wp_unslash($_POST['manual_reason'] ?? 'manual')
    );

    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
      self::redirect_manual_block(
        __('Invalid IP address.', Plugin::get_text_domain()),
        'error'
      );
    }

    $options = Config::get_effective_options();

    $client = new Client(
      $options['cloudflare_api_token'] ?? '',
      $options['cloudflare_zone_id'] ?? ''
    );

    $mode = $options['cloudflare_mode'] ?? 'zone_access_rules';

    if ($mode === 'account_list') {
      $account_id = $options['cloudflare_account_id'] ?? '';
      $list_id = $client->resolve_account_list_id(
        $account_id,
        $options['cloudflare_list_name'] ?? '',
        $options['cloudflare_list_id'] ?? ''
      );

      if ($list_id === null) {
        self::redirect_manual_block(
          self::client_error_message(
            $client,
            __(
              'The Cloudflare account list could not be resolved.',
              Plugin::get_text_domain()
            )
          ),
          'error'
        );
      }

      $success = $client->add_ip_to_account_list(
        $account_id,
        $list_id,
        $ip,
        'Manual block: ' . $reason
      );
    } else {
      $success = $client->create_block(
        $ip,
        'Manual block: ' . $reason
      );
    }

    if ($success) {
      BlockLogger::log($ip, 'manual: ' . $reason);

      self::redirect_manual_block(
        __('IP address blocked successfully.', Plugin::get_text_domain()),
        'updated'
      );
    }

    self::redirect_manual_block(
      self::client_error_message(
        $client,
        __(
          'The IP address could not be blocked.',
          Plugin::get_text_domain()
        )
      ),
      'error'
    );
  }

  /**
   * Reschedule sites whose effective interval comes from Network Admin.
   */
  private static function reschedule_network_inheriting_sites(): void {
    if (!is_multisite()) {
      SyncScheduler::reschedule();
      return;
    }

    foreach (get_sites(['fields' => 'ids']) as $blog_id) {
      switch_to_blog((int) $blog_id);

      try {
        if (Config::uses_network_options()) {
          SyncScheduler::reschedule();
        }
      } finally {
        restore_current_blog();
      }
    }
  }

  private static function options_for_scope(string $scope): array {
    if ($scope === 'network') {
      self::require_network_capability();

      return Config::get_network_options();
    }

    self::require_site_capability();

    return Config::get_effective_options();
  }

  private static function posted_scope(): string {
    $scope = sanitize_key(
      wp_unslash($_POST['firewall_sync_scope'] ?? 'site')
    );

    return $scope === 'network' ? 'network' : 'site';
  }

  private static function require_network_capability(): void {
    if (
      !is_multisite()
      || !current_user_can('manage_network_options')
    ) {
      wp_die(
        esc_html__(
          'You do not have permission to administer network firewall settings.',
          Plugin::get_text_domain()
        )
      );
    }
  }

  private static function require_site_capability(): void {
    if (!current_user_can('manage_options')) {
      wp_die(
        esc_html__(
          'You do not have permission to administer firewall settings.',
          Plugin::get_text_domain()
        )
      );
    }
  }

  private static function client_error_message(
    Client $client,
    string $fallback
  ): string {
    $message = $client->get_last_error_message();

    return $message !== '' ? $message : $fallback;
  }

  private static function redirect_with_message(
    string $scope,
    string $message,
    string $type
  ): void {
    add_settings_error(
      'firewall_sync_messages',
      'firewall_sync_message',
      $message,
      $type
    );

    set_transient(
      'settings_errors',
      get_settings_errors(),
      30
    );

    $url = $scope === 'network'
      ? network_admin_url(
        'admin.php?page=firewall-sync-settings&settings-updated=true'
      )
      : admin_url(
        'admin.php?page=firewall-sync-settings&settings-updated=true'
      );

    wp_safe_redirect($url);
    exit;
  }

  private static function redirect_manual_block(
    string $message,
    string $type
  ): void {
    add_settings_error(
      'firewall_sync_manual_block',
      'firewall_sync_manual_block_message',
      $message,
      $type
    );

    set_transient(
      'settings_errors',
      get_settings_errors(),
      30
    );

    wp_safe_redirect(
      admin_url(
        'admin.php?page=firewall-manual-block&settings-updated=true'
      )
    );
    exit;
  }
}
