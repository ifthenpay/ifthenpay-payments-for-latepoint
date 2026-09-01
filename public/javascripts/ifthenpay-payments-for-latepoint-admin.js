class LatepointPaymentsIfthenpayAdmin {
	// Selectors
	static SELECTORS = {
		gatewaySelect: '.ifthenpay-gateway-select',
		validateButton: '.validate-button',
		backofficeKeyInput: '.custom-backoffice-key',
		methodItem: '.ifthenpay-method-item',
		methodCheckbox: '.ifthenpay-method-checkbox',
		methodAccount: '.ifthenpay-method-account',
		defaultMethodSelect: '.ifthenpay-default-method',
		methodsConfigInput: '#ifthenpay_payment_methods_configuration',
		methodsWrapper: '.ifthenpay-method-right',
		activateLink: '.ifthenpay-activate',
	};

	constructor() {
		this.initEvents();
	}

	initEvents() {
		const doc = jQuery(document);

		doc.on(
			'click',
			LatepointPaymentsIfthenpayAdmin.SELECTORS.validateButton,
			(e) => this.handleKeyValidation(e)
		)
			.on(
				'change',
				LatepointPaymentsIfthenpayAdmin.SELECTORS.gatewaySelect,
				(e) => this.onGatewayChange(e)
			)
			.on(
				'click',
				LatepointPaymentsIfthenpayAdmin.SELECTORS.methodItem,
				(e) => this.toggleMethod(e)
			)
			.on(
				'change',
				LatepointPaymentsIfthenpayAdmin.SELECTORS.methodCheckbox,
				() => {
					this.updateDefaultMethods();
					this.updateConfig();
				}
			)
			.on(
				'change',
				LatepointPaymentsIfthenpayAdmin.SELECTORS.defaultMethodSelect,
				() => this.updateConfig()
			)
			.on(
				'click',
				LatepointPaymentsIfthenpayAdmin.SELECTORS.activateLink,
				(e) => this.handleActivate(e)
			);
	}

	serialize(params) {
		return new URLSearchParams(params).toString();
	}

	handleKeyValidation(event) {
		event.preventDefault();

		const $btn = jQuery(event.currentTarget).prop('disabled', true);
		const $keyInput = jQuery(
			LatepointPaymentsIfthenpayAdmin.SELECTORS.backofficeKeyInput
		);
		const backofficeKey = $keyInput.val().trim();
		const $section = $keyInput.closest('.sub-section-row');

		$btn.find('.label-connect').hide();
		$btn.find('.label-connecting').show();

		const payload = {
			action: 'latepoint_route_call',
			route_name: latepoint_helper.ifthenpay_validate_key_route,
			layout: 'none',
			return_format: 'json',
			params: this.serialize({
				backoffice_key: backofficeKey,
			}),
		};

		jQuery
			.post(
				latepoint_timestamped_ajaxurl(),
				payload,
				(res) => {
					$section.nextAll('.sub-section-row').remove();
					if (res.status === 'success') {
						// This preview's own dataset — not necessarily what was localized at
						// page load, since the key being previewed here has not been saved yet.
						latepoint_helper.ifthenpay_accounts = res.inline_data.accounts;
						$section.after(res.html);
					} else {
						alert(res.message || 'Validation failed.');
					}
				},
				'json'
			)
			.fail(() => alert('Server error.'))
			.always(() => {
				$btn.prop('disabled', false);
				$btn.find('.label-connecting').hide();
				$btn.find('.label-connect').show();
			});
	}

	// Every gateway's accounts are already in `latepoint_helper.ifthenpay_accounts` (localized in
	// full, or refreshed by handleKeyValidation() above) — no AJAX round trip per gateway change.
	onGatewayChange(event) {
		const gatewayKey = jQuery(event.target).val();
		const accounts =
			(latepoint_helper.ifthenpay_accounts || {})[gatewayKey] || {};
		this.applyAccountsForGateway(accounts);
	}

	// A gateway record has at most one account per method (no list to choose from), so a method
	// is either available — its account key travels in a hidden field — or it isn't.
	applyAccountsForGateway(accounts) {
		jQuery(LatepointPaymentsIfthenpayAdmin.SELECTORS.methodItem).each(
			function () {
				const $item = jQuery(this);
				const entity = $item.data('entity');
				const accountKey = accounts[entity];
				const $wrapper = $item.find(
					LatepointPaymentsIfthenpayAdmin.SELECTORS.methodsWrapper
				);
				const $checkbox = $item.find(
					LatepointPaymentsIfthenpayAdmin.SELECTORS.methodCheckbox
				);

				$wrapper.empty();

				if (!accountKey) {
					$checkbox.prop('checked', false).prop('disabled', true);
					$wrapper.html(
						`<div class="ifthenpay-no-accounts">
               ${latepoint_helper.ifthenpay_translations.no_accounts}
               <a href="#"
                  class="ifthenpay-activate"
                  data-entity="${entity}">
                 ${latepoint_helper.ifthenpay_translations.activate}
               </a>.
             </div>`
					);
					return;
				}

				$checkbox.prop('disabled', false);
				$wrapper.append(
					jQuery('<input>', {
						type: 'hidden',
						class: 'ifthenpay-method-account',
						name: `settings[ifthenpay_payment_methods_configuration][${entity}][selected_account]`,
						value: accountKey,
					})
				);
			}
		);

		this.updateDefaultMethods();
		this.updateConfig();
	}

	toggleMethod(event) {
		const $target = jQuery(event.target);
		if (
			$target.is(
				LatepointPaymentsIfthenpayAdmin.SELECTORS.methodCheckbox
			) ||
			$target.closest(
				LatepointPaymentsIfthenpayAdmin.SELECTORS.activateLink
			).length
		) {
			return;
		}

		const $item = jQuery(event.currentTarget);
		const $checkbox = $item.find(
			LatepointPaymentsIfthenpayAdmin.SELECTORS.methodCheckbox
		);
		if ($checkbox.prop('disabled')) {
			return;
		}

		$checkbox.prop('checked', !$checkbox.prop('checked')).trigger('change');
	}

	updateDefaultMethods() {
		const $defaultSelect = jQuery(
			LatepointPaymentsIfthenpayAdmin.SELECTORS.defaultMethodSelect
		);
		if (!$defaultSelect.length) {
			return;
		}

		const selectedDefault =
			$defaultSelect.data('selected') || $defaultSelect.val();
		$defaultSelect.empty();

		const enabledEntities = [];
		jQuery(
			LatepointPaymentsIfthenpayAdmin.SELECTORS.methodCheckbox +
				':checked'
		).each(function () {
			const entity = jQuery(this)
				.closest(LatepointPaymentsIfthenpayAdmin.SELECTORS.methodItem)
				.data('entity');
			if (entity) {
				enabledEntities.push(entity);
			}
		});

		if (!enabledEntities.length) {
			$defaultSelect.append(
				jQuery('<option>', {
					value: '',
					text: latepoint_helper.ifthenpay_translations
						.warning_default_method,
					disabled: true,
					selected: true,
				})
			);
		} else {
			enabledEntities.forEach((entity) => {
				$defaultSelect.append(
					jQuery('<option>', {
						value: entity,
						text: entity,
						selected: entity === selectedDefault,
					})
				);
			});
		}
	}

	updateConfig() {
		const config = {};

		jQuery(LatepointPaymentsIfthenpayAdmin.SELECTORS.methodItem).each(
			function () {
				const $item = jQuery(this);
				const entity = $item.data('entity');
				const $checkbox = $item.find(
					LatepointPaymentsIfthenpayAdmin.SELECTORS.methodCheckbox
				);
				const $account = $item.find(
					LatepointPaymentsIfthenpayAdmin.SELECTORS.methodAccount
				);

				config[entity] = {
					checked: $checkbox.is(':checked'),
					selected_account: $account.val() || '',
				};
			}
		);

		jQuery(
			LatepointPaymentsIfthenpayAdmin.SELECTORS.methodsConfigInput
		).val(JSON.stringify(config));
	}

	handleActivate(event) {
		event.preventDefault();
		const $link = jQuery(event.currentTarget);
		const entity = $link.data('entity');
		const gatewayKey = jQuery(
			LatepointPaymentsIfthenpayAdmin.SELECTORS.gatewaySelect
		).val();

		// build your AJAX-mail payload
		const payload = {
			action: 'latepoint_route_call',
			route_name: latepoint_helper.ifthenpay_activate_account_route,
			layout: 'none',
			return_format: 'json',
			params: this.serialize({
				gateway_key: gatewayKey,
				entity,
			}),
		};

		jQuery
			.post(
				latepoint_timestamped_ajaxurl(),
				payload,
				(res) => {
					if (res.status === 'success') {
						alert(
							res.message ||
								'Your activation request has been sent to support.'
						);
					} else {
						alert(
							res.message || 'Failed to send activation request.'
						);
					}
				},
				'json'
			)
			.fail(() => {
				alert('Server error sending activation request.');
			});
	}
}

jQuery(() => new LatepointPaymentsIfthenpayAdmin());
