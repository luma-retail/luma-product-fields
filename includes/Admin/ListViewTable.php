<?php
/**
 * Product field overview table.
 *
 * @package Luma\ProductFields
 */

namespace Luma\ProductFields\Admin;

use WP_List_Table;
use Luma\ProductFields\Utils\Helpers;
use Luma\ProductFields\Registry\FieldTypeRegistry;

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

 /*
 * Product field overview table class.
 *
 * Showing an overview of fields per product group utilizing WP_List_Table.
 *
 * @hook luma_product_fields_listview_column
 *      Filters columns in the table to add fields from outside the plugin ecosystem
 *
 */ 
class ListViewTable extends WP_List_Table {

    /**
     * Selected product group slug.
     *
     * @var string
     */
    protected $product_group_slug;

    /**
     * @var string|null
     */
    protected $orderby;

    /**
     * @var string|null
     */
    protected $order;

    /**
     * Constructor.
     *
     * @param string $product_group_slug Product group term slug
     */
    public function __construct( $product_group_slug ) {
        parent::__construct([
            'singular' => 'product',
            'plural'   => 'products',
            'ajax'     => false,
        ]);
        $this->product_group_slug = $product_group_slug;
        $this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];
        
        // Sorting: read from $_GET, sanitize and whitelist.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- using GET only to control sort order, no data mutation.
        $orderby_raw = isset( $_GET['orderby'] ) ? wp_unslash( $_GET['orderby'] ) : '';  // phpcs:ignore
        $order_raw   = isset( $_GET['order'] ) ? wp_unslash( $_GET['order'] ) : 'asc';  // phpcs:ignore
        $this->orderby = sanitize_key( $orderby_raw );

        $order = strtolower( sanitize_text_field( $order_raw ) ); 
        $this->order = in_array( $order, [ 'asc', 'desc' ], true ) ? $order : 'asc';
        
    }
    

    /**
     * Prepare product items for display.
     *
     * @return void
     */
    public function prepare_items() {
        $per_page = 30;
        $paged    = $this->get_pagenum();
        $args = [
            'status'     => 'publish',
            'limit'      => -1,
            'return'     => 'objects',
            'paginate'   => false,
            'visibility' => 'visible',
        ];

        if ($this->product_group_slug === 'general') {
            // Products with NO product group
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
            $args['tax_query'] = [
                [
                    'taxonomy' => 'lpf_product_group',
                    'operator' => 'NOT EXISTS',
                ]
            ];
        } else {
            // Products assigned to this group
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
            $args['tax_query'] = [
                [
                    'taxonomy' => 'lpf_product_group',
                    'field'    => 'slug',
                    'terms'    => $this->product_group_slug,
                ]
            ];
        }

        $products = wc_get_products( $args );
        $this->items = [];

        foreach ( $products as $product ) {
            if ( ! $product || ! $product->get_id() ) {
                continue;
            }

            $row = [
                'ID'         => $product->get_id(),
                'name'       => $product->get_name(),
            ];

            foreach ( Helpers::get_fields_for_group( $this->product_group_slug ) as $field ) {
                if ( $product->is_type( 'variation' ) && ! $field['show_in_variation'] ) {
                    continue;
                }
                $is_numeric = FieldTypeRegistry::field_type_is_numeric( $field['type'] );                
                $row[ 'lpftbl_' . $field['slug'] ] = [                    
                    'raw'  => Helpers::get_formatted_field_value( $product->get_id(), $field['slug'] , false),
                    'html'  => Helpers::get_formatted_field_value( $product->get_id(), $field['slug'] , false),
                    'field' => $field,
                    'is_numeric' => $is_numeric,
                ]; 
            }

            $this->items[] = $row;
        }

        if ( $this->orderby && ! in_array( $this->orderby, [ 'name', 'sku' ], true ) ) {
            usort( $this->items, function ( $a, $b ) {
                $a_val = $a[ $this->orderby ]['raw'] ?? '';
                $b_val = $b[ $this->orderby ]['raw'] ?? '';

                $fields = Helpers::get_fields_for_group( $this->product_group_slug );
                foreach ( $fields as $field ) {
                    if ( 'lpftbl_' . $field['slug'] === $this->orderby ) {
                        if ( FieldTypeRegistry::field_type_is_numeric( $field['type'] ) ) {
                            $a_val = floatval( $a_val );
                            $b_val = floatval( $b_val );
                        }
                        break;
                    }
                }

                $compare = $a_val <=> $b_val;
                return ( $this->order === 'desc' ) ? -$compare : $compare;
            });
        }

        $total_items = count( $this->items );
        $this->items = array_slice( $this->items, ( $paged - 1 ) * $per_page, $per_page );

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total_items / $per_page ),
        ]);
    }
    
