<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Cloudflare;

use WPCF\FirewallSync\Services\CloudflareIdentifierValidator;

use WPCF\FirewallSync\Services\IpValidator;

use WPCF\FirewallSync\Plugin;

final class Client {
  private const MAX_RESPONSE_BYTES = 1048576;
  private const MAX_COMMENT_LENGTH = 200;
  private const HTTP_TIMEOUT_SECONDS = 30;
  private const HTTP_REDIRECTION_LIMIT = 3;

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
   * Account-list IDs resolved from their visible Cloudflare names.
   *
   * @var array<string, string>
   */
  private array $accountListIdCache = [];

  /**
   * Most recent Cloudflare or local validation error.
   *
   * @var array{operation: string, message: string, http_code: int|null}|null
   */
  private ?array $lastError = null;

  public function __construct(string $token, string $zone) {
    $this->token =
      CloudflareIdentifierValidator::normalize_api_token($token);
    $this->zone =
      CloudflareIdentifierValidator::normalize_zone_id($zone);
  }

  public function validate(): bool {
    if (
      !CloudflareIdentifierValidator::validate_api_token(
        $this->token
      )
    ) {
      return $this->fail(
        'validate_token',
        __(
          'Cloudflare API Token is missing or malformed.',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        )
      );
    }

    if (
      !CloudflareIdentifierValidator::validate_zone_id(
        $this->zone
      )
    ) {
      return $this->fail(
        'validate_zone',
        __(
          'Cloudflare Zone ID must contain exactly 32 hexadecimal characters.',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        )
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
    string $list_name,
    string $legacy_list_id = ''
  ): bool {
    return $this->resolve_account_list_id(
      $account_id,
      $list_name,
      $legacy_list_id
    ) !== null;
  }

  /**
   * Resolve a visible Cloudflare account-list name to its internal API ID.
   *
   * Cloudflare displays a list variable such as
   * $wordfence_hot_blocklist, but the ordinary dashboard does not expose
   * the internal list UUID required by the API.
   *
   * The legacy List ID parameter preserves compatibility with existing
   * installations that already stored the internal ID.
   */
  public function resolve_account_list_id(
    string $account_id,
    string $list_name,
    string $legacy_list_id = ''
  ): ?string {
    if (
      !CloudflareIdentifierValidator::validate_api_token(
        $this->token
      )
    ) {
      $this->fail(
        'validate_token',
        __(
          'Cloudflare API Token is missing or malformed.',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        )
      );

      return null;
    }

    $account_id =
      CloudflareIdentifierValidator::normalize_account_id(
        $account_id
      );

    $list_name =
      CloudflareIdentifierValidator::normalize_list_name(
        $list_name
      );

    $legacy_list_id =
      CloudflareIdentifierValidator::normalize_list_id(
        $legacy_list_id
      );

    if (
      !CloudflareIdentifierValidator::validate_account_id(
        $account_id
      )
    ) {
      $this->fail(
        'resolve_account_list',
        __(
          'Cloudflare Account ID must contain exactly 32 hexadecimal characters.',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        )
      );

      return null;
    }

    if ($list_name === '') {
      if ($legacy_list_id === '') {
        $this->fail(
          'resolve_account_list',
          __('Cloudflare List Name is required.', 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare')
        );

        return null;
      }

      $cache_key = $account_id . ':legacy:' . $legacy_list_id;

      if (isset($this->accountListIdCache[$cache_key])) {
        return $this->accountListIdCache[$cache_key];
      }

      $url = $this->apiBase
        . "/accounts/{$account_id}/rules/lists/{$legacy_list_id}";

      $response = wp_remote_get($url, $this->get_request_args());

      $body = null;

      if (
        !$this->response_succeeded(
          'validate_legacy_account_list',
          $response,
          [200],
          $body
        )
      ) {
        return null;
      }

      $result = $body['result'] ?? null;

      if (
        !is_array($result)
        || (string) ($result['kind'] ?? '') !== 'ip'
      ) {
        $this->fail(
          'validate_legacy_account_list',
          __(
            'The stored Cloudflare list is not an IP list.',
            'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
          )
        );

        return null;
      }

      $this->accountListIdCache[$cache_key] = $legacy_list_id;
      $this->clear_last_error();

      return $legacy_list_id;
    }

    if (
      !CloudflareIdentifierValidator::validate_list_name(
        $list_name
      )
    ) {
      $this->fail(
        'resolve_account_list',
        __(
          'Cloudflare List Name may contain only lowercase letters, numbers and underscores.',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        )
      );

      return null;
    }

    $cache_key = $account_id . ':name:' . $list_name;

    if (isset($this->accountListIdCache[$cache_key])) {
      return $this->accountListIdCache[$cache_key];
    }

    $matches = [];
    $page = 1;

    do {
      $url = $this->apiBase
        . "/accounts/{$account_id}/rules/lists"
        . "?page={$page}&per_page=50";

      $response = wp_remote_get(
        $url,
        $this->get_request_args()
      );

      $body = null;

      if (
        !$this->response_succeeded(
          'list_account_lists',
          $response,
          [200],
          $body
        )
      ) {
        return null;
      }

      $result = $this->response_result_array(
        'list_account_lists',
        $body
      );

      if ($result === null) {
        return null;
      }

      foreach ($result as $list) {
        if (!is_array($list)) {
          $this->fail(
            'list_account_lists',
            __(
              'Cloudflare returned an invalid account-list item.',
              'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
            )
          );

          return null;
        }
        if ((string) ($list['name'] ?? '') !== $list_name) {
          continue;
        }

        $matches[] = [
          'id' => (string) ($list['id'] ?? ''),
          'kind' => (string) ($list['kind'] ?? ''),
        ];
      }

      $total_pages = $this->response_total_pages(
        'list_account_lists',
        $body
      );

      if ($total_pages === null) {
        return null;
      }

      $page++;
    } while ($page <= $total_pages);

    if (count($matches) === 0) {
      $this->fail(
        'resolve_account_list',
        sprintf(
          /* translators: %s: Cloudflare list name. */
          __(
            'Cloudflare IP list "%s" was not found in this account.',
            'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
          ),
          $list_name
        )
      );

      return null;
    }

    if (count($matches) > 1) {
      $this->fail(
        'resolve_account_list',
        sprintf(
          /* translators: %s: Cloudflare list name. */
          __(
            'Cloudflare returned more than one list named "%s".',
            'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
          ),
          $list_name
        )
      );

      return null;
    }

    $list_id = $matches[0]['id'];
    $kind = $matches[0]['kind'];

    if (
      !CloudflareIdentifierValidator::validate_list_id(
        $list_id
      )
    ) {
      $this->fail(
        'resolve_account_list',
        __(
          'Cloudflare returned an invalid internal List ID.',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        )
      );

      return null;
    }

    $list_id =
      CloudflareIdentifierValidator::normalize_list_id(
        $list_id
      );

    if ($kind !== 'ip') {
      $this->fail(
        'resolve_account_list',
        sprintf(
          /* translators: %s: Cloudflare list name. */
          __(
            'Cloudflare list "%s" is not an IP list.',
            'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
          ),
          $list_name
        )
      );

      return null;
    }

    $this->accountListIdCache[$cache_key] = $list_id;
    $this->clear_last_error();

    return $list_id;
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
      !CloudflareIdentifierValidator::validate_account_id(
        $account_id
      )
      || !CloudflareIdentifierValidator::validate_list_id(
        $list_id
      )
      || !IpValidator::validate_public_ip($ip)
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
      !CloudflareIdentifierValidator::validate_account_id(
        $account_id
      )
      || !CloudflareIdentifierValidator::validate_list_id(
        $list_id
      )
      || !IpValidator::validate_public_ip($ip)
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

    if (!CloudflareIdentifierValidator::validate_account_id(
      $account_id
    ) || !CloudflareIdentifierValidator::validate_list_id(
      $list_id
    )) {
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

      if (!IpValidator::validate_public_ip($ip)) {
        $failed[] = $ip;
        continue;
      }

      if (array_key_exists($ip, $items)) {
        continue;
      }

      $reason = (string) (
        $entry['reason']
        ?? __('Unknown', 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare')
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
      !CloudflareIdentifierValidator::validate_account_id(
        $account_id
      )
      || !CloudflareIdentifierValidator::validate_list_id(
        $list_id
      )
      || !IpValidator::validate_public_ip($ip)
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
     * Cloudflare may not expose a newly added list item immediately.
     * Retry the item lookup before attempting deletion. Do not report
     * success merely because the new item has not appeared yet.
     */
    if ($item_id === '') {
      $item_id = '';

      for ($attempt = 0; $attempt < 5; $attempt++) {
        if ($attempt > 0) {
          usleep(500000);
        }

        $this->clear_account_list_cache($account_id, $list_id);

        $items = $this->get_account_list_item_map(
          $account_id,
          $list_id
        );

        if ($items === null) {
          return false;
        }

        if (
          array_key_exists($ip, $items)
          && $items[$ip] !== ''
        ) {
          $item_id = $items[$ip];
          break;
        }
      }

      if ($item_id === '') {
        return $this->fail(
          'remove_account_list_item',
          __(
            'Cloudflare did not expose the newly added list item in time for deletion. Remove it manually or try again.',
            'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
          )
        );
      }
    }

    $url = $this->apiBase
      . "/accounts/{$account_id}/rules/lists/{$list_id}/items";

    $response = wp_remote_request(
      $url,
      $this->get_request_args(
        true,
        [
          'method' => 'DELETE',
          'body' => wp_json_encode(
            [
              'items' => [
                [
                  'id' => $item_id,
                ],
              ],
            ]
          ),
        ]
      )
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
    $comment = self::normalize_comment($comment);

    if ($comment !== '') {
      $item['comment'] = $comment;
    }

    $response = wp_remote_post(
      $url,
      $this->get_request_args(
        true,
        [
          'body' => wp_json_encode([$item]),
        ]
      )
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
    if (!CloudflareIdentifierValidator::validate_account_id(
      $account_id
    ) || !CloudflareIdentifierValidator::validate_list_id(
      $list_id
    )) {
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

      $body = null;

      if (
        !$this->response_succeeded(
          'read_account_list',
          $response,
          [200],
          $body
        )
      ) {
        return null;
      }

      $result = $this->response_result_array(
        'read_account_list',
        $body
      );

      if ($result === null) {
        return null;
      }

      foreach ($result as $item) {
        if (!is_array($item)) {
          $this->fail(
            'read_account_list',
            __(
              'Cloudflare returned an invalid account-list item.',
              'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
            )
          );

          return null;
        }
        $ip = (string) ($item['ip'] ?? '');
        $item_id = (string) ($item['id'] ?? '');

        if (
          IpValidator::validate_public_ip($ip)
          && $item_id !== ''
        ) {
          $items_by_ip[$ip] = $item_id;
        }
      }

      $total_pages = $this->response_total_pages(
        'read_account_list',
        $body
      );

      if ($total_pages === null) {
        return null;
      }

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
    if (!IpValidator::validate_public_ip($ip)) {
      return $this->fail(
        'create_zone_access_rule',
        __('Invalid or non-public IP address.', 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare')
      );
    }

    if ($this->zone === '') {
      return $this->fail(
        'create_zone_access_rule',
        __('Cloudflare Zone ID is required.', 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare')
      );
    }

    $url = $this->apiBase
      . "/zones/{$this->zone}/firewall/access_rules/rules";

    $notes = self::normalize_comment($notes);

    if ($notes === '') {
      $notes = __(
        'Wordfence Sync Block',
        'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
      );
    }

    $data = [
      'mode' => 'block',
      'configuration' => [
        'target' => 'ip',
        'value' => $ip,
      ],
      'notes' => $notes,
    ];

    $response = wp_remote_post(
      $url,
      $this->get_request_args(
        true,
        [
          'body' => wp_json_encode($data),
        ]
      )
    );

    return $this->response_succeeded(
      'create_zone_access_rule',
      $response
    );
  }

  public function delete_block(string $ip): bool {
    if (!IpValidator::validate_public_ip($ip)) {
      return $this->fail(
        'delete_zone_access_rule',
        __('Invalid or non-public IP address.', 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare')
      );
    }

    if ($this->zone === '') {
      return $this->fail(
        'delete_zone_access_rule',
        __('Cloudflare Zone ID is required.', 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare')
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

    $body = null;

    if (
      !$this->response_succeeded(
        'find_zone_access_rule',
        $list,
        [200],
        $body
      )
    ) {
      return false;
    }

    $result = $this->response_result_array(
      'find_zone_access_rule',
      $body
    );

    if ($result === null) {
      return false;
    }

    $first_rule = $result[0] ?? null;
    $rule_id = is_array($first_rule)
      ? (string) ($first_rule['id'] ?? '')
      : '';

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
      $this->get_request_args(
        true,
        [
          'method' => 'DELETE',
        ]
      )
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
      __('Cloudflare %1$s failed: %2$s', 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'),
      $operation,
      $this->lastError['message']
    );

    if ($this->lastError['http_code'] !== null) {
      $message .= sprintf(
        /* translators: %d: HTTP response code */
        __(' (HTTP %d)', 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'),
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
  /**
   * Decode and validate a Cloudflare JSON response body.
   *
   * @param mixed $response WordPress HTTP API response.
   * @return array<string, mixed>|null
   */
  private function decode_json_response(
    string $operation,
    $response
  ): ?array {
    if (is_wp_error($response)) {
      $this->fail(
        $operation,
        $response->get_error_message()
      );

      return null;
    }

    $raw_body = wp_remote_retrieve_body($response);

    if (!is_string($raw_body) || trim($raw_body) === '') {
      $this->fail(
        $operation,
        __(
          'Cloudflare returned an empty response body.',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        ),
        self::response_http_code($response)
      );

      return null;
    }

    if (strlen($raw_body) > self::MAX_RESPONSE_BYTES) {
      $this->fail(
        $operation,
        __(
          'Cloudflare returned an unexpectedly large response.',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        ),
        self::response_http_code($response)
      );

      return null;
    }

    try {
      $body = json_decode(
        $raw_body,
        true,
        512,
        JSON_THROW_ON_ERROR
      );
    } catch (\JsonException) {
      $this->fail(
        $operation,
        __(
          'Cloudflare returned invalid JSON.',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        ),
        self::response_http_code($response)
      );

      return null;
    }

    if (!is_array($body)) {
      $this->fail(
        $operation,
        __(
          'Cloudflare returned a JSON response with an invalid top-level structure.',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        ),
        self::response_http_code($response)
      );

      return null;
    }

    if (
      !array_key_exists('success', $body)
      || !is_bool($body['success'])
    ) {
      $this->fail(
        $operation,
        __(
          'Cloudflare returned a response without a valid success indicator.',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        ),
        self::response_http_code($response)
      );

      return null;
    }

    foreach (['errors', 'messages'] as $field) {
      if (
        array_key_exists($field, $body)
        && !is_array($body[$field])
      ) {
        $this->fail(
          $operation,
          sprintf(
            /* translators: %s: response field name. */
            __(
              'Cloudflare returned an invalid %s field.',
              'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
            ),
            $field
          ),
          self::response_http_code($response)
        );

        return null;
      }
    }

    if (
      array_key_exists('success', $body)
      && $body['success'] === false
    ) {
      $this->fail(
        $operation,
        $this->cloudflare_error_message($body),
        self::response_http_code($response)
      );

      return null;
    }

    return $body;
  }

  /**
   * Return the result array from a validated Cloudflare response.
   *
   * @param array<string, mixed> $body
   * @return array<int|string, mixed>|null
   */
  private function response_result_array(
    string $operation,
    array $body
  ): ?array {
    if (
      !array_key_exists('result', $body)
      || !is_array($body['result'])
    ) {
      $this->fail(
        $operation,
        __(
          'Cloudflare returned an invalid result structure.',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        )
      );

      return null;
    }

    return $body['result'];
  }

  /**
   * Determine the number of pages in a paginated response.
   *
   * @param array<string, mixed> $body
   */
  private function response_total_pages(
    string $operation,
    array $body
  ): ?int {
    if (!array_key_exists('result_info', $body)) {
      return 1;
    }

    if (!is_array($body['result_info'])) {
      $this->fail(
        $operation,
        __(
          'Cloudflare returned invalid pagination information.',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        )
      );

      return null;
    }

    $total_pages = $body['result_info']['total_pages'] ?? 1;

    if (
      !is_int($total_pages)
      && !(is_string($total_pages) && ctype_digit($total_pages))
    ) {
      $this->fail(
        $operation,
        __(
          'Cloudflare returned an invalid page count.',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        )
      );

      return null;
    }

    $total_pages = (int) $total_pages;

    if ($total_pages < 1) {
      $this->fail(
        $operation,
        __(
          'Cloudflare returned an invalid page count.',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        )
      );

      return null;
    }

    return $total_pages;
  }

  /**
   * @param array<string, mixed> $body
   */
  private function cloudflare_error_message(array $body): string {
    $errors = $body['errors'] ?? [];

    if (
      is_array($errors)
      && isset($errors[0])
      && is_array($errors[0])
    ) {
      $code = isset($errors[0]['code'])
        ? sanitize_text_field((string) $errors[0]['code'])
        : '';

      $message = isset($errors[0]['message'])
        ? sanitize_text_field((string) $errors[0]['message'])
        : '';

      if ($code !== '' && $message !== '') {
        return sprintf(
          'Cloudflare error %s: %s',
          $code,
          $message
        );
      }

      if ($message !== '') {
        return $message;
      }
    }

    if (
      isset($body['message'])
      && is_scalar($body['message'])
    ) {
      $message = sanitize_text_field(
        (string) $body['message']
      );

      if ($message !== '') {
        return $message;
      }
    }

    return __(
      'Cloudflare returned an unsuccessful response.',
      'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
    );
  }

  /**
   * @param mixed $response WordPress HTTP API response.
   */
  private static function response_http_code(
    $response
  ): ?int {
    if (is_wp_error($response)) {
      return null;
    }

    $code = wp_remote_retrieve_response_code($response);

    return $code > 0 ? $code : null;
  }

  private function response_succeeded(
    string $operation,
    $response,
    ?array $expected_codes = null,
    ?array &$decoded_body = null
  ): bool {
    $decoded_body = null;
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
      $decoded_body = $this->decode_json_response(
        $operation,
        $response
      );

      if ($decoded_body === null) {
        return false;
      }

      $this->clear_last_error();

      return true;
    }

    $body = $this->decode_json_response(
      $operation,
      $response
    );

    $message = $body !== null
      ? $this->cloudflare_error_message($body)
      : $this->get_last_error_message();

    if ($message === '') {
      $response_message = wp_remote_retrieve_response_message(
        $response
      );

      $message = $response_message !== ''
        ? $response_message
        : __(
          'Cloudflare returned an unsuccessful response.',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
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

  /**
   * Build the common WordPress HTTP API arguments for Cloudflare.
   *
   * @param array<string, mixed> $overrides
   * @return array<string, mixed>
   */
  private function get_request_args(
    bool $with_content_type = false,
    array $overrides = []
  ): array {
    return array_merge(
      [
        'headers' => $this->get_headers($with_content_type),
        'timeout' => self::HTTP_TIMEOUT_SECONDS,
        'redirection' => self::HTTP_REDIRECTION_LIMIT,
        'reject_unsafe_urls' => true,
        'sslverify' => true,
      ],
      $overrides
    );
  }

  private static function normalize_comment(string $comment): string {
    $comment = preg_replace(
      '/[\x00-\x1F\x7F]+/u',
      ' ',
      $comment
    );

    if (!is_string($comment)) {
      return '';
    }

    $comment = preg_replace('/\s+/u', ' ', $comment);

    if (!is_string($comment)) {
      return '';
    }

    $comment = trim($comment);

    if (function_exists('mb_substr')) {
      return mb_substr(
        $comment,
        0,
        self::MAX_COMMENT_LENGTH,
        'UTF-8'
      );
    }

    return substr($comment, 0, self::MAX_COMMENT_LENGTH);
  }

  public function get_current_blocked_ips(): array {
    if (
      !CloudflareIdentifierValidator::validate_zone_id(
        $this->zone
      )
    ) {
      $this->fail(
        'read_zone_access_rules',
        __(
          'Cloudflare Zone ID must contain exactly 32 hexadecimal characters.',
          'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
        )
      );

      return [];
    }

    $ip_list = [];
    $page = 1;

    do {
      $url = $this->apiBase
        . "/zones/{$this->zone}/firewall/access_rules/rules"
        . "?mode=block&page={$page}&per_page=50";

      $response = wp_remote_get(
        $url,
        $this->get_request_args()
      );

      $body = null;

      if (
        !$this->response_succeeded(
          'read_zone_access_rules',
          $response,
          [200],
          $body
        )
      ) {
        return [];
      }

      $result = $this->response_result_array(
        'read_zone_access_rules',
        $body
      );

      if ($result === null) {
        return [];
      }

      foreach ($result as $rule) {
        if (!is_array($rule)) {
          $this->fail(
            'read_zone_access_rules',
            __(
              'Cloudflare returned an invalid access-rule item.',
              'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'
            )
          );

          return [];
        }

        $configuration = $rule['configuration'] ?? null;

        if (
          !is_array($configuration)
          || (string) ($configuration['target'] ?? '') !== 'ip'
        ) {
          continue;
        }

        $ip = (string) ($configuration['value'] ?? '');

        if (IpValidator::validate_public_ip($ip)) {
          $ip_list[$ip] = true;
        }
      }

      $total_pages = $this->response_total_pages(
        'read_zone_access_rules',
        $body
      );

      if ($total_pages === null) {
        return [];
      }

      $page++;
    } while ($page <= $total_pages);

    $this->clear_last_error();

    return array_keys($ip_list);
  }

  public function batch_block(array $ips): array {
    $failed = [];

    foreach ($ips as $entry) {
      $ip = $entry['ip'] ?? '';

      if (!IpValidator::validate_public_ip($ip)) {
        $failed[] = $ip;
        continue;
      }

      $notes = __('Wordfence Sync', 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare') . ': ' . ($entry['reason'] ?? __('Unknown', 'grey-rock-block-synchroniser-for-wordfence-and-cloudflare'));

      if (!$this->create_block($ip, $notes)) {
        $failed[] = $ip;
      }
    }

    return $failed;
  }
}
