<?php
/**
 * Proves the `latepoint_model_validate` hook actually intercepts a real OsSettingsModel::save() —
 * the wiring that BackofficeKeyValidationTest.php's and MultibancoValidityValidationTest.php's own
 * pure unit tests can't reach on their own. Uses only cases that never call the network (malformed
 * format, empty, an unrelated setting), so nothing here needs HTTP mocking inside a real
 * WordPress boot.
 *
 * @package ifthenpay-payments-for-latepoint
 */

/**
 * Save-blocking hook wiring proof.
 */
class BackofficeKeyValidationHookTest extends WP_UnitTestCase {

	/**
	 * A malformed key aborts the real save — the hook's add_error() call makes
	 * OsModel::validate() return false, which OsModel::save() checks before persisting.
	 */
	public function test_malformed_key_blocks_the_real_save(): void {
		$model        = new OsSettingsModel();
		$model->name  = 'ifthenpay_backoffice_key';
		$model->value = OsEncryptHelper::encrypt_value( 'not-a-key' );

		$this->assertFalse( $model->save() );
	}

	/**
	 * An empty key (the field cleared) allows the real save to proceed.
	 */
	public function test_empty_key_allows_the_real_save(): void {
		$model        = new OsSettingsModel();
		$model->name  = 'ifthenpay_backoffice_key';
		$model->value = OsEncryptHelper::encrypt_value( '' );

		$this->assertTrue( $model->save() );
	}

	/**
	 * Saving an unrelated setting is untouched — the hook must filter tightly, not intercept
	 * every OsSettingsModel save.
	 */
	public function test_unrelated_setting_save_is_not_intercepted(): void {
		$model        = new OsSettingsModel();
		$model->name  = 'ifthenpay_lp_test_unrelated_setting';
		$model->value = 'anything';

		$this->assertTrue( $model->save() );
	}

	/**
	 * An out-of-range Reference Validity aborts the real save — the same hook wiring as the
	 * Backoffice Key, proven separately since it's a different setting name and needs no
	 * decryption.
	 */
	public function test_out_of_range_multibanco_validity_blocks_the_real_save(): void {
		$model        = new OsSettingsModel();
		$model->name  = 'ifthenpay_multibanco_validity_days';
		$model->value = '9999';

		$this->assertFalse( $model->save() );
	}

	/**
	 * An empty Reference Validity (the field cleared) allows the real save to proceed.
	 */
	public function test_empty_multibanco_validity_allows_the_real_save(): void {
		$model        = new OsSettingsModel();
		$model->name  = 'ifthenpay_multibanco_validity_days';
		$model->value = '';

		$this->assertTrue( $model->save() );
	}

	/**
	 * An in-range Reference Validity allows the real save to proceed.
	 */
	public function test_in_range_multibanco_validity_allows_the_real_save(): void {
		$model        = new OsSettingsModel();
		$model->name  = 'ifthenpay_multibanco_validity_days';
		$model->value = '5';

		$this->assertTrue( $model->save() );
	}
}
