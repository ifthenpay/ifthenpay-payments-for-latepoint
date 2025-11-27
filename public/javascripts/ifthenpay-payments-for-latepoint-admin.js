class LatepointPaymentsIfthenpayAdmin {
  // Selectors
  static SELECTORS = {
    gatewaySelect: ".ifthenpay-gateway-select",
    validateButton: ".validate-button",
    backofficeKeyInput: ".custom-backoffice-key",
    methodItem: ".ifthenpay-method-item",
    methodCheckbox: ".ifthenpay-method-checkbox",
    methodDropdown: ".ifthenpay-method-dropdown",
    defaultMethodSelect: ".ifthenpay-default-method",
    methodsConfigInput: "#ifthenpay_payment_methods_configuration",
    methodsWrapper: ".ifthenpay-method-right",
    activateLink: ".ifthenpay-activate",
  };

  constructor() {
    this.initEvents();
    this.refreshGatewayOptions();
    this.triggerGatewayChange();
  }

  initEvents() {
    const doc = jQuery(document);

    doc
      .on(
        "click",
        LatepointPaymentsIfthenpayAdmin.SELECTORS.validateButton,
        (e) => this.handleKeyValidation(e)
      )
      .on(
        "change",
        LatepointPaymentsIfthenpayAdmin.SELECTORS.gatewaySelect,
        (e) => this.fetchAccounts(e)
      )
      .on("click", LatepointPaymentsIfthenpayAdmin.SELECTORS.methodItem, (e) =>
        this.toggleMethod(e)
      )
      .on(
        "change",
        LatepointPaymentsIfthenpayAdmin.SELECTORS.methodCheckbox,
        (e) => this.toggleDropdown(e)
      )
      .on(
        "change",
        LatepointPaymentsIfthenpayAdmin.SELECTORS.methodDropdown,
        () => this.updateConfig()
      )
      .on(
        "change",
        LatepointPaymentsIfthenpayAdmin.SELECTORS.defaultMethodSelect,
        () => this.updateConfig()
      )
      .on(
        "click",
        LatepointPaymentsIfthenpayAdmin.SELECTORS.activateLink,
        (e) => this.handleActivate(e)
      );
  }

  serialize(params) {
    return new URLSearchParams(params).toString();
  }

  refreshGatewayOptions() {
    const $select = jQuery(
      LatepointPaymentsIfthenpayAdmin.SELECTORS.gatewaySelect
    );
    const options = latepoint_helper.ifthenpay_gateway_options;
    if (!$select.length || typeof options !== "object") return;

    const selectedKey =
      latepoint_helper.ifthenpay_gateway_selected || $select.data("selected");
    $select.empty();

    for (const [label, key] of Object.entries(options)) {
      $select.append(
        jQuery("<option>", {
          value: key,
          text: label,
          selected: key === selectedKey,
        })
      );
    }
  }

  triggerGatewayChange() {
    const $select = jQuery(
      LatepointPaymentsIfthenpayAdmin.SELECTORS.gatewaySelect
    );
    if ($select.val()) $select.trigger("change");
  }

  handleKeyValidation(event) {
    event.preventDefault();

    const $btn = jQuery(event.currentTarget).prop("disabled", true);
    const $keyInput = jQuery(
      LatepointPaymentsIfthenpayAdmin.SELECTORS.backofficeKeyInput
    );
    const backofficeKey = $keyInput.val().trim();
    const $section = $keyInput.closest(".sub-section-row");

    $btn.find(".label-connect").hide();
    $btn.find(".label-connecting").show();

    const payload = {
      action: "latepoint_route_call",
      route_name: latepoint_helper.ifthenpay_validate_key_route,
      layout: "none",
      return_format: "json",
      params: this.serialize({
        backoffice_key: backofficeKey,
      }),
    };

    jQuery
      .post(
        latepoint_timestamped_ajaxurl(),
        payload,
        (res) => {
          $section.nextAll(".sub-section-row").remove();
          if (res.status === "success") {
            latepoint_helper.ifthenpay_gateway_options =
              res.inline_data.gateway_options;
            latepoint_helper.gateway_selected =
              res.inline_data.gateway_selected;
            $section.after(res.html);
            this.refreshGatewayOptions();
            this.triggerGatewayChange();
          } else {
            alert(res.message || "Validation failed.");
          }
        },
        "json"
      )
      .fail(() => alert("Server error."))
      .always(() => {
        $btn.prop("disabled", false);
        $btn.find(".label-connecting").hide();
        $btn.find(".label-connect").show();
      });
  }

  fetchAccounts(event) {
    const gatewayKey = jQuery(event.target).val();
    if (!gatewayKey) return;

    jQuery(LatepointPaymentsIfthenpayAdmin.SELECTORS.methodsWrapper).html(
      `<select disabled class="ifthenpay-method-dropdown"><option>${latepoint_helper.ifthenpay_translations.loading}</option></select>`
    );

    const payload = {
      action: "latepoint_route_call",
      route_name: latepoint_helper.ifthenpay_get_accounts_route,
      layout: "none",
      return_format: "json",
      params: this.serialize({
        gateway_key: gatewayKey,
      }),
    };

    jQuery
      .post(
        latepoint_timestamped_ajaxurl(),
        payload,
        (res) => {
          if (res.status === "success") this.buildMethodDropdowns(res.data);
        },
        "json"
      )
      .fail(() => alert("Error loading accounts."));
  }

  buildMethodDropdowns(accountsData) {
    const config = JSON.parse(
      jQuery(
        LatepointPaymentsIfthenpayAdmin.SELECTORS.methodsConfigInput
      ).val() || "{}"
    );

    jQuery(LatepointPaymentsIfthenpayAdmin.SELECTORS.methodItem).each(
      function () {
        const $item = jQuery(this);
        const entity = $item.data("entity");
        const accounts = accountsData[entity] || {};
        const $wrapper = $item.find(
          LatepointPaymentsIfthenpayAdmin.SELECTORS.methodsWrapper
        );
        const $checkbox = $item.find(
          LatepointPaymentsIfthenpayAdmin.SELECTORS.methodCheckbox
        );

        $wrapper.empty();
        $checkbox.prop("disabled", !Object.keys(accounts).length);

        if (!Object.keys(accounts).length) {
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
          $checkbox.prop("checked", false);
          return;
        }

        const $select = jQuery("<select>", {
          class: "ifthenpay-method-dropdown",
          name: `settings[ifthenpay_payment_methods_configuration][${entity}][selected_account]`,
        });

        const selectedValue = config[entity]?.selected_account;
        for (const [label, value] of Object.entries(accounts)) {
          $select.append(
            jQuery("<option>", {
              value,
              text: label,
              selected: value === selectedValue,
            })
          );
        }

        $wrapper.append($select);
        $select.prop("disabled", !$checkbox.is(":checked"));
      }
    );

    this.updateDefaultMethods();
    this.updateConfig();
  }

  toggleDropdown(event) {
    const $checkbox = jQuery(event.currentTarget);
    const $item = $checkbox.closest(
      LatepointPaymentsIfthenpayAdmin.SELECTORS.methodItem
    );
    const $select = $item.find(
      LatepointPaymentsIfthenpayAdmin.SELECTORS.methodDropdown
    );

    $select.prop("disabled", !$checkbox.is(":checked"));
    this.updateDefaultMethods();
    this.updateConfig();
  }

  toggleMethod(event) {
    const $target = jQuery(event.target);
    if (
      $target.is(LatepointPaymentsIfthenpayAdmin.SELECTORS.methodCheckbox) ||
      $target.closest(LatepointPaymentsIfthenpayAdmin.SELECTORS.methodDropdown)
        .length
    ) {
      return;
    }

    const $item = jQuery(event.currentTarget);
    const $checkbox = $item.find(
      LatepointPaymentsIfthenpayAdmin.SELECTORS.methodCheckbox
    );
    if ($checkbox.prop("disabled")) return;

    $checkbox.prop("checked", !$checkbox.prop("checked")).trigger("change");
  }

  updateDefaultMethods() {
    const $defaultSelect = jQuery(
      LatepointPaymentsIfthenpayAdmin.SELECTORS.defaultMethodSelect
    );
    if (!$defaultSelect.length) return;

    const selectedDefault =
      $defaultSelect.data("selected") || $defaultSelect.val();
    $defaultSelect.empty();

    const enabledEntities = [];
    jQuery(
      LatepointPaymentsIfthenpayAdmin.SELECTORS.methodCheckbox + ":checked"
    ).each(function () {
      const entity = jQuery(this)
        .closest(LatepointPaymentsIfthenpayAdmin.SELECTORS.methodItem)
        .data("entity");
      if (entity) enabledEntities.push(entity);
    });

    if (!enabledEntities.length) {
      $defaultSelect.append(
        jQuery("<option>", {
          value: "",
          text: latepoint_helper.ifthenpay_translations.warning_default_method,
          disabled: true,
          selected: true,
        })
      );
    } else {
      enabledEntities.forEach((entity) => {
        $defaultSelect.append(
          jQuery("<option>", {
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
        const entity = $item.data("entity");
        const $checkbox = $item.find(
          LatepointPaymentsIfthenpayAdmin.SELECTORS.methodCheckbox
        );
        const $select = $item.find(
          LatepointPaymentsIfthenpayAdmin.SELECTORS.methodDropdown
        );

        config[entity] = {
          checked: $checkbox.is(":checked"),
          selected_account: $select.val() || "",
        };
      }
    );

    jQuery(LatepointPaymentsIfthenpayAdmin.SELECTORS.methodsConfigInput).val(
      JSON.stringify(config)
    );
  }

  handleActivate(event) {
    event.preventDefault();
    const $link = jQuery(event.currentTarget);
    const entity = $link.data("entity");
    const gatewayKey = jQuery(
      LatepointPaymentsIfthenpayAdmin.SELECTORS.gatewaySelect
    ).val();

    // build your AJAX-mail payload
    const payload = {
      action: "latepoint_route_call",
      route_name: latepoint_helper.ifthenpay_activate_account_route,
      layout: "none",
      return_format: "json",
      params: this.serialize({
        gateway_key: gatewayKey,
        entity: entity,
      }),
    };

    jQuery
      .post(
        latepoint_timestamped_ajaxurl(),
        payload,
        (res) => {
          console.log(res);
          if (res.status === "success") {
            alert(
              res.message || "Your activation request has been sent to support."
            );
          } else {
            alert(res.message || "Failed to send activation request.");
          }
        },
        "json"
      )
      .fail(() => {
        alert("Server error sending activation request.");
      });
  }
}

jQuery(() => new LatepointPaymentsIfthenpayAdmin());
