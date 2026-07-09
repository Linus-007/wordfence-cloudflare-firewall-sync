<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Cloudflare;

use WP_Error;
use WP_Http;
use WPCF\FirewallSync\Plugin;

final class Client {
  private string $token;
  private string $zone;
  private string $apiBase = 'https://api.cloudflare.com/client/v4';

  public function __construct(string $token, string $zone) {
    $this->token = $token;
    $this->zone = $zone;
  }

  public function validate(): bool {
    $url = $this->apiBase . "/zones/{$this->zone}";
    $response = wp_remote_get($url, $this->get_request_args());

    if (is_wp_error($response)) {
      return false;
    }

    $code = wp_remote_retrieve_response_code($response);

    return $code === 200;
  }

  public function validate_account_list(string $account_id, string $list_id): bool {
    if ($account_id === '' || $list_id === '') {
      return false;
    }

    $url = $this->apiBase . "/accounts/{$account_id}/rules/lists/{$list_id}";
    $response = wp_remote_get($url, $this->get_request_args());

    if (is_wp_error($response)) {
      return false;
    }

    return wp_remote_retrieve_response_code($response) === 200;
  }

  public function add_ip_to_account_list(string $account_id, string $list_id, string $ip, string $comment = ''): bool {
    if ($account_id === '' || $list_id === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
      return false;
    }

    $url = $this->apiBase . "/accounts/{$account_id}/rules/lists/{$list_id}/items";

    $item = [
      'ip' => $ip,
    ];

    if ($comment !== '') {
      $item['comment'] = $comment;
    }

    $response = wp_remote_post($url, [
      'headers' => $this->get_headers(true),
      'body' => wp_json_encode([$item]),
    ]);

    if (is_wp_error($response)) {
      return false;
    }

    $code = wp_remote_retrieve_response_code($response);

    return $code >= 200 && $code < 300;
  }

  public function find_account_list_item_id_by_ip(string $account_id, string $list_id, string $ip): ?string {
    if ($account_id === '' || $list_id === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
      return null;
    }

    $page = 1;

    do {
      $url = $this->apiBase . "/accounts/{$account_id}/rules/lists/{$list_id}/items?page={$page}&per_page=50";
      $response = wp_remote_get($url, $this->get_request_args());

      if (is_wp_error($response)) {
        return null;
      }

      if (wp_remote_retrieve_response_code($response) !== 200) {
        return null;
      }

      $body = json_decode(wp_remote_retrieve_body($response), true);

      if (!is_array($body) || !isset($body['result']) || !is_array($body['result'])) {
        return null;
      }

      $items = $body['result'];

      foreach ($items as $item) {
        if (($item['ip'] ?? '') === $ip) {
          return $item['id'] ?? null;
        }
      }

      $has_more = ($body['result_info']['total_pages'] ?? 1) > $page;
      $page++;

    } while ($has_more);

    return null;
  }

  public function remove_ip_from_account_list(string $account_id, string $list_id, string $ip): bool {
    $item_id = $this->find_account_list_item_id_by_ip($account_id, $list_id, $ip);

    if (!$item_id) {
      return false;
    }

    $url = $this->apiBase . "/accounts/{$account_id}/rules/lists/{$list_id}/items/{$item_id}";
    $response = wp_remote_request($url, [
      'method' => 'DELETE',
      'headers' => $this->get_headers(true),
    ]);

    if (is_wp_error($response)) {
      return false;
    }

    $code = wp_remote_retrieve_response_code($response);

    return $code >= 200 && $code < 300;
  }

  public function get_current_account_list_ips(string $account_id, string $list_id): array {
    if ($account_id === '' || $list_id === '') {
      return [];
    }

    $ip_list = [];
    $page = 1;

    do {
      $url = $this->apiBase . "/accounts/{$account_id}/rules/lists/{$list_id}/items?page={$page}&per_page=50";
      $response = wp_remote_get($url, $this->get_request_args());

      if (is_wp_error($response)) {
        break;
      }

      if (wp_remote_retrieve_response_code($response) !== 200) {
        break;
      }

      $body = json_decode(wp_remote_retrieve_body($response), true);

      if (!is_array($body) || !isset($body['result']) || !is_array($body['result'])) {
        break;
      }

      $items = $body['result'];

      foreach ($items as $item) {
        if (!empty($item['ip'])) {
          $ip_list[] = $item['ip'];
        }
      }

      $has_more = ($body['result_info']['total_pages'] ?? 1) > $page;
      $page += 1;
    } while ($has_more);

    return array_unique($ip_list);
  }

  public function create_block(string $ip): bool {
    $url = $this->apiBase . "/zones/{$this->zone}/firewall/access_rules/rules";

    $data = [
      'mode' => 'block',
      'configuration' => [
        'target' => 'ip',
        'value' => $ip,
      ],
      'notes' => __('Wordfence Sync Block', Plugin::get_text_domain()),
    ];

    $response = wp_remote_post($url, [
      'headers' => $this->get_headers(true),
      'body' => json_encode($data),
    ]);

    return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
  }

  public function delete_block(string $ip): bool {
    $list_url = $this->apiBase . "/zones/{$this->zone}/firewall/access_rules/rules?mode=block&configuration.target=ip&configuration.value={$ip}";
    $list = wp_remote_get($list_url, $this->get_request_args());

    if (is_wp_error($list)) {
      return false;
    }

    $body = json_decode(wp_remote_retrieve_body($list), true);
    $rule_id = $body['result'][0]['id'] ?? null;

    if (!$rule_id) {
      return false;
    }

    $delete_url = $this->apiBase . "/zones/{$this->zone}/firewall/access_rules/rules/{$rule_id}";
    $response = wp_remote_request($delete_url, [
      'method' => 'DELETE',
      'headers' => $this->get_headers(true),
    ]);

    return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
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
    $max_batch = 1000;
    $chunks = array_chunk($ips, $max_batch);
    $failed = [];

    foreach ($chunks as $chunk) {
      $rules = [];

      foreach ($chunk as $entry) {
        $rules[] = [
          'mode' => 'block',
          'configuration' => [
            'target' => 'ip',
            'value' => $entry['ip']
          ],
          'notes' => __('Wordfence Sync', Plugin::get_text_domain()) . ': ' . ($entry['reason'] ?? __('Unknown', Plugin::get_text_domain()))
        ];
      }

      $url = $this->apiBase . "/zones/{$this->zone}/firewall/access_rules/rules";

      $response = wp_remote_post($url, [
        'headers' => $this->get_headers(true),
        'body' => wp_json_encode(['rules' => $rules])
      ]);

      if (is_wp_error($response)) {
        error_log('Cloudflare batch block failed: ' . $response->get_error_message());

        foreach ($chunk as $entry) {
          $failed[] = $entry['ip'];
        }

        continue;
      }

      $body = json_decode(wp_remote_retrieve_body($response), true);

      if (!isset($body['result']) || !is_array($body['result'])) {
        error_log('Cloudflare batch block: Unexpected response format');
        
        foreach ($chunk as $entry) {
          $failed[] = $entry['ip'];
        }

        continue;
      }

      foreach ($body['result'] as $index => $result) {
        if (!empty($result['errors'])) {
          $failed[] = $chunk[$index]['ip'];
          $error_messages = array_column($result['errors'], 'message');
          error_log("Cloudflare error blocking IP {$chunk[$index]['ip']}: " . implode('; ', $error_messages));
        }
      }
    }
  
    return $failed;
  }
}
