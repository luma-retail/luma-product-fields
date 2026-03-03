jQuery(function ($) {
    var STORAGE_KEY = 'luma_product_fields_settings_tab';

    function isLumaSettingsPage() {
        return (
            window.location.search.indexOf('tab=products') !== -1 &&
            window.location.search.indexOf('section=luma_product_fields') !== -1
        );
    }

    function getCurrentTabFromUrl() {
        var params = new URLSearchParams(window.location.search);
        return params.get('luma_settings_tab');
    }

    function persistCurrentTab(tab) {
        if (!tab) {
            return;
        }

        window.localStorage.setItem(STORAGE_KEY, tab);
        $('input[name="luma_settings_tab"]').val(tab);
    }

    function maybeRestoreTabFromStorage() {
        if (!isLumaSettingsPage()) {
            return;
        }

        var currentTab = getCurrentTabFromUrl();
        if (currentTab) {
            persistCurrentTab(currentTab);
            return;
        }

        var storedTab = window.localStorage.getItem(STORAGE_KEY);
        if (!storedTab) {
            return;
        }

        var params = new URLSearchParams(window.location.search);
        params.set('luma_settings_tab', storedTab);
        window.location.replace(window.location.pathname + '?' + params.toString());
    }

    maybeRestoreTabFromStorage();

    $(document).on('click', '.woocommerce-nav-tab-wrapper a.nav-tab', function () {
        var href = $(this).attr('href');
        if (!href) {
            return;
        }

        var targetUrl = new URL(href, window.location.origin);
        var targetTab = targetUrl.searchParams.get('luma_settings_tab');
        persistCurrentTab(targetTab);
    });

    $('#mainform').on('submit', function () {
        var currentTab = getCurrentTabFromUrl() || $('input[name="luma_settings_tab"]').val();
        persistCurrentTab(currentTab);
    });

    function ensureMinimumRow($table) {
        var $tbody = $table.find('tbody');
        if ($tbody.find('tr').length > 0) {
            return;
        }

        addRow($table);
    }

    function addRow($table) {
        var $tbody = $table.find('tbody');

        var $templateRow = $table.find('tbody tr:first').clone();
        if (!$templateRow.length) {
            return;
        }

        $templateRow.find('input').val('');
        $tbody.append($templateRow);
    }

    $('.lumaprfi-add-row').on('click', function () {
        var target = $(this).data('lumaprfiTarget');
        if (!target) {
            return;
        }

        var $table = $('.lumaprfi-repeater[data-lumaprfi-repeater="' + target + '"]');
        if (!$table.length) {
            return;
        }

        addRow($table);
        $table.find('tbody tr:last input:first').trigger('focus');
    });

    $(document).on('click', '.lumaprfi-remove-row', function () {
        var $table = $(this).closest('table.lumaprfi-repeater');
        $(this).closest('tr').remove();
        ensureMinimumRow($table);
    });

    $('table.lumaprfi-repeater').each(function () {
        ensureMinimumRow($(this));
    });
});
