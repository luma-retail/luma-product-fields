jQuery(function ($) {
    var menu = document.getElementById('menu-posts-product');
    if (menu) {
        menu.classList.add('wp-has-current-submenu', 'wp-menu-open');
    }

    var link = menu ? menu.querySelector('a[href*="page=luma-product-fields"]') : null;
    if (link) {
        link.classList.add('current');
        var li = link.closest('li');
        if (li) {
            li.classList.add('current');
        }
    }

    var $typeInputs = $('input[name="lrpf_type"]');
    var $items = $('.luma-product-fields-types-desc li');
    var $unitRow = $('.field-unit-row');
    var $showLinksRow = $('.field-show-tax-links-row');
    var $variationsRow = $('.field-variations-row');
    var $initialTermsRow = $('.field-initial-terms-row');
    var $initialTermsContainer = $('#luma-product-fields-initial-terms');

    function getSelectedFieldType() {
        return $typeInputs.filter(':checked').val();
    }

    function updateFieldVisibility() {
        var selectedType = getSelectedFieldType();
        if (!selectedType || typeof luma_product_fields_admin_ajaxdata === 'undefined') {
            return;
        }

        $.post(luma_product_fields_admin_ajaxdata.ajaxurl, {
            action: luma_product_fields_admin_ajaxdata.action,
            nonce: luma_product_fields_admin_ajaxdata.nonce,
            luma_product_fields_action: 'get_field_type_capabilities',
            field_type: selectedType
        }, function (response) {
            if (response.success) {
                $unitRow.toggle(response.data.supports_unit);
                $showLinksRow.toggle(response.data.supports_links);
                $variationsRow.toggle(response.data.supports_variations);
            }
        });
    }

    function highlightType(typeSlug) {
        $items.removeClass('is-active');

        if (!typeSlug) {
            return;
        }

        var $target = $('#luma-product-fields-type-' + typeSlug);
        if ($target.length) {
            $target.addClass('is-active');
        }
    }

    function getEligibleInitialTypes() {
        var raw = $initialTermsRow.data('lumaprfiEligibleTypes');

        if (typeof raw !== 'string' || !raw.length) {
            return [];
        }

        return raw
            .split(',')
            .map(function (typeSlug) {
                return $.trim(typeSlug);
            })
            .filter(Boolean);
    }

    function updateInitialValuesVisibility() {
        if (!$initialTermsRow.length || !$typeInputs.length) {
            return;
        }

        var eligibleTypes = getEligibleInitialTypes();
        var selectedType = getSelectedFieldType();
        var shouldShow = eligibleTypes.indexOf(selectedType) !== -1;

        $initialTermsRow.toggleClass('hidden', !shouldShow);
    }

    function updateInitialValueRemoveButtons() {
        if (!$initialTermsContainer.length) {
            return;
        }

        var $rows = $initialTermsContainer.find('.lumaprfi-initial-term-row');
        $rows.each(function (index) {
            var $removeButton = $(this).find('.lumaprfi-remove-initial-term');
            $removeButton.toggleClass('hidden', $rows.length <= 1 || index === 0);
        });
    }

    if ($typeInputs.length) {
        $typeInputs.on('change', function () {
            var selectedType = getSelectedFieldType();
            highlightType(selectedType);
            updateFieldVisibility();
            updateInitialValuesVisibility();
        });

        var initialType = getSelectedFieldType();
        highlightType(initialType);
        updateFieldVisibility();
        updateInitialValuesVisibility();
    }

    $items.on('click', function (e) {
        e.preventDefault();

        var typeSlug = $(this).data('type');
        if (!typeSlug) {
            return;
        }

        $typeInputs.filter('[value="' + typeSlug + '"]').prop('checked', true).trigger('change');
    });

    if ($initialTermsContainer.length) {
        var $list = $initialTermsContainer.find('.lumaprfi-initial-terms-list');
        var $addButton = $initialTermsContainer.find('.lumaprfi-add-initial-term');
        var removeLabel = $.trim($initialTermsContainer.find('.lumaprfi-remove-initial-term').first().text()) || 'Remove';

        $initialTermsContainer.on('click', '.lumaprfi-remove-initial-term', function () {
            $(this).closest('.lumaprfi-initial-term-row').remove();
            updateInitialValueRemoveButtons();
        });

        $addButton.on('click', function () {
            if (!$list.length) {
                return;
            }

            var $newRow = $('<div class="lumaprfi-initial-term-row"></div>');
            var $input = $('<input type="text" name="lrpf_initial_terms[]" class="regular-text" />');
            var $removeButton = $('<button type="button" class="button-link-delete lumaprfi-remove-initial-term"></button>').text(removeLabel);

            $newRow.append($input).append($removeButton);
            $list.append($newRow);

            updateInitialValueRemoveButtons();
            $input.trigger('focus');
        });

        updateInitialValueRemoveButtons();
    }
});
