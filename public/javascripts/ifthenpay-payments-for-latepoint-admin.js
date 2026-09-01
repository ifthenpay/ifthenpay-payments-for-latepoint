class LatepointPaymentsIfthenpayAdmin {
	static SELECTORS = {
		gatewaySelect: '.ifthenpay-gateway-select',
		validateButton: '.validate-button',
		backofficeKeyInput: '.custom-backoffice-key',
		methodItem: '.ifthenpay-method-item',
		methodCheckbox: '.ifthenpay-method-item input[type="checkbox"]',
		defaultMethodSelect: '.ifthenpay-default-method',
		activateLink: '.ifthenpay-activate',
	};

	constructor() {
		this.initEvents();
	}

	initEvents() {
		const S = LatepointPaymentsIfthenpayAdmin.SELECTORS;

		jQuery(document)
			.on('click', S.validateButton, (e) => this.handleKeyValidation(e))
			.on('change', S.gatewaySelect, (e) => this.onGatewayChange(e))
			.on('change', S.methodCheckbox, (e) => this.onMethodCheckboxChange(e))
			.on('click', S.activateLink, (e) => this.handleActivate(e));
	}

	serialize(params) {
		return new URLSearchParams(params).toString();
	}

	// LatePoint's own toast notification system, used everywhere else in its admin — replaces a
	// plain alert() with the same `.os-notifications` UI a merchant already sees for every other
	// action on this page.
	notify(message, type = 'success') {
		latepoint_add_notification(message, type);
	}

	postRoute(routeName, params) {
		return jQuery.post(latepoint_timestamped_ajaxurl(), {
			action: 'latepoint_route_call',
			route_name: routeName,
			layout: 'none',
			return_format: 'json',
			params: this.serialize(params),
		});
	}

	// Previews a Backoffice Key without saving it: format-checks and verifies it against
	// ifthenpay, then replaces everything after the Backoffice Key row with the gateway/method
	// configuration that key would produce. The actual save happens through LatePoint's own
	// settings save, triggered separately by the merchant.
	handleKeyValidation(event) {
		event.preventDefault();

		const S = LatepointPaymentsIfthenpayAdmin.SELECTORS;
		const $button = jQuery(event.currentTarget).prop('disabled', true);
		const $keyInput = jQuery(S.backofficeKeyInput);
		const $section = $keyInput.closest('.sub-section-row');

		$button.find('.label-connect').hide();
		$button.find('.label-connecting').show();

		this.postRoute(latepoint_helper.ifthenpay_validate_key_route, {
			backoffice_key: $keyInput.val().trim(),
		})
			.done((res) => {
				$section.nextAll('.sub-section-row').remove();

				if (res.status !== 'success') {
					this.notify(res.message || latepoint_helper.ifthenpay_translations.validation_failed, 'error');
					return;
				}

				// This preview's own dataset, not necessarily what was localized at page
				// load — the key being previewed here hasn't been saved yet.
				latepoint_helper.ifthenpay_accounts = res.inline_data.accounts;
				$section.after(res.html);
			})
			.fail(() => this.notify(latepoint_helper.ifthenpay_translations.server_error, 'error'))
			.always(() => {
				$button.prop('disabled', false);
				$button.find('.label-connecting').hide();
				$button.find('.label-connect').show();
			});
	}

	// Every gateway key's accounts are already available in `latepoint_helper.ifthenpay_accounts`
	// (localized on page load, or refreshed by handleKeyValidation() above), so switching the
	// selected gateway needs no request of its own.
	onGatewayChange(event) {
		const gatewayKey = jQuery(event.target).val();
		const accounts = (latepoint_helper.ifthenpay_accounts || {})[gatewayKey] || {};
		this.applyAccountsToMethodRows(accounts);
	}

	// A gateway key carries at most one account per method, so each row's checkbox is either
	// usable or it isn't — a plain, natively `disabled` checkbox, same as the initial render.
	// The "No accounts." note is always in the markup; CSS shows it only while `is-disabled` is
	// set, so there is no DOM to build here, only its disabled/checked state and the class LatePoint
	// itself uses to highlight a checked row.
	applyAccountsToMethodRows(accounts) {
		const S = LatepointPaymentsIfthenpayAdmin.SELECTORS;

		jQuery(S.methodItem).each((_, row) => {
			const $row = jQuery(row);
			const hasAccount = !!accounts[$row.data('entity')];
			const checkbox = $row.find('input[type="checkbox"]').prop('disabled', !hasAccount).get(0);

			if (!hasAccount) {
				checkbox.checked = false;
			}

			$row.toggleClass('is-disabled', !hasAccount);
			this.syncCheckedClass(checkbox);
		});

		this.updateDefaultMethodOptions();
	}

	// LatePoint's own checkbox component only paints its `is-checked` (bordered, highlighted)
	// state at render time — unlike its toggler switches, a plain `<input type="checkbox">` gets
	// no click handler of its own to keep that in sync, so checking or unchecking one needs this.
	syncCheckedClass(checkbox) {
		jQuery(checkbox).closest('.os-form-checkbox-group').toggleClass('is-checked', checkbox.checked);
	}

	onMethodCheckboxChange(event) {
		this.syncCheckedClass(event.target);
		this.updateDefaultMethodOptions();
	}

	// The Default Method dropdown can only offer methods that are actually checked.
	updateDefaultMethodOptions() {
		const S = LatepointPaymentsIfthenpayAdmin.SELECTORS;
		const $select = jQuery(S.defaultMethodSelect);
		if (!$select.length) {
			return;
		}

		const previouslySelected = $select.data('selected') || $select.val();
		const enabledEntities = jQuery(S.methodCheckbox)
			.filter(':checked')
			.map((_, checkbox) => jQuery(checkbox).closest(S.methodItem).data('entity'))
			.get();

		$select.empty();

		if (!enabledEntities.length) {
			$select.append(jQuery('<option>', { value: '' }));
			return;
		}

		enabledEntities.forEach((entity) => {
			$select.append(
				jQuery('<option>', {
					value: entity,
					text: entity,
					selected: entity === previouslySelected,
				})
			);
		});
	}

	// Emails ifthenpay support asking them to activate a method for the currently selected
	// gateway key — this doesn't change anything locally, so nothing here needs to update the
	// method row itself.
	handleActivate(event) {
		event.preventDefault();
		const entity = jQuery(event.currentTarget).data('entity');
		const gatewayKey = jQuery(LatepointPaymentsIfthenpayAdmin.SELECTORS.gatewaySelect).val();

		this.postRoute(latepoint_helper.ifthenpay_activate_account_route, { gateway_key: gatewayKey, entity })
			.done((res) => {
				const isSuccess = res.status === 'success';
				const fallback = isSuccess ? latepoint_helper.ifthenpay_translations.activation_sent : latepoint_helper.ifthenpay_translations.activation_failed;
				this.notify(res.message || fallback, isSuccess ? 'success' : 'error');
			})
			.fail(() => this.notify(latepoint_helper.ifthenpay_translations.server_error, 'error'));
	}
}

jQuery(() => new LatepointPaymentsIfthenpayAdmin());
