<?php
if (! defined('ABSPATH')) {
   exit;
}

/**
 * Repository class to manage ifthenpay payment records
 * in the custom database table `wp_ifthenpay_payments`.
 */
class IfthenpayPaymentRepository
{

   /**
    * Cache group for payment lookups.
    */
   private const CACHE_GROUP = 'ifthenpay_payments';

   /**
    * Returns the fully qualified table name.
    *
    * @return string
    */
   private static function table_name(): string
   {
      global $wpdb;
      return $wpdb->prefix . 'ifthenpay_payments';
   }

   /**
    * Creates or updates the custom payments table via dbDelta.
    */
   public static function create_table(): void
   {
      global $wpdb;
      $table = self::table_name();
      $collate = $wpdb->get_charset_collate();
      require_once ABSPATH . 'wp-admin/includes/upgrade.php';

      $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            token VARCHAR(255) NOT NULL,
            intent_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
            paybylink_url VARCHAR(255) DEFAULT NULL,
            transaction_id VARCHAR(255) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY token (token)
        ) {$collate};";

      dbDelta($sql);
   }

   /**
    * Inserts a new payment with 'PENDING' status.
    */
   public static function create(string $token, int $intent_id, string $paybylink_url): bool
   {
      global $wpdb;
      return (bool) $wpdb->insert(
         self::table_name(),
         compact('token', 'intent_id', 'paybylink_url') + ['status' => 'PENDING'],
         ['%s', '%d', '%s', '%s']
      );
   }

   /**
    * Updates a single column by token and clears cache.
    */
   private static function update_field(string $token, string $column, string $value): bool
   {
      global $wpdb;
      $data    = [$column => $value];
      $where   = ['token' => $token];
      $formats = ['%s'];
      $result  = (bool) $wpdb->update(self::table_name(), $data, $where, $formats, $formats);

      if ($result) {
         wp_cache_delete("token_{$token}", self::CACHE_GROUP);
         if ('transaction_id' === $column) {
            wp_cache_delete("txid_{$value}", self::CACHE_GROUP);
         }
      }

      return $result;
   }

   /**
    * Updates the payment status.
    */
   public static function update_status(string $token, string $status): bool
   {
      return self::update_field($token, 'status', $status);
   }

   /**
    * Updates the payment transaction ID.
    */
   public static function update_transaction_id(string $token, string $transaction_id): bool
   {
      return self::update_field($token, 'transaction_id', $transaction_id);
   }

   /**
    * Generic fetch by column name with caching.
    */
   private static function get_by_column(string $column, string $value, string $cache_key): ?object
   {
      global $wpdb;
      if (false !== ($cached = wp_cache_get($cache_key, self::CACHE_GROUP))) {
         return $cached;
      }

      $table = esc_sql(self::table_name());
      $where = $wpdb->prepare("`{$column}` = %s", $value);
      $row   = $wpdb->get_row("SELECT * FROM `{$table}` WHERE {$where}");

      if ($row) {
         wp_cache_set($cache_key, $row, self::CACHE_GROUP);
      }

      return $row ?: null;
   }

   /**
    * Retrieves a payment record by token.
    */
   public static function get_by_token(string $token): ?object
   {
      return self::get_by_column('token', $token, "token_{$token}");
   }

   /**
    * Retrieves a payment record by transaction ID.
    */
   public static function get_by_transaction_id(string $txid): ?object
   {
      return self::get_by_column('transaction_id', $txid, "txid_{$txid}");
   }

   /**
    * Deletes a payment by token and clears cache.
    */
   public static function delete_by_token(string $token): bool
   {
      global $wpdb;
      $deleted = (bool) $wpdb->delete(
         self::table_name(),
         ['token' => $token],
         ['%s']
      );
      if ($deleted) {
         wp_cache_delete("token_{$token}", self::CACHE_GROUP);
      }
      return $deleted;
   }

   /**
    * Checks if the custom table exists, with caching.
    */
   public static function table_exists(): bool
   {
      global $wpdb;
      $cache_key = 'table_exists_' . self::table_name();
      if (false !== ($cached = wp_cache_get($cache_key, self::CACHE_GROUP))) {
         return (bool) $cached;
      }

      $table  = esc_sql(self::table_name());
      $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === self::table_name();
      wp_cache_set($cache_key, $exists, self::CACHE_GROUP);

      return $exists;
   }
}
