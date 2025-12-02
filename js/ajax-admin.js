// Reusable function to initialize all .lpf-autocomplete-select fields
function initAutocompleteSelectFields() {
    jQuery('.lpf-autocomplete-select').each(function () {
        const $select = jQuery(this);
        const taxonomy = $select.data('taxonomy');

        // Avoid double initialization
        if ($select.hasClass('select2-hidden-accessible')) {
            $select.select2('destroy');
        }

        const select2Options = {
            ajax: {
                type: 'POST',
                url: luma_product_fields_admin_ajaxdata.ajaxurl,
                dataType: 'json',
                delay: 250,
                data: function (params) {
                    return {
                        action: luma_product_fields_admin_ajaxdata.action,
                        nonce: luma_product_fields_admin_ajaxdata.nonce,
                        lpf_action: 'autocomplete_search',
                        taxonomy: taxonomy,
                        term: params.term || ''
                    };
                },
                processResults: function (response, params) {
                    let results = response.data.results.map(item => ({
                        id: item.slug,
                        text: item.name
                    }));

                    // Allow creating new term after min length
                    if (params.term && params.term.length >= 2) {
                        results.push({
                            id: params.term,
                            text: params.term,
                            newTag: true
                        });
                    }

                    return { results };
                }

            },
            language: {
                inputTooShort: function (args) {
                    const remaining = args.minimum - args.input.length;
                    const template = luma_product_fields_admin_ajaxdata.autocomplete_min_chars
                        || 'Please enter %d more characters';

                    return template.replace('%d', remaining);
                },
                searching: function () {
                    return luma_product_fields_admin_ajaxdata.autocomplete_searching || 'Searching…';
                }
            },
            tags: false,
            minimumInputLength: 2,
            placeholder: luma_product_fields_admin_ajaxdata.autocomplete_placeholder || 'Start typing...',
            dropdownParent: jQuery('body'),   
            allowClear: true,
            width: '100%'                      
        };

        $select.select2(select2Options);
    });
}



function focusFirstEditorField($root){
    if(!$root || !$root.length) return;

    // Prefer anything marked explicitly
    let $target = $root.find('[autofocus]').filter(':visible:first');

    // Else: the first visible, enabled input/textarea/select (skip hidden)
    if(!$target || !$target.length){
        $target = $root.find('input:not([type=hidden]):not([disabled]), textarea:not([disabled]), select:not([disabled])')
                       .filter(':visible:first');
    }
    if(!$target.length) return;

    // Special handling for Select2
    if ($target.is('select') && $target.hasClass('lpf-autocomplete-select')) {
        // Ensure Select2 is mounted, then open
        // Slight delay to allow Select2 to fully initialize in this DOM
        setTimeout(function(){
            try {
                $target.select2('open');
            } catch(e){
                // Fallback: focus the selection element (no-op if not present)
                $root.find('.select2-selection').first().trigger('focus');
            }
        }, 0);
        return;
    }

    // Default focus for inputs/textareas/regular selects
    $target.trigger('focus');

    // If it's a text-like input, move caret to end (nice UX)
    if ($target.is('input[type="text"], input[type="search"], input[type="number"], input[type="url"], input[type="email"], textarea')) {
        const el = $target.get(0);
        if (el && typeof el.setSelectionRange === 'function') {
            const len = el.value ? el.value.length : 0;
            try { el.setSelectionRange(len, len); } catch(e) {}
        }
    }
}


