<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Admin;

use WP_List_Table;
use WPCF\FirewallSync\Plugin;
use WPCF\FirewallSync\Services\BlockLogger;

if (!class_exists('WP_List_Table')) {
  require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class LogTable extends WP_List_Table {
  private array $items_data;

  public function __construct() {
    parent::__construct([
      'singular' => __('Firewall Block', Plugin::get_text_domain()),
      'plural' => __('Firewall Blocks', Plugin::get_text_domain()),
      'ajax' => false,
    ]);
  }

  public function prepare_items(): void {
    $per_page = 10;
    $current_page = max(1, (int) ($_GET['paged'] ?? 1));
    $total_items = BlockLogger::count();

    $this->items_data = BlockLogger::get_logs($per_page, ($current_page - 1) * $per_page);

    $this->set_pagination_args([
      'total_items' => $total_items,
      'per_page' => $per_page,
      'total_pages' => (int) ceil($total_items / $per_page),
    ]);

    $this->items = $this->items_data;
  }

  public function get_columns(): array {
    return [
      'ip' => __('IP Address', Plugin::get_text_domain()),
      'reason' => __('Reason', Plugin::get_text_domain()),
      'created_at' => __('Created At', Plugin::get_text_domain()),
    ];
  }

  public function column_default($item, $column_name): string {
    return esc_html($item[$column_name]);
  }

  public function no_items(): void {
    echo '<p>' . __('No firewall blocks found.', Plugin::get_text_domain()) . '</p>';
  }
}