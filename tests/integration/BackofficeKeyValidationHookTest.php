<?php
/**
 * Proves the 003 T-09 `latepoint_model_validate` hook actually intercepts a real
 * OsSettingsModel::save() — the wiring that BackofficeKeyValidationTest.php's pure unit tests
 * can't reach on their own. Uses only cases that never call the network (malformed format, empty,
 * an unrelated setting), so nothing here needs HTTP mocking inside a real WordPress boot.
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
}