// Main behavior on DOM ready
jQuery(function($) {
    
    function captureFieldValues($container) {
        const values = {};
        $container.find('input, textarea, select').each(function () {
            const $field = $(this);
            const name   = $field.attr('name');
            if (!name) {
                return;
            }
            if ($field.is(':checkbox')) {
                if (!values[name]) {
                    values[name] = [];
                }
                if ($field.is(':checked')) {
                    values[name].push($field.val());
                }
                return;
            }
            if ($field.is(':radio')) {
                if ($field.is(':checked')) {
                    values[name] = $field.val();
                }
                return;
            }
            if ($field.is('select') && $field.prop('multiple')) {
                values[name] = $field.val() || [];
                return;
            }
            values[name] = $field.val();
        });
        return values;
    }


    function restoreFieldValues($container, values) {
        if (!values) {
            return;
        }
        $container.find('input, textarea, select').each(function () {
            const $field = $(this);
            const name   = $field.attr('name');
            if (!name || typeof values[name] === 'undefined') {
                return;
            }
            const saved = values[name];
            if ($field.is(':checkbox')) {
                if (Array.isArray(saved)) {
                    $field.prop('checked', saved.includes($field.val()));
                } else {
                    // Single checkbox case (boolean-style)
                    $field.prop('checked', !!saved);
                }
                return;
            }
            if ($field.is(':radio')) {
                $field.prop('checked', saved == $field.val());
                return;
            }
            if ($field.is('select') && $field.prop('multiple')) {
                $field.val(saved);
                $field.trigger('change');
                return;
            }
            $field.val(saved);
            // In case Select2 / other widgets look at change events:
            $field.trigger('change');
        });
    }
    
    // Initialize autocomplete fields on page load
    initAutocompleteSelectFields();

    // On change of product group, update fields via AJAX
    $("#lpf-product-group-select").on("change", function (e) {
        const productGroup = $(this).find(":selected").val();
        const $fieldsContainer  = $('#lpf-product-group-fields');
        const preservedValues = captureFieldValues($fieldsContainer);    
        $('#lpf-product-group-fields').html(luma_product_fields_admin_ajaxdata.spinner);

        const data = {
            action: luma_product_fields_admin_ajaxdata.action,
            nonce: luma_product_fields_admin_ajaxdata.nonce,
            lpf_action: 'update_product_group',
            post_id: luma_product_fields_admin_ajaxdata.post_id,
            product_group: productGroup,
        };

        $.post(luma_product_fields_admin_ajaxdata.ajaxurl, data, function (response) {
            $('#lpf-product-group-fields').html(response.data.html);

            restoreFieldValues($fieldsContainer, preservedValues);
            initAutocompleteSelectFields();
        });
    });


        
    // In field editor: Toggle unit and show taxonomy links based on selected field type
    const $typeSelect = $('#lpf_fields_type_selector');
    const $unitRow = $('.field-unit-row');
    const $showLinksRow = $('.field-show-tax-links-row');
    const $variationsRow = $('.field-variations-row');
    
    function updateFieldVisibility() {
        const selectedType = $typeSelect.val();

        $.post(luma_product_fields_admin_ajaxdata.ajaxurl, {
            action: luma_product_fields_admin_ajaxdata.action,
            nonce: luma_product_fields_admin_ajaxdata.nonce,
            lpf_action: 'get_field_type_capabilities',
            field_type: selectedType
        }, function(response) {
            if (response.success) {
                $unitRow.toggle(response.data.supports_unit);
                $showLinksRow.toggle(response.data.supports_links);
                $variationsRow.toggle(response.data.supports_variations);
            }
        });
    }

    if ($typeSelect.length) {
        $typeSelect.on('change', updateFieldVisibility);
        updateFieldVisibility(); // Run once on page load
    }
        


    // Toggle and load variation table in ListViewTable
    $('.lpf-toggle-variations').on('click', function () {
      const $btn = $(this);
      const $icon = $btn.find('.dashicons');
      const productId = $btn.data('product-id');
      const selector = '.variation-child-of-' + productId;

      if ($btn.data('loaded') === 1) {
        const $rows = $(selector);
        const isVisible = $rows.first().is(':visible');
        $rows.toggle();
        // arrow + aria
        $icon.toggleClass('dashicons-arrow-right dashicons-arrow-down');
        $btn.attr('aria-expanded', String(!isVisible));
        return;
      }

      $btn.prop('disabled', true);
      // show a spinner icon while loading (optional):
      $icon.removeClass('dashicons-arrow-right').addClass('dashicons-update');

      const data = {
        action: luma_product_fields_admin_ajaxdata.action,
        nonce: luma_product_fields_admin_ajaxdata.nonce,
        lpf_action: 'load_variations',
        product_id: productId
      };

      $.post(luma_product_fields_admin_ajaxdata.ajaxurl, data, function (response) {
        if (response.success) {
          $(response.data).insertAfter($btn.closest('tr'));
          $btn.data('loaded', 1).attr('aria-expanded', 'true');
          $icon.removeClass('dashicons-update').addClass('dashicons-arrow-down');
        } else {
          alert(response.data?.error || 'Failed to load variations.');
          $icon.removeClass('dashicons-update').addClass('dashicons-arrow-right');
        }
      }).fail(function () {
        alert('AJAX request failed.');
        $icon.removeClass('dashicons-update').addClass('dashicons-arrow-right');
      }).always(function () {
        $btn.prop('disabled', false);
      });
    });



    // Inline editing for ListViewTable
    let currentEditor = null;

    function closeEditor() {
        if (currentEditor) {
            currentEditor.remove();
            $('#lpf-editor-overlay').hide();
            currentEditor = null;
            $('.lpf-editable').removeClass('lpf-editing');
            $('.lpf-editable').closest('tr').removeClass('lpf-row-editing');
        }
    }

    $(document).on('click', '.lpf-editable', function () {
        const $cell = $(this);
        const productId = $cell.data('product-id');
        const fieldSlug = $cell.data('field-slug');

        // Avoid opening multiple editors
        if ($cell.hasClass('lpf-editing')) {
            return;
        }

        closeEditor();
        $('#lpf-editor-overlay')
            .show()
            .off('click')
            .on('click', function () {
                closeEditor();
            });
    
        $cell.closest('tr').addClass('lpf-row-editing');

        $.post(luma_product_fields_admin_ajaxdata.ajaxurl, {
            action: luma_product_fields_admin_ajaxdata.action,
            nonce: luma_product_fields_admin_ajaxdata.nonce,
            lpf_action: 'inline_edit_render',
            product_id: productId,
            field_slug: fieldSlug
        }, function (response) {
            if (response.success) {
                // Create and append editor
                const $editor = $('<div class="lpf-floating-editor">').html(response.data.html);
                $('#wpbody-content').append($editor);

                // Make sure it measures correctly before positioning
                $editor.css({ visibility: 'hidden', display: 'block' });

                // Tooltip init 
                $editor.find('.woocommerce-help-tip').tooltip({
                    content: function () { return $(this).attr('data-tip'); },
                    items: '.woocommerce-help-tip',
                    tooltipClass: 'woocommerce-help-tip-ui'
                });

                // ---- Positioning (viewport-safe) ----
                function positionEditor() {
                    const rect = $cell[0].getBoundingClientRect(); // viewport coords of the cell
                    const vw = window.innerWidth;
                    const vh = window.innerHeight;
                    const edW = $editor.outerWidth();
                    const edH = $editor.outerHeight();
                    const margin = 12; // safe viewport padding
                    const minLeft = margin;
                    const maxLeft = Math.max(minLeft, vw - edW - margin);
                    const minTop  = margin;
                    const maxTop  = Math.max(minTop, vh - edH - margin);

                    // Start aligned to the cell’s top-left
                    let left = rect.left;
                    let top  = rect.top;

                    // Clamp inside viewport
                    left = Math.min(Math.max(left, minLeft), maxLeft);
                    top  = Math.min(Math.max(top,  minTop),  maxTop);

                    // If the editor is wider than the viewport minus margins, shrink it
                    const maxWidth = vw - margin * 2;
                    if (edW > maxWidth) {
                        $editor.css({ width: maxWidth });
                    }

                    $editor.css({
                        position: 'fixed',
                        left: left,
                        top:  top,
                        zIndex: 101,
                        background: '#fff',
                        padding: '8px',
                        border: '1px solid #ccc',
                        boxShadow: '0 0 6px rgba(0,0,0,0.15)',
                        visibility: 'visible'
                    });
                }

                // First position immediately
                positionEditor();

                // Reposition while open (scroll/resize)
                function bindReposition() {
                    $(window).on('scroll.fkInline resize.fkInline', positionEditor);
                }
                function unbindReposition() {
                    $(window).off('scroll.fkInline resize.fkInline');
                }

                bindReposition();

                $cell.addClass('lpf-editing');
                currentEditor = $editor;

                // Reinit select2 if needed
                initAutocompleteSelectFields();

                // Focus the first sensible control (your helper from earlier)
                focusFirstEditorField($editor);

                // Clean up bindings when closing
                function teardownEditor() {
                    unbindReposition();
                    $editor.remove();
                    currentEditor = null;
                    $('.lpf-editable').removeClass('lpf-editing');
                    $('.lpf-editable').closest('tr').removeClass('lpf-row-editing');
                }


                $cell.addClass('lpf-editing');
                currentEditor = $editor;
            
                // Reinit select2 if needed and set focus to current field
                setTimeout(() => {
                    initAutocompleteSelectFields();
                    focusFirstEditorField($editor);
                    console.log( 'reinitiated');
                }, 50);
                            

                // ✅ SAVE 
                $editor.find('.lpf-edit-save').on('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();                    
                    const $btn = $(this);
                    $btn.prop('disabled', true);
                    const form = $editor.find('form')[0];
                    const formData = new FormData(form);
                    const postData = {
                        action: luma_product_fields_admin_ajaxdata.action,
                        lpf_action: 'inline_save_field',
                        product_id: productId,
                        field_slug: fieldSlug,
                        nonce: luma_product_fields_admin_ajaxdata.nonce
                    };

                    const valueKey = `lpf-${fieldSlug}`;
                    let value = null;

                    for (const [key, rawValue] of formData.entries()) {
                        const trimmed = (typeof rawValue === 'string') ? rawValue.trim() : rawValue;

                        if (key === valueKey) {
                            value = trimmed;
                        } else if (key === `${valueKey}[]`) {
                            if (!Array.isArray(value)) value = [];
                            if (trimmed !== '') value.push(trimmed);
                        } else if (key.startsWith(`${valueKey}[`)) {
                            const m = key.match(/^lpf-[^[]+\[([^\]]+)\]$/);
                            if (m) {
                                if (value === null || typeof value !== 'object' || Array.isArray(value)) value = {};
                                value[m[1]] = trimmed;
                            }
                        } else {
                            postData[key] = trimmed;
                        }
                    }
                    postData.value = value;

                    $.post(luma_product_fields_admin_ajaxdata.ajaxurl, postData, function (res) {
                        if (res.success) {
                            $cell.html(res.data.html).removeClass('lpf-editing');
                            $('.lpf-editable').closest('tr').removeClass('lpf-row-editing');
                            closeEditor();
                            $cell.removeClass('lpf-save-glow lpf-save-glow-reset').addClass('lpf-save-glow');
                            setTimeout(() => $cell.addClass('lpf-save-glow-reset'), 1000);
                            setTimeout(() => $cell.removeClass('lpf-save-glow lpf-save-glow-reset'), 3000);
                        } else {
                            alert(res.data || 'Failed to save.');
                            $btn.prop('disabled', false);
                        }
                    });
                });

                // Cancel
                $editor.find('.lpf-edit-cancel').on('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    const $btn = $(this);
                    $btn.prop('disabled', true)
                    $('.lpf-editable').closest('tr').removeClass('lpf-row-editing');
                    $cell.removeClass('lpf-editing');
                    closeEditor();
                });

            } else {
                alert(response.data || 'Could not load field.');
            }
        });
    });

    // Close editor on outside click
    $(document).on('click', function (e) {
        const $target = $(e.target);
        const isInsideEditor =
            $target.closest('.lpf-floating-editor').length > 0 ||
            $target.closest('.lpf-editable').length > 0 ||
            $target.closest('.select2-container').length > 0 ||
            $target.closest('.select2-dropdown').length > 0 ||
            $target.closest('.select2-selection__choice__remove').length > 0;

        if (!isInsideEditor) {
            $('.lpf-editable').closest('tr').removeClass('lpf-row-editing');
            $('.lpf-editable').removeClass('lpf-editing');
            closeEditor();
        }
    });

    // Close editor on ESC key
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' || e.keyCode === 27) {
            closeEditor();
        }
    });
});



