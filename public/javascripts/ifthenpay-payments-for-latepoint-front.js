class LatepointPaymentsIfthenpayFront {
	static CLS = {
		overlay: 'ifp-overlay',
		container: 'ifp-container',
		header: 'ifp-header',
		warningText: 'ifp-warning-text',
		closeButton: 'ifp-close',
		iframe: 'ifp-iframe',
		spinnerOverlay: 'ifp-spinner-overlay',
	};

	// ~45s total, matching the previous server-side blocking retry budget — SIBS (MBWAY) in
	// particular can take a while to confirm a payment back to ifthenpay.
	static POLL_INTERVAL_MS = 3000;
	static POLL_MAX_ATTEMPTS = 15;

	constructor() {
		jQuery(() => this.init());
	}

	init() {
		jQuery('body').on(
			'latepoint:initPaymentMethod',
			`.latepoint-booking-form-element`,
			(e, data) => {
				if (data.payment_method !== 'ifthenpay_gateway') {
					return;
				}
				latepoint_add_action(data.callbacks_list, async () =>
					this.openModal('order', jQuery(e.currentTarget))
				);
			}
		);
		jQuery('body').on(
			'latepoint:initOrderPaymentMethod',
			`.latepoint-transaction-payment-form`,
			(e, data) => {
				if (data.payment_method !== 'ifthenpay_gateway') {
					return;
				}
				const c = jQuery(e.currentTarget);
				c.find('.latepoint-lightbox-footer').hide();
				latepoint_add_action(data.callbacks_list, async () =>
					this.openModal('transaction', c)
				);
			}
		);
	}

	async request(route, params) {
		try {
			return await jQuery.post(
				latepoint_timestamped_ajaxurl(),
				{
					action: 'latepoint_route_call',
					route_name: route,
					layout: 'none',
					return_format: 'json',
					params: new URLSearchParams(params).toString(),
				},
				null,
				'json'
			);
		} catch {
			return {
				status: 'error',
				message: latepoint_helper.ifthenpay_translations.request_failed,
			};
		}
	}

	toggleScroll(enable) {
		jQuery('body').css('overflow', enable ? '' : 'hidden');
	}

	async openModal(type, container) {
		const isOrder = type === 'order';
		const $form = isOrder ? container.find('.latepoint-form') : container;
		const route = isOrder
			? latepoint_helper.ifthenpay_order_payment_options_route
			: latepoint_helper.ifthenpay_transaction_payment_options_route;

		const opts = await this.request(route, new FormData($form[0]));
		if (opts.skip_payment) {
			return isOrder
				? latepoint_submit_booking_form($form)
				: $form.trigger('submit');
		}

		if (opts.status !== 'success' || !opts.paybylink_url) {
			return isOrder
				? latepoint_show_error_and_stop_loading_booking_form(
						opts,
						$form
					)
				: latepoint_show_message_inside_element(
						opts.message,
						$form.find('.clean-layout-content-body')
					);
		}

		this.toggleScroll(false);
		const html = `
      <div class="${LatepointPaymentsIfthenpayFront.CLS.overlay}">
        <div class="${LatepointPaymentsIfthenpayFront.CLS.container}">
          <div class="${LatepointPaymentsIfthenpayFront.CLS.header}">
            <div class="${LatepointPaymentsIfthenpayFront.CLS.warningText}">${latepoint_helper.ifthenpay_translations.warning}</div>
            <button class="${LatepointPaymentsIfthenpayFront.CLS.closeButton}" aria-label="Close">×</button>
          </div>
          <iframe class="${LatepointPaymentsIfthenpayFront.CLS.iframe}" src="${opts.paybylink_url}" allow="payment *"></iframe>
        </div>
      </div>`;
		const $modal = jQuery(html).appendTo('body');
		const $iframe = $modal.find(
			`.${LatepointPaymentsIfthenpayFront.CLS.iframe}`
		);

		// A cross-origin iframe throws reading contentWindow.location until PBL redirects back to
		// this site's own return URL.
		$iframe.on('load', () => {
			let href;
			try {
				href = $iframe[0].contentWindow.location.href;
			} catch {
				return;
			}
			const params = new URL(href).searchParams;
			if (params.has('ifthenpay_return') && params.has('txid')) {
				this.verify(
					type,
					$form,
					opts.token,
					params.get('ifthenpay_return'),
					params.get('txid'),
					$modal
				);
			}
		});

		$modal
			.find(`.${LatepointPaymentsIfthenpayFront.CLS.closeButton}`)
			.one('click', () => {
				$iframe.off('load');
				this.verify(type, $form, opts.token, 'cancel', '', $modal);
			});
	}

	// Our own endpoint reads our own stored payment state; it only falls back to asking ifthenpay
	// directly when that state isn't settled yet. A `pending: true` response means neither has
	// resolved this yet, so we ask again rather than treat it as a failure.
	async pollPaymentStatus(token, status, txid) {
		for (
			let attempt = 0;
			attempt < LatepointPaymentsIfthenpayFront.POLL_MAX_ATTEMPTS;
			attempt++
		) {
			const resp = await this.request(
				latepoint_helper.ifthenpay_check_status_route,
				{
					payment_token: token,
					ifthenpay_return: status,
					txid,
				}
			);

			if (!resp.pending) {
				return resp;
			}

			await new Promise((resolve) =>
				setTimeout(
					resolve,
					LatepointPaymentsIfthenpayFront.POLL_INTERVAL_MS
				)
			);
		}

		return {
			status: 'error',
			message:
				latepoint_helper.ifthenpay_translations.verification_timeout,
		};
	}

	async verify(type, $form, token, status, txid, $modal) {
		const $container = $modal.find(
			`.${LatepointPaymentsIfthenpayFront.CLS.container}`
		);
		const $spin = jQuery(
			`<div class="${LatepointPaymentsIfthenpayFront.CLS.spinnerOverlay}"></div>`
		).appendTo($container);

		const resp = await this.pollPaymentStatus(token, status, txid);

		$spin.remove();
		$modal.remove();
		this.toggleScroll(true);

		if (resp.status === 'success') {
			if (type === 'order') {
				$form.find('[name="cart[payment_token]"]').val(token);
				latepoint_submit_booking_form($form);
			} else {
				$form.find('[name="payment_token"]').val(token);
				$form.trigger('submit');
			}
		} else if (type === 'order') {
			latepoint_show_error_and_stop_loading_booking_form(resp, $form);
		} else {
			latepoint_show_message_inside_element(
				resp.message,
				$form.find('.clean-layout-content-body')
			);
		}
	}
}

jQuery(() => new LatepointPaymentsIfthenpayFront());
