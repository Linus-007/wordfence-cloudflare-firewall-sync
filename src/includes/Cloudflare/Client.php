<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Cloudflare;

use WPCF\FirewallSync\Plugin;

final class Client {
  private string $token;
  private string $zone;
  private string $apiBase = 'https://api.cloudflare.com/client/v4';

  /**
   * Account-list items cached for the lifetime of this Client instance.
   *
   * Keys identify the Cloudflare account and list. Each value maps an IP
   * address to its Cloudflare list-item ID.
   *
   * @var array<string, array<string, string>>
   */
  private array $accountListItemCache = [];

  /**
   * Most recent Cloudflare or local validation error.
   *
   * @var array{operation: string, message: string, http_code: int|null}|null
   */
  private ?array $lastError = null;

  public function __construct(string $token, string $zone) {
    $this->token = $token;
    $this->zone = $zone;
  }

  public function validate(): bool {
    if ($this->zone === '') {
      return $this->fail(
        'validate_zone',
        __('Cloudflare Zone ID is required.', Plugin::get_text_domain())
      );
    }

    $url = $this->apiBase . "/zones/{$this->zone}";
    $response = wp_remote_get($url, $this->get_request_args());

    return $this->response_succeeded(
      'validate_zone',
      $response,
      [200]
    );
  }

  public function validate_account_list(
    string $account_id,
    string $list_id
  ): bool {
    if ($account_id === '') {
      return $this->fail(
        'validate_account_list',
        __('Cloudflare Account ID is required.', Plugin::get_text_domain())
      );
    }

    if ($list_id === '') {
      return $this->fail(
        'validate_account_list',
        __('Cloudflare List ID is required.', Plugin::get_text_domain())
      );
    }

    $url = $this->apiBase
      . "/accounts/{$account_id}/rules/lists/{$list_id}";

    $response = wp_remote_get($url, $this->get_request_args());

    return $this->response_succeeded(
      'validate_account_list',
      $response,
      [200]
    );
  }

  /**
   * Determine whether an IP address already exists in an account list.
   */
  public function account_list_contains_ip(
    string $account_id,
    string $list_id,
    string $ip
  ): bool {
    if (
      $account_id === ''
      || $list_id === ''
      || !filter_var($ip, FILTER_VALIDATE_IP)
    ) {
      return false;
    }

    $items = $this->get_account_list_item_map(
      $account_id,
      $list_id
    );

    return $items !== null && array_key_exists($ip, $items);
  }

  /**
   * Add one IP address to an account list.
   *
   * Existing entries are treated as successful so synchronization remains
   * idempotent.
   */
  public function add_ip_to_account_list(
    string $account_id,
    string $list_id,
    string $ip,
    string $comment = ''
  ): bool {
    if (
      $account_id === ''
      || $list_id === ''
      || !filter_var($ip, FILTER_VALIDATE_IP)
    ) {
      return false;
    }

    $items = $this->get_account_list_item_map(
      $account_id,
      $list_id
    );

    if ($items === null) {
      return false;
    }

    if (array_key_exists($ip, $items)) {
      $this->clear_last_error();

      return true;
    }

    $added = $this->create_account_list_item(
      $account_id,
      $list_id,
      $ip,
      $comment
    );

    if ($added) {
      /*
       * The insertion response is not needed for later operations in this
       * request. An empty ID is sufficient to preserve membership and avoid
       * another full-list lookup.
       */
      $cache_key = $this->account_list_cache_key(
        $account_id,
        $list_id
      );

      $this->accountListItemCache[$cache_key][$ip] = '';
    }

    return $added;
  }

  /**
   * Add a synchronization batch after loading the account list once.
   *
   * @param array<int, array{ip?: string, reason?: string}> $entries
   * @return array<int, string> IP addresses that could not be synchronized.
   */
  public function batch_add_ips_to_account_list(
    string $account_id,
    string $list_id,
    array $entries
  ): array {
    $failed = [];

    if ($account_id === '' || $list_id === '') {
      foreach ($entries as $entry) {
        $failed[] = (string) ($entry['ip'] ?? '');
      }

      return $failed;
    }

    $items = $this->get_account_list_item_map(
      $account_id,
      $list_id
    );

    if ($items === null) {
      foreach ($entries as $entry) {
        $failed[] = (string) ($entry['ip'] ?? '');
      }

      return $failed;
    }

    $cache_key = $this->account_list_cache_key(
      $account_id,
      $list_id
    );

    foreach ($entries as $entry) {
      $ip = (string) ($entry['ip'] ?? '');

      if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        $failed[] = $ip;
        continue;
      }

      if (array_key_exists($ip, $items)) {
        continue;
      }

      $reason = (string) (
        $entry['reason']
        ?? __('Unknown', Plugin::get_text_domain())
      );

      $added = $this->create_account_list_item(
        $account_id,
        $list_id,
        $ip,
        'Wordfence sync: ' . $reason
      );

      if (!$added) {
        $failed[] = $ip;
        continue;
      }

      $items[$ip] = '';
      $this->accountListItemCache[$cache_key][$ip] = '';
    }