// Toggle featured taxonomy
jQuery(document).on('click', 'a.lpf-toggle-featured', function (e) {
    e.preventDefault();

    var $a     = jQuery(this);
    var $icon  = $a.find('.dashicons');
    var termId = parseInt($a.data('term-id'), 10);

    if (!termId || !$icon.length) return;

    // Visual feedback.
    $icon.addClass('is-busy');

    jQuery.post(luma_product_fields_admin_ajaxdata.ajaxurl, {
        action:   luma_product_fields_admin_ajaxdata.action,       // "luma_product_fields_ajax"
        nonce:    luma_product_fields_admin_ajaxdata.nonce,        // "luma_product_fields_admin_nonce"
        lpf_action:'toggle_featured_term',
        term_id:  termId
    })
    .done(function (resp) {
        if (resp && resp.success && resp.data) {
            var featured = (resp.data.featured === 'yes');
            $icon
                .removeClass('dashicons-star-filled dashicons-star-empty')
                .addClass(featured ? 'dashicons-star-filled' : 'dashicons-star-empty')
                .removeClass('is-busy');
        } else {
            $icon.removeClass('is-busy');
            alert((resp && resp.data && resp.data.message) ? resp.data.message : 'Error');
        }
    })
    .fail(function (xhr) {
        $icon.removeClass('is-busy');
        alert('Request failed: ' + (xhr && xhr.status ? xhr.status : ''));
    });
});


