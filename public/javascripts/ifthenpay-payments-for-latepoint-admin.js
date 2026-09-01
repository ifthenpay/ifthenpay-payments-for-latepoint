class LatepointPaymentsIfthenpayAdmin {
	static SELECTORS = {
		gatewaySelect: '.ifthenpay-gateway-select',
		validateButton: '.validate-button',
		backofficeKeyInput: '.custom-backoffice-key',
		methodItem: '.ifthenpay-method-item',
		methodToggler: '.ifthenpay-method-item .os-toggler',
		methodAccount: '.ifthenpay-method-account',
		methodBody: '.ifthenpay-method-body',
		defaultMethodSelect: '.ifthenpay-default-method',
		methodsConfigInput: '#ifthenpay_payment_methods_configuration',
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
			// LatePoint's own admin script already makes clicking a `.os-toggler` flip its
			// on/off class and fire this event — this just reacts to that.
			.on('ostoggler:toggle', S.methodToggler, () => {
				this.updateDefaultMethodOptions();
				this.updateMethodsConfig();
			})
			.on('change', S.defaultMethodSelect, () => this.updateMethodsConfig())
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

	// A gateway key carries at most one account per method, so each row is either usable — its
	// account key travels in a hidden field — or it isn't. The toggle switch has no native
	// disabled state, so a method losing its account is force-switched off, not just faded by
	// the `is-disabled` class LatePoint's own CSS already dims.
	applyAccountsToMethodRows(accounts) {
		const S = LatepointPaymentsIfthenpayAdmin.SELECTORS;

		jQuery(S.methodItem).each((_, row) => {
			const $row = jQuery(row);
			const entity = $row.data('entity');
			const accountKey = accounts[entity];

			$row.toggleClass('is-disabled', !accountKey).find(`${S.methodAccount}, ${S.methodBody}`).remove();

			if (!accountKey) {
				this.setTogglerState($row.find('.os-toggler'), false);
				$row.append(
					jQuery('<div>', { class: 'os-togglable-item-body ifthenpay-method-body' }).html(
						`${latepoint_helper.ifthenpay_translations.no_accounts} <a href="#" class="ifthenpay-activate" data-entity="${entity}">${latepoint_helper.ifthenpay_translations.activate}</a>.`
					)
				);
				return;
			}

			$row.append(
				jQuery('<input>', {
					type: 'hidden',
					class: 'ifthenpay-method-account',
					name: `settings[ifthenpay_payment_methods_configuration][${entity}][selected_account]`,
					value: accountKey,
				})
			);
		});

		this.updateDefaultMethodOptions();
		this.updateMethodsConfig();
	}

	// LatePoint's own click handler only flips a toggler's on/off class and its paired hidden
	// input's value on click — there's no API to set that state programmatically, which is
	// needed here when a gateway change disables a method client-side.
	setTogglerState($toggler, isOn) {
		$toggler.toggleClass('on', isOn).toggleClass('off', !isOn);
		const hiddenId = $toggler.data('for');
		if (hiddenId) {
			jQuery('#' + hiddenId).val(isOn ? 'on' : 'off');
		}
	}

	// The Default Method dropdown can only offer methods that are actually switched on.
	updateDefaultMethodOptions() {
		const S = LatepointPaymentsIfthenpayAdmin.SELECTORS;
		const $select = jQuery(S.defaultMethodSelect);
		if (!$select.length) {
			return;
		}

		const previouslySelected = $select.data('selected') || $select.val();
		const enabledEntities = jQuery(S.methodItem)
			.filter((_, row) => jQuery(row).find('.os-toggler').hasClass('on'))
			.map((_, row) => jQuery(row).data('entity'))
			.get();

		$select.empty();

		if (!enabledEntities.length) {
			$select.append(
				jQuery('<option>', {
					value: '',
					text: latepoint_helper.ifthenpay_translations.warning_default_method,
					disabled: true,
					selected: true,
				})
			);
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

	// Serializes every method row's current state into the one hidden field LatePoint's settings
	// save actually reads.
	updateMethodsConfig() {
		const S = LatepointPaymentsIfthenpayAdmin.SELECTORS;
		const config = {};

		jQuery(S.methodItem).each((_, row) => {
			const $row = jQuery(row);
			config[$row.data('entity')] = {
				checked: $row.find('.os-toggler').hasClass('on'),
				selected_account: $row.find(S.methodAccount).val() || '',
			};
		});

		jQuery(S.methodsConfigInput).val(JSON.stringify(config));
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
