<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Services;

final class IpValidator {
  /**
   * IPv4 ranges that must never be sent to Cloudflare as public attackers.
   *
   * This supplements PHP's FILTER_FLAG_NO_PRIV_RANGE and
   * FILTER_FLAG_NO_RES_RANGE behavior, which does not reject every
   * IANA special-purpose range on every supported PHP version.
   *
   * @var string[]
   */
  private const DENIED_IPV4_RANGES = [
    '0.0.0.0/8',
    '10.0.0.0/8',
    '100.64.0.0/10',
    '127.0.0.0/8',
    '169.254.0.0/16',
    '172.16.0.0/12',
    '192.0.0.0/24',
    '192.0.2.0/24',
    '192.168.0.0/16',
    '198.18.0.0/15',
    '198.51.100.0/24',
    '203.0.113.0/24',
    '224.0.0.0/4',
    '240.0.0.0/4',
  ];

  /**
   * IPv6 ranges that are private, local, documentation, benchmarking,
   * discard-only, multicast or otherwise inappropriate as public attackers.
   *
   * @var string[]
   */
  private const DENIED_IPV6_RANGES = [
    '::/128',
    '::1/128',
    '64:ff9b:1::/48',
    '100::/64',
    '2001:2::/48',
    '2001:10::/28',
    '2001:20::/28',
    '2001:db8::/32',
    'fc00::/7',
    'fe80::/10',
    'ff00::/8',
  ];

  /**
   * Determine whether an address is a publicly routable IPv4 or IPv6 address.
   *
   * Private, reserved, loopback, link-local, documentation, benchmarking,
   * multicast and unspecified ranges are rejected. IPv4-mapped IPv6
   * addresses are evaluated as IPv4 addresses.
   */
  public static function validate_public_ip(string $ip): bool {
    if ($ip === '' || trim($ip) !== $ip) {
      return false;
    }

    if (stripos($ip, '::ffff:') === 0) {
      $mapped_ipv4 = substr($ip, 7);

      return self::validate_ipv4($mapped_ipv4);
    }

    if (
      filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_IPV4
      ) !== false
    ) {
      return self::validate_ipv4($ip);
    }

    if (
      filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_IPV6
      ) !== false
    ) {
      return self::validate_ipv6($ip);
    }

    return false;
  }

  private static function validate_ipv4(string $ip): bool {
    if (
      filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_IPV4
          | FILTER_FLAG_NO_PRIV_RANGE
          | FILTER_FLAG_NO_RES_RANGE
      ) === false
    ) {
      return false;
    }

    return !self::matches_any_cidr(
      $ip,
      self::DENIED_IPV4_RANGES
    );
  }

  private static function validate_ipv6(string $ip): bool {
    if (
      filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_IPV6
          | FILTER_FLAG_NO_PRIV_RANGE
          | FILTER_FLAG_NO_RES_RANGE
      ) === false
    ) {
      return false;
    }

    return !self::matches_any_cidr(
      $ip,
      self::DENIED_IPV6_RANGES
    );
  }

  /**
   * @param string[] $ranges
   */
  private static function matches_any_cidr(
    string $ip,
    array $ranges
  ): bool {
    foreach ($ranges as $range) {
      if (self::matches_cidr($ip, $range)) {
        return true;
      }
    }

    return false;
  }

  private static function matches_cidr(
    string $ip,
    string $cidr
  ): bool {
    [$network, $prefix_string] = explode('/', $cidr, 2);

    $address_binary = inet_pton($ip);
    $network_binary = inet_pton($network);

    if (
      $address_binary === false
      || $network_binary === false
      || strlen($address_binary) !== strlen($network_binary)
    ) {
      return false;
    }

    $prefix = (int) $prefix_string;
    $maximum_prefix = strlen($address_binary) * 8;

    if ($prefix < 0 || $prefix > $maximum_prefix) {
      return false;
    }

    $whole_bytes = intdiv($prefix, 8);
    $remaining_bits = $prefix % 8;

    if (
      $whole_bytes > 0
      && substr($address_binary, 0, $whole_bytes)
        !== substr($network_binary, 0, $whole_bytes)
    ) {
      return false;
    }

    if ($remaining_bits === 0) {
      return true;
    }

    $mask = (0xFF << (8 - $remaining_bits)) & 0xFF;

    return (
      (ord($address_binary[$whole_bytes]) & $mask)
      === (ord($network_binary[$whole_bytes]) & $mask)
    );
  }
}
