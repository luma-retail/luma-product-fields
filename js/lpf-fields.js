jQuery(document).ready(function ($) {
    $(".single_variation_wrap").on("show_variation", function (event, variation) {
        const variationId = variation.variation_id;
        const container = $("#lpf-product-meta-list");

        if (!variationId || !luma_product_fields_data || !luma_product_fields_data.ajax_url || !luma_product_fields_data.nonce) {
            console.warn("Missing variation ID or AJAX config.");
            return;
        }
        
        
        console.log( luma_product_fields_data );

        const data = {
                action: "lpf_get_variation_fields_html",
                variation_id: variationId,
                nonce: luma_product_fields_data.nonce
        };
        
        $.ajax({
            url: luma_product_fields_data.ajax_url,
            type: "POST",
            dataType: "json",
            data: {
                action: "lpf_get_variation_fields_html",
                variation_id: variationId,
                nonce: luma_product_fields_data.nonce
            },
            success: function (response) {
                if (response.success && response.data && response.data.html) {
                    container.html(response.data.html);
                } else {
                    console.warn("Failed to load variation fields:", response.data?.error || "Unknown error");
                }
            },
            error: function (xhr, status, error) {
                console.error("AJAX request failed:", status, error);
            }
        });
    });
});