/**
 * Render column value.
 *
 * @param array  $item        Row item.
 * @param string $column_name Column name.
 *
 * @return string
 */
public function column_default( $item, $column_name ) {
    if ( isset( $item[ $column_name ]['field'] ) ) {
        $field      = $item[ $column_name ]['field'];
        $product_id = $item['ID'];
        $html = self::render_field_cell( $product_id, $field );
        return wp_kses( $html, wp_kses_allowed_html( 'luma_product_fields_admin_fields' ) );
    }

    // 🔹 External override for non-HPF fields
    $override = apply_filters(
        'luma_product_fields_listview_column_value',
        null,          
        $column_name,
        $item
    );

    if ( $override !== null ) {
        return $override;
    }

    $value = $item[ $column_name ] ?? null;

    if ( is_array( $value ) ) {
        return implode( ', ', $value );
    }

    if ( is_object( $value ) ) {
        return '<code>' . esc_html( json_encode( $value, JSON_UNESCAPED_UNICODE ) ) . '</code>';
    }

    if ( $value !== null && $value !== '' ) {
        return $this->is_html( $value ) ? $value : esc_html( $value );
    }

    return '&mdash;';
}



    /**
     * Render the name column with edit link.
     *
     * @param array $item Row item.
     *
     * @return string
     */
    public function column_name( $item ) {
        $edit_link = get_edit_post_link( $item['ID'] );
        $name = $item['name'] ?? '(no name)';
        $content = sprintf(
            '<a href="%s" target="_blank">%s</a>',
            esc_url( $edit_link ),
            esc_html( $name )
        );
        $product = wc_get_product( $item['ID'] );
        if ( $product && $product->is_type( 'variable' ) && Helpers::get_product_group_slug( $product->get_id() ) ) {

            $toggle = sprintf(
                '<button class="lpf-toggle-variations" data-product-id="%d" aria-label="Toggle variations" aria-expanded="false">
                    <span class="dashicons dashicons-arrow-right"></span>
                </button> ',
                $product->get_id()
            );

            return $toggle . $content;
        }
        return $content;
    }
    
    
    

    protected function is_html(string $string): bool {
        return $string !== wp_strip_all_tags($string);
    }


    /**
     * Defines the columns to display in the product field overview table.
     *
     * @return array<string, string>
     */
    public function get_columns() {
        $columns = [
            'name'       => __( 'Name', 'luma-product-fields' ),
        ];

        foreach ( Helpers::get_fields_for_group( $this->product_group_slug ) as $field ) {
            $columns[ 'lpftbl_' . $field['slug'] ] = $field['label'];
        }
        $columns = apply_filters( 'luma_product_fields_listview_columns', $columns );
        return $columns;
    }

    /**
     * Declare which columns are sortable.
     *
     * @return array
     */
    public function get_sortable_columns() {
        $columns = [
            'name' => [ 'name', false ],
        ];

        foreach ( Helpers::get_fields_for_group( $this->product_group_slug ) as $field ) {
            $col_key = 'lpftbl_' . $field['slug'];
            $columns[ $col_key ] = [ $col_key, false ]; 
        }

        return $columns;
    }
    
    
    
    /**
	 * Generates the HTML table for variations of a variable product.
	 *
	 * @param int $product_id The ID of the variable product.
	 *
	 * @return string HTML table output.
	 */
    public function load_variations( int $product_id ): string {

        $product = wc_get_product( $product_id );

        if ( ! $product || ! $product->is_type( 'variable' ) ) {
            return '';
        }

        $variations = $product->get_children();
        $fields     = Helpers::get_fields_for_group( $this->product_group_slug );

        ob_start();
        foreach ( $variations as $variation_id ) {
            $variation = wc_get_product( $variation_id );
            if ( ! $variation ) {
                continue;
            }
            ?>
            <tr class="variation-child-row variation-child-of-<?php echo esc_attr( $product_id ); ?>">
                <td class="column-name">
                    <?php echo esc_html( $variation->get_name() ); ?>
                </td>
                <?php 
                foreach ( $fields as $field ) :
                    $is_numeric = FieldTypeRegistry::field_type_is_numeric( $field['type'] );
                    ?>
                    <td class="column-<?php echo esc_attr( 'lpftbl_' . $field['slug'] ); ?><?php echo $is_numeric ? ' lpf-is-numeric' : ''; ?>">
                        <?php
                        if ( ! empty( $field['variation'] ) ) {
                            $updated_html = ListViewTable::render_field_cell( $variation_id, $field );
                            echo wp_kses( $updated_html, wp_kses_allowed_html( 'luma_product_fields_admin_fields' ) );
                        } else {
                            echo esc_html( ' -- ' );
                        }
                        ?>
                    </td>
                <?php endforeach; ?>
            </tr>
            <?php
        }
        $html = ob_get_clean();
        return $html;
    }
        

    /**
     * Render a single field cell as HTML.
     *
     * If the field definition includes a custom 'render_admin_list_cb', it will be used.
     * Otherwise, it defaults to rendering a <div> with data attributes for inline editing.
     *
     * @param int   $product_id Product or variation ID.
     * @param array $field      Field definition array.
     *
     * @return string HTML output for the field cell.
     */
    public static function render_field_cell( int $product_id, array $field ): string {
        
        $field_definition = FieldTypeRegistry::get( $field['type'] );
        if ( isset( $field_definition['render_admin_list_cb'] ) && is_callable( $field_definition['render_admin_list_cb'] ) ) {
            return call_user_func( $field_definition['render_admin_list_cb'], $product_id, $field );
        }

        $raw_value  = Helpers::get_field_value( $product_id, $field['slug'] );
        $html_value = self::render_field_cell_inner( $product_id, $field );
        
        $is_numeric = FieldTypeRegistry::field_type_is_numeric( $field['type'] ?? 'text' );
        $classes    = [ 'lpf-editable' ];

        if ( $is_numeric ) {
            $classes[] = 'lpf-is-numeric';
        }

        return sprintf(
            '<div class="%s" 
                data-product-id="%d" 
                data-field-slug="%s" 
                data-field-type="%s"
                data-original-value="%s"
                id="fkf-%d-%s">%s</div>',
            esc_attr( implode( ' ', $classes ) ),
            $product_id,
            esc_attr( $field['slug'] ),
            esc_attr( $field['type'] ?? 'text' ),
            esc_attr( is_scalar( $raw_value ) ? $raw_value : '' ),
            $product_id,
            esc_attr( $field['slug'] ),
            $html_value
        );
    }


    /**
     * Render the inner HTML for a field cell
     *
     * @param int   $product_id Product ID.
     * @param array $field      Field definition.
     * @return string
     */
    public static function render_field_cell_inner( int $product_id, array $field ): string {

        $field_definition = FieldTypeRegistry::get( $field['type'] ?? 'text' );

        if ( isset( $field_definition['render_admin_list_cb'] ) && is_callable( $field_definition['render_admin_list_cb'] ) ) {
            return (string) call_user_func( $field_definition['render_admin_list_cb'], $product_id, $field );
        }

        return (string) Helpers::get_formatted_field_value( $product_id, $field['slug'], false );
    }


}