jQuery(function($) {

    $(document).on('change', 'select[name^="map_"]', function(event) {
        var metaKey = $(this).val();
        if (!metaKey) {
            return;
        }

        $.post(
            luma_product_fields_admin_ajaxdata.ajaxurl,
            {
                action: luma_product_fields_admin_ajaxdata.action,
                lpf_action: 'migration_meta_preview',
                nonce: luma_product_fields_admin_ajaxdata.nonce,
                meta_key: metaKey,
                limit: 10
            }
        ).done(function(response) {
            if (!response || !response.success) {
                return;
            }

            var values = response.data.values || [];
            var html = '<strong>Sample values for:</strong> <code>' + metaKey + '</code><br>';

            if (values.length === 0) {
                html += '<em>No values found</em>';
            } else {
                html += '<ul>';
                values.forEach(function(v) {
                    html += '<li>' + $('<div/>').text(v).html() + '</li>';
                });
                html += '</ul>';
            }

            var $modal = $('<div class="lpf-meta-preview-modal"></div>').html(html)
                .css({
                    background: '#fff',
                    border: '1px solid #ccc',
                    padding: '12px',
                    position: 'absolute',
                    zIndex: 99999,
                    maxWidth: '360px'
                });

            $('body').append($modal);

            $modal.css({
                top: event.pageY + 12 + 'px',
                left: event.pageX + 'px'
            });

            setTimeout(function() {
                $(document).one('click', function() {
                    $modal.remove();
                });
            }, 50);
        });
    });

});

// Field editor field type highlight
jQuery(function($) {
    function highlightType(typeSlug) {
        var $items = $('.lpf-types-desc li');
        $items.removeClass('is-active');

        if (!typeSlug) {
            return;
        }

        var $target = $('#lpf-type-' + typeSlug);
        if ($target.length) {
            $target.addClass('is-active');
        }
    }

    var $select = $('#lpf_fields_type_selector');

    // Initial highlight on load
    highlightType($select.val());

    // Update highlight on change
    $select.on('change', function() {
        highlightType($(this).val());
    });
});