    return $failed;
  }

  /**
   * Remove an IP from an account list.
   *
   * An already-absent IP is a successful final state.
   */
  public function remove_ip_from_account_list(
    string $account_id,
    string $list_id,
    string $ip
  ): bool {
    if (
      $account_id === ''
      || $list_id === ''
      || !filter_var($ip, FILTER_VALIDATE_IP)
    ) {
      return false;
    }

    $items = $this->get_account_list_item_map(
      $account_id,
      $list_id
    );

    if ($items === null) {
      return false;
    }

    if (!array_key_exists($ip, $items)) {
      $this->clear_last_error();

      return true;
    }

    $item_id = $items[$ip];

    /*
     * A newly inserted cached entry may not have its item ID. Refresh only
     * in that unusual same-request add-then-remove case.
     */
    if ($item_id === '') {
      $this->clear_account_list_cache($account_id, $list_id);

      $items = $this->get_account_list_item_map(
        $account_id,
        $list_id
      );

      if ($items === null) {
        return false;
      }

      if (!array_key_exists($ip, $items)) {
        $this->clear_last_error();

        return true;
      }

      $item_id = $items[$ip];

      if ($item_id === '') {
        return false;
      }
    }

    $url = $this->apiBase
      . "/accounts/{$account_id}/rules/lists/{$list_id}/items/{$item_id}";

    $response = wp_remote_request(
      $url,
      [
        'method' => 'DELETE',
        'headers' => $this->get_headers(true),
      ]
    );

    $deleted = $this->response_succeeded(
      'remove_account_list_item',
      $response
    );

    if ($deleted) {
      $cache_key = $this->account_list_cache_key(
        $account_id,
        $list_id
      );

      unset($this->accountListItemCache[$cache_key][$ip]);
    }

    return $deleted;
  }

  /**
   * Return the current IP addresses in an account list.
   */
  public function get_current_account_list_ips(
    string $account_id,
    string $list_id
  ): array {
    $items = $this->get_account_list_item_map(
      $account_id,
      $list_id
    );

    return $items === null ? [] : array_keys($items);
  }

  /**
   * Submit one account-list item without performing another list lookup.
   */
  private function create_account_list_item(
    string $account_id,
    string $list_id,
    string $ip,
    string $comment = ''
  ): bool {
    $url = $this->apiBase
      . "/accounts/{$account_id}/rules/lists/{$list_id}/items";

    $item = ['ip' => $ip];

    if ($comment !== '') {
      $item['comment'] = $comment;
    }

    $response = wp_remote_post(
      $url,
      [
        'headers' => $this->get_headers(true),
        'body' => wp_json_encode([$item]),
      ]
    );

    return $this->response_succeeded(
      'add_account_list_item',
      $response
    );
  }

  /**
   * Load and cache all account-list items.
   *
   * Null indicates that Cloudflare could not provide a complete list.
   *
   * @return array<string, string>|null
   */
  private function get_account_list_item_map(
    string $account_id,
    string $list_id
  ): ?array {
    if ($account_id === '' || $list_id === '') {
      return null;
    }

    $cache_key = $this->account_list_cache_key(
      $account_id,
      $list_id
    );

    if (array_key_exists($cache_key, $this->accountListItemCache)) {
      return $this->accountListItemCache[$cache_key];
    }

    $items_by_ip = [];
    $page = 1;

    do {
      $url = $this->apiBase
        . "/accounts/{$account_id}/rules/lists/{$list_id}/items"
        . "?page={$page}&per_page=50";

      $response = wp_remote_get(
        $url,
        $this->get_request_args()
      );

      if (
        !$this->response_succeeded(
          'read_account_list',
          $response,
          [200]
        )
      ) {
        return null;
      }

      $body = json_decode(
        wp_remote_retrieve_body($response),
        true
      );

      if (
        !is_array($body)
        || !isset($body['result'])
        || !is_array($body['result'])
      ) {
        $this->fail(
          'read_account_list',
          __(
            'Cloudflare returned an invalid account-list response.',
            Plugin::get_text_domain()
          )
        );

        return null;
      }

      foreach ($body['result'] as $item) {
        $ip = (string) ($item['ip'] ?? '');
        $item_id = (string) ($item['id'] ?? '');

        if (
          filter_var($ip, FILTER_VALIDATE_IP)
          && $item_id !== ''
        ) {
          $items_by_ip[$ip] = $item_id;
        }
      }

      $total_pages = max(
        1,
        (int) ($body['result_info']['total_pages'] ?? 1)
      );

      $page++;
    } while ($page <= $total_pages);

    $this->accountListItemCache[$cache_key] = $items_by_ip;

    return $items_by_ip;
  }

  private function account_list_cache_key(
    string $account_id,
    string $list_id
  ): string {
    return $account_id . ':' . $list_id;
  }

  private function clear_account_list_cache(
    string $account_id,
    string $list_id
  ): void {
    unset(
      $this->accountListItemCache[
        $this->account_list_cache_key($account_id, $list_id)
      ]
    );
  }

  public function create_block(
    string $ip,
    string $notes = ''
  ): bool {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
      return $this->fail(
        'create_zone_access_rule',
        __('Invalid IP address.', Plugin::get_text_domain())
      );
    }

    if ($this->zone === '') {
      return $this->fail(
        'create_zone_access_rule',
        __('Cloudflare Zone ID is required.', Plugin::get_text_domain())
      );
    }

    $url = $this->apiBase
      . "/zones/{$this->zone}/firewall/access_rules/rules";

    $data = [
      'mode' => 'block',
      'configuration' => [
        'target' => 'ip',
        'value' => $ip,
      ],
      'notes' => $notes !== ''
        ? $notes
        : __(
          'Wordfence Sync Block',
          Plugin::get_text_domain()
        ),
    ];

    $response = wp_remote_post(
      $url,
      [
        'headers' => $this->get_headers(true),
        'body' => wp_json_encode($data),
      ]
    );

    return $this->response_succeeded(
      'create_zone_access_rule',
      $response
    );
  }

  public function delete_block(string $ip): bool {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
      return $this->fail(
        'delete_zone_access_rule',
        __('Invalid IP address.', Plugin::get_text_domain())
      );
    }

    if ($this->zone === '') {
      return $this->fail(
        'delete_zone_access_rule',
        __('Cloudflare Zone ID is required.', Plugin::get_text_domain())
      );
    }

    $encoded_ip = rawurlencode($ip);

    $list_url = $this->apiBase
      . "/zones/{$this->zone}/firewall/access_rules/rules"
      . "?mode=block"
      . "&configuration.target=ip"
      . "&configuration.value={$encoded_ip}";

    $list = wp_remote_get(
      $list_url,
      $this->get_request_args()
    );

    if (
      !$this->response_succeeded(
        'find_zone_access_rule',
        $list,
        [200]
      )
    ) {
      return false;
    }

    $body = json_decode(
      wp_remote_retrieve_body($list),
      true
    );

    if (!is_array($body) || !isset($body['result'])) {
      return $this->fail(
        'find_zone_access_rule',
        __(
          'Cloudflare returned an invalid access-rule response.',
          Plugin::get_text_domain()
        )
      );
    }

    $rule_id = $body['result'][0]['id'] ?? null;

    /*
     * The desired final state has already been reached.
     */
    if (!$rule_id) {
      $this->clear_last_error();

      return true;
    }

    $delete_url = $this->apiBase
      . "/zones/{$this->zone}/firewall/access_rules/rules/{$rule_id}";

    $response = wp_remote_request(
      $delete_url,
      [
        'method' => 'DELETE',
        'headers' => $this->get_headers(true),
      ]
    );

    return $this->response_succeeded(
      'delete_zone_access_rule',
      $response
    );
  }

  public function clear_last_error(): void {
    $this->lastError = null;
  }

  public function get_last_error_message(): string {
    if ($this->lastError === null) {
      return '';
    }

    $operation = str_replace(
      '_',
      ' ',
      $this->lastError['operation']
    );

    $message = sprintf(
      /* translators: 1: Cloudflare operation, 2: error message */
      __('Cloudflare %1$s failed: %2$s', Plugin::get_text_domain()),
      $operation,
      $this->lastError['message']
    );

    if ($this->lastError['http_code'] !== null) {
      $message .= sprintf(
        /* translators: %d: HTTP response code */
        __(' (HTTP %d)', Plugin::get_text_domain()),
        $this->lastError['http_code']
      );
    }

    return $message;
  }

  /**
   * Store a local validation or Cloudflare error.
   */
  private function fail(
    string $operation,
    string $message,
    ?int $http_code = null
  ): bool {
    $this->lastError = [
      'operation' => $operation,
      'message' => $message,
      'http_code' => $http_code,
    ];

    return false;
  }

  /**
   * Validate a WordPress HTTP API response and retain useful diagnostics.
   *
   * @param mixed $response
   * @param array<int, int>|null $expected_codes
   */
  private function response_succeeded(
    string $operation,
    $response,
    ?array $expected_codes = null
  ): bool {
    if (is_wp_error($response)) {
      return $this->fail(
        $operation,
        $response->get_error_message()
      );
    }

    $http_code = wp_remote_retrieve_response_code($response);
    $successful = $expected_codes !== null
      ? in_array($http_code, $expected_codes, true)
      : ($http_code >= 200 && $http_code < 300);

    if ($successful) {
      $this->clear_last_error();

      return true;
    }

    $body = json_decode(
      wp_remote_retrieve_body($response),
      true
    );

    $message = '';

    if (is_array($body)) {
      $errors = $body['errors'] ?? [];

      if (
        is_array($errors)
        && isset($errors[0])
        && is_array($errors[0])
      ) {
        $cloudflare_code = isset($errors[0]['code'])
          ? (string) $errors[0]['code']
          : '';

        $cloudflare_message = isset($errors[0]['message'])
          ? (string) $errors[0]['message']
          : '';

        if ($cloudflare_code !== '' && $cloudflare_message !== '') {
          $message = sprintf(
            'Cloudflare error %s: %s',
            $cloudflare_code,
            $cloudflare_message
          );
        } elseif ($cloudflare_message !== '') {
          $message = $cloudflare_message;
        }
      }

      if ($message === '' && !empty($body['message'])) {
        $message = (string) $body['message'];
      }
    }

    if ($message === '') {
      $response_message = wp_remote_retrieve_response_message(
        $response
      );

      $message = $response_message !== ''
        ? $response_message
        : __(
          'Cloudflare returned an unsuccessful response.',
          Plugin::get_text_domain()
        );
    }

    return $this->fail(
      $operation,
      sanitize_text_field($message),
      $http_code > 0 ? $http_code : null
    );
  }

  private function get_headers(bool $with_content_type = false): array {
    $headers = [
      'Authorization' => 'Bearer ' . $this->token,
    ];

    if ($with_content_type) {
      $headers['Content-Type'] = 'application/json';
    }

    return $headers;
  }

  private function get_request_args(bool $with_content_type = false): array {
    return [
      'headers' => $this->get_headers($with_content_type),
    ];
  }

  public function get_current_blocked_ips(): array {
    $ip_list = [];
    $page = 1;

    do {
      $url = $this->apiBase . "/zones/{$this->zone}/firewall/access_rules/rules?mode=block&page={$page}&per_page=50";

      $response = wp_remote_get($url, $this->get_request_args());

      if (is_wp_error($response)) {
        break;
      }

      $body = json_decode(wp_remote_retrieve_body($response), true);
      $result = $body['result'] ?? [];

      foreach ($result as $rule) {
        if (($rule['configuration']['target'] ?? '') === 'ip') {
          $ip_list[] = $rule['configuration']['value'];
        }
      }

      $has_more = ($body['result_info']['total_pages'] ?? 1) > $page;
      
      $page += 1;
    } while ($has_more);

    return array_unique($ip_list);
  }

  public function batch_block(array $ips): array {
    $failed = [];

    foreach ($ips as $entry) {
      $ip = $entry['ip'] ?? '';

      if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        $failed[] = $ip;
        continue;
      }

      $notes = __('Wordfence Sync', Plugin::get_text_domain()) . ': ' . ($entry['reason'] ?? __('Unknown', Plugin::get_text_domain()));

      if (!$this->create_block($ip, $notes)) {
        $failed[] = $ip;
      }
    }

    return $failed;
  }
}
