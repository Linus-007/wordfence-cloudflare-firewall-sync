<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Services;

final class CloudflareIdentifierValidator {
  private const RESOURCE_ID_PATTERN = '/^[a-f0-9]{32}$/i';
  private const LIST_NAME_PATTERN = '/^[a-z0-9_]{1,50}$/';
  private const API_TOKEN_PATTERN =
    '/\A[\x21-\x7E]{20,255}\z/';

  public static function validate_api_token(
    string $value
  ): bool {
    return preg_match(
      self::API_TOKEN_PATTERN,
      $value
    ) === 1;
  }

  public static function normalize_api_token(
    string $value
  ): string {
    $value = trim($value);

    return self::validate_api_token($value)
      ? $value
      : '';
  }

  public static function validate_zone_id(string $value): bool {
    return self::validate_resource_id($value);
  }

  public static function validate_account_id(string $value): bool {
    return self::validate_resource_id($value);
  }

  public static function validate_list_id(string $value): bool {
    return self::validate_resource_id($value);
  }

  public static function validate_list_name(string $value): bool {
    return preg_match(self::LIST_NAME_PATTERN, $value) === 1;
  }

  public static function normalize_zone_id(string $value): string {
    return self::normalize_resource_id($value);
  }

  public static function normalize_account_id(string $value): string {
    return self::normalize_resource_id($value);
  }

  public static function normalize_list_id(string $value): string {
    return self::normalize_resource_id($value);
  }

  public static function normalize_list_name(string $value): string {
    $value = ltrim(trim($value), '$');

    return self::validate_list_name($value)
      ? $value
      : '';
  }

  private static function validate_resource_id(string $value): bool {
    return preg_match(
      self::RESOURCE_ID_PATTERN,
      $value
    ) === 1;
  }

  private static function normalize_resource_id(
    string $value
  ): string {
    $value = trim($value);

    return self::validate_resource_id($value)
      ? strtolower($value)
      : '';
  }
}
