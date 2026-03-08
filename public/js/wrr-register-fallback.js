(function ($) {
  "use strict";

  function formatUaPhone(value) {
    var digits = String(value || "").replace(/\D/g, "");

    if (digits.indexOf("380") === 0) {
      digits = digits.slice(3);
    } else if (digits.indexOf("80") === 0) {
      digits = digits.slice(2);
    } else if (digits.indexOf("0") === 0) {
      digits = digits.slice(1);
    }

    digits = digits.slice(0, 9);

    var result = "+380";
    if (digits.length > 0) result += " (" + digits.slice(0, 2);
    if (digits.length >= 2) result += ")";
    if (digits.length > 2) result += " " + digits.slice(2, 5);
    if (digits.length > 5) result += "-" + digits.slice(5, 7);
    if (digits.length > 7) result += "-" + digits.slice(7, 9);

    return result;
  }

  function bindUaPhoneMask(context) {
    var $phones = (context || $(document)).find("#reg_wrr_phone");
    if (!$phones.length) return;

    $phones.each(function () {
      var $input = $(this);
      if ($input.data("wrr-phone-mask")) return;

      if (!$input.val()) {
        $input.val("+380");
      } else {
        $input.val(formatUaPhone($input.val()));
      }

      $input.attr("placeholder", "+380 (67) 123-45-67");
      $input.attr("inputmode", "tel");
      $input.attr("autocomplete", "tel");

      $input.on("focus", function () {
        if (!$input.val()) {
          $input.val("+380");
        }
      });

      $input.on("input", function () {
        $input.val(formatUaPhone($input.val()));
      });

      $input.on("keydown", function (e) {
        var start = this.selectionStart || 0;
        if ((e.key === "Backspace" || e.key === "Delete") && start <= 4) {
          e.preventDefault();
        }
      });

      $input.data("wrr-phone-mask", true);
    });
  }

  function injectRegisterFields() {
    // If already added, do nothing
    if (typeof wrr_register_settings !== "undefined") {
      // If dob exists and was inserted already, skip
      if (
        wrr_register_settings.fields.date_of_birth &&
        $("#reg_wrr_dob").length
      )
        return;
      if (
        wrr_register_settings.fields.first_name &&
        $("#reg_wrr_first_name").length
      )
        return;
      if (wrr_register_settings.fields.phone && $("#reg_wrr_phone").length)
        return;
    } else {
      if (
        $("#reg_wrr_dob").length ||
        $("#reg_wrr_first_name").length ||
        $("#reg_wrr_phone").length
      )
        return;
    }

    // Common registration form selectors
    var selectors = [
      "form#registerform",
      "form.woocommerce-form--register",
      "form.woocommerce-form.register",
      "form.register",
    ];
    var $form = $();
    selectors.forEach(function (s) {
      if ($form.length) return;
      var f = $(s);
      if (f.length) $form = f.first();
    });

    if (!$form.length) return;

    var labels =
      typeof wrr_register_settings !== "undefined" &&
      wrr_register_settings.labels
        ? wrr_register_settings.labels
        : {
            first_name: "First name",
            last_name: "Last name",
            phone: "Phone",
            date_of_birth: "Date of birth",
          };
    var $first = $(
      '<p class="form-row form-row-first"><label for="reg_wrr_first_name">' +
        labels.first_name +
        ' <span class="required">*</span></label><input type="text" class="input-text" name="wrr_first_name" id="reg_wrr_first_name" /></p>',
    );
    var $last = $(
      '<p class="form-row form-row-last"><label for="reg_wrr_last_name">' +
        labels.last_name +
        ' <span class="required">*</span></label><input type="text" class="input-text" name="wrr_last_name" id="reg_wrr_last_name" /></p>',
    );
    var $dob = $(
      '<p class="form-row form-row-wide"><label for="reg_wrr_dob">' +
        labels.date_of_birth +
        '</label><input type="date" class="input-text" name="wrr_dob" id="reg_wrr_dob" /></p>',
    );
    var $phone = $(
      '<p class="form-row form-row-wide"><label for="reg_wrr_phone">' +
        labels.phone +
        ' <span class="required">*</span></label><input type="tel" class="input-text" name="wrr_phone" id="reg_wrr_phone" /></p>',
    );

    // Try to append near submit button if exists
    var $submit = $form.find("button[type=submit], input[type=submit]").last();
    // Insert only enabled fields (settings localized into wrr_register_settings)
    var fields =
      typeof wrr_register_settings !== "undefined" &&
      wrr_register_settings.fields
        ? wrr_register_settings.fields
        : { first_name: 1, last_name: 1, phone: 1, date_of_birth: 0 };

    var insertBefore = function ($el) {
      if ($submit.length) {
        $submit.before($el);
      } else {
        $form.append($el);
      }
    };

    if (fields.first_name) insertBefore($first);
    if (fields.last_name) insertBefore($last);
    if (fields.phone) insertBefore($phone);
    if (fields.date_of_birth) insertBefore($dob);

    bindUaPhoneMask($form);
  }

  $(document).ready(function () {
    try {
      injectRegisterFields();
      bindUaPhoneMask($(document));
    } catch (e) {
      console && console.warn && console.warn("wrr fallback error", e);
    }

    // Run again after a short delay to handle JS-rendered forms
    setTimeout(injectRegisterFields, 1200);
  });
})(jQuery);
