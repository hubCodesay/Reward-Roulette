(function($){
    'use strict';

    function injectRegisterFields() {
        // If already added, do nothing
        if ($('#reg_wrr_dob').length) return;

        // Common registration form selectors
        var selectors = ['form#registerform', 'form.woocommerce-form--register', 'form.woocommerce-form.register', 'form.register'];
        var $form = $();
        selectors.forEach(function(s){
            if ($form.length) return;
            var f = $(s);
            if (f.length) $form = f.first();
        });

        if (!$form.length) return;

        var $first = $("<p class=\"form-row form-row-first\"><label for=\"reg_wrr_first_name\">First name <span class=\"required\">*</span></label><input type=\"text\" class=\"input-text\" name=\"wrr_first_name\" id=\"reg_wrr_first_name\" /></p>");
        var $last = $("<p class=\"form-row form-row-last\"><label for=\"reg_wrr_last_name\">Last name <span class=\"required\">*</span></label><input type=\"text\" class=\"input-text\" name=\"wrr_last_name\" id=\"reg_wrr_last_name\" /></p>");
        var $dob = $("<p class=\"form-row form-row-wide\"><label for=\"reg_wrr_dob\">Date of birth</label><input type=\"date\" class=\"input-text\" name=\"wrr_dob\" id=\"reg_wrr_dob\" /></p>");

        // Try to append near submit button if exists
        var $submit = $form.find('button[type=submit], input[type=submit]').last();
        if ($submit.length) {
            $submit.before($first).before($last).before($dob);
        } else {
            $form.append($first).append($last).append($dob);
        }
    }

    $(document).ready(function(){
        try { injectRegisterFields(); } catch(e){ console && console.warn && console.warn('wrr fallback error', e); }

        // Run again after a short delay to handle JS-rendered forms
        setTimeout(injectRegisterFields, 1200);
    });

})(jQuery);
