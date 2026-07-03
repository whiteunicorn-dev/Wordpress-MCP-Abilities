<?php
/**
 * WUA MCP ACF Abilities
 *
 * @package     wua-mcp-abilities
 * Description: Advanced Custom Fields abilities for the WUA MCP plugin.
 *              Requires ACF Pro to be installed and active.
 */

# =============================================================================
# Table of Contents
# =============================================================================
# 1. Register ACF Category
# 2. Register ACF Abilities
#       3.1  acf-create-field-group            - Create an ACF field group with fields and location rules
#       3.2  acf-get-field-groups              - List all registered ACF field groups
#       3.3  acf-get-fields                    - Get all fields in a specific field group
#       3.4  acf-get-field-value               - Read an ACF field value from a post / options / user / term
#       3.5  acf-update-field-value            - Write an ACF field value to a post / options / user / term
#       3.6  acf-update-local-json-field-group - Add or update fields in an existing ACF Local JSON field group
#       3.7  acf-update-options                - Write one or more ACF options page field values
# =============================================================================


# Register ACF Category
add_action( 'wp_abilities_api_categories_init', function() {

    wp_register_ability_category( 'wua-mcp-acf', [
        'label'       => 'WUA MCP ACF Abilities',
        'description' => 'Advanced Custom Fields abilities for WUA MCP integration',
    ]);

});


# Register ACF Abilities
add_action( 'wp_abilities_api_init', function() {

    // Bail early if ACF is not active
    if ( ! function_exists( 'acf_add_local_field_group' ) ) {
        return;
    }

    // -------------------------------------------------------------------------
    // 3.1 Create ACF Field Group
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-acf/acf-create-field-group', [
        'label'       => 'ACF Create Field Group',
        'description' => 'Create an ACF field group with fields and location rules. Supports all ACF Pro field types. Writes the group to the theme Local JSON (acf-json) directory; ACF auto-registers it on the next load.',
        'category'    => 'wua-mcp-acf',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'title' => [
                    'type'        => 'string',
                    'description' => 'Field group title (e.g. "Theme Options", "Page Hero Section")',
                ],
                'key' => [
                    'type'        => 'string',
                    'description' => 'Unique key for the field group (e.g. group_theme_options). Must start with "group_".',
                ],
                'fields' => [
                    'type'        => 'array',
                    'description' => 'Array of field definitions. Each field should include: key, label, name, type, and any type-specific settings.',
                    'items'       => [
                        'type'       => 'object',
                        'properties' => [
                            'key'           => [ 'type' => 'string',  'description' => 'Unique field key (e.g. field_hero_title). Must start with "field_".' ],
                            'label'         => [ 'type' => 'string',  'description' => 'Field label shown in the admin UI' ],
                            'name'          => [ 'type' => 'string',  'description' => 'Field name used in code (snake_case, no spaces)' ],
                            'type'          => [ 'type' => 'string',  'description' => 'ACF field type (e.g. text, textarea, image, wysiwyg, select, checkbox, radio, true_false, repeater, flexible_content, relationship, post_object, taxonomy, user, google_map, date_picker, color_picker, link, file, gallery, number, email, url, password, range, accordion, tab, message, clone)' ],
                            'instructions'  => [ 'type' => 'string',  'description' => 'Optional instructions shown below the field label' ],
                            'required'      => [ 'type' => 'integer', 'description' => '1 to make the field required, 0 for optional' ],
                            'default_value' => [ 'type' => 'string',  'description' => 'Default value for the field' ],
                            'placeholder'   => [ 'type' => 'string',  'description' => 'Placeholder text (for text-based fields)' ],
                            'choices'       => [ 'type' => 'object',  'description' => 'Key/value choices for select, checkbox, or radio fields (e.g. {"red": "Red", "blue": "Blue"})' ],
                            'sub_fields'    => [ 'type' => 'array',   'description' => 'Sub-fields for repeater or group fields' ],
                        ],
                    ],
                ],
                'location' => [
                    'type'        => 'array',
                    'description' => 'Location rules array. Each item is a rule group (OR). Within a group, each rule is ANDed. Example: [[{"param":"post_type","operator":"==","value":"post"}]]',
                    'items'       => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'param'    => [ 'type' => 'string', 'description' => 'Location param (e.g. post_type, page_template, options_page, taxonomy, user_role)' ],
                                'operator' => [ 'type' => 'string', 'description' => 'Operator: == or !=' ],
                                'value'    => [ 'type' => 'string', 'description' => 'Value to match (e.g. post, page, front_page)' ],
                            ],
                        ],
                    ],
                ],
                'position'        => [ 'type' => 'string',  'description' => 'Where to show the field group: normal, side, or acf_after_title (default: normal)' ],
                'style'           => [ 'type' => 'string',  'description' => 'Meta box style: default or seamless (default: default)' ],
                'label_placement' => [ 'type' => 'string',  'description' => 'Label placement: top or left (default: top)' ],
                'menu_order'      => [ 'type' => 'integer', 'description' => 'Order of the field group (default: 0)' ],
                'active'          => [ 'type' => 'integer', 'description' => '1 to activate the group, 0 to deactivate (default: 1)' ],
            ],
            'required' => [ 'title', 'key', 'fields', 'location' ],
        ],
        'execute_callback' => function( $input ) {
            if ( ! function_exists( 'acf_add_local_field_group' ) ) {
                return new WP_Error( 'acf_missing', 'ACF Pro is not active.' );
            }

            // v2.10: JSON-first. Write a complete Local JSON file (fields included) and let
            // ACF auto-register the group on the next load. We do NOT call
            // acf_update_field_group() here: on its own it saves the group post but NOT its
            // child fields (the pre-2.10 bug produced an empty group), and mixing DB records
            // with Local JSON creates duplicate acf-field-group entries.
            $field_group = [
                'key'                   => sanitize_text_field( $input['key'] ),
                'title'                 => sanitize_text_field( $input['title'] ),
                'fields'                => $input['fields']   ?? [],
                'location'              => $input['location'] ?? [],
                'menu_order'            => $input['menu_order'] ?? 0,
                'position'              => $input['position'] ?? 'normal',
                'style'                 => $input['style']    ?? 'default',
                'label_placement'       => $input['label_placement'] ?? 'top',
                'instruction_placement' => 'label',
                'hide_on_screen'        => '',
                'active'                => $input['active']   ?? 1,
                'description'           => '',
                'modified'              => time(),
            ];

            // Resolve ACF's Local JSON save directory (default: active theme /acf-json).
            $save_path = function_exists( 'acf_get_setting' ) ? acf_get_setting( 'save_json' ) : '';
            if ( empty( $save_path ) ) {
                $save_path = get_stylesheet_directory() . '/acf-json';
            }

            if ( ! is_dir( $save_path ) ) {
                wp_mkdir_p( $save_path );
            }
            if ( ! is_writable( $save_path ) ) {
                return new WP_Error( 'not_writable', 'ACF JSON directory is not writable: ' . $save_path );
            }

            $json_file = trailingslashit( $save_path ) . $field_group['key'] . '.json';
            $result    = file_put_contents(
                $json_file,
                json_encode( $field_group, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
            );

            if ( $result === false ) {
                return new WP_Error( 'json_write_error', 'Failed to write ACF JSON file. Check file permissions.' );
            }

            return [
                'created'     => true,
                'key'         => $field_group['key'],
                'title'       => $field_group['title'],
                'field_count' => count( $field_group['fields'] ),
                'json_file'   => $json_file,
                'note'        => 'Written to Local JSON. ACF auto-registers the group on the next load; use the Sync button in Custom Fields > Field Groups for a DB-editable copy.',
            ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);


    // -------------------------------------------------------------------------
    // 3.2 Get ACF Field Groups
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-acf/acf-get-field-groups', [
        'label'       => 'ACF Get Field Groups',
        'description' => 'List all registered ACF field groups',
        'category'    => 'wua-mcp-acf',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'active' => [ 'type' => 'integer', 'description' => 'Filter by active status: 1 for active only, 0 for inactive only. Omit for all.' ],
            ],
        ],
        'execute_callback' => function( $input ) {
            $args = [];
            if ( isset( $input['active'] ) ) {
                $args['active'] = (int) $input['active'];
            }

            $groups = acf_get_field_groups( $args );

            return array_map( fn( $g ) => [
                'key'        => $g['key'],
                'title'      => $g['title'],
                'active'     => $g['active'],
                'position'   => $g['position'],
                'menu_order' => $g['menu_order'],
                'location'   => $g['location'],
            ], $groups );
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);


    // -------------------------------------------------------------------------
    // 3.3 Get ACF Fields
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-acf/acf-get-fields', [
        'label'       => 'ACF Get Fields',
        'description' => 'Get all fields in a specific ACF field group',
        'category'    => 'wua-mcp-acf',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'group_key' => [ 'type' => 'string', 'description' => 'The field group key (e.g. group_theme_options)' ],
            ],
            'required' => [ 'group_key' ],
        ],
        'execute_callback' => function( $input ) {
            $fields = acf_get_fields( sanitize_text_field( $input['group_key'] ) );

            if ( ! $fields ) {
                return new WP_Error( 'not_found', 'No fields found for this field group key.' );
            }

            return array_map( fn( $f ) => [
                'key'           => $f['key'],
                'label'         => $f['label'],
                'name'          => $f['name'],
                'type'          => $f['type'],
                'required'      => $f['required'],
                'default_value' => $f['default_value'] ?? '',
                'instructions'  => $f['instructions']  ?? '',
            ], $fields );
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);


    // -------------------------------------------------------------------------
    // 3.4 Get ACF Field Value
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-acf/acf-get-field-value', [
        'label'       => 'ACF Get Field Value',
        'description' => 'Read an ACF field value from a post, page, user, term, or options page',
        'category'    => 'wua-mcp-acf',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'field_name' => [ 'type' => 'string', 'description' => 'The field name (not key) to retrieve' ],
                'post_id'    => [ 'type' => 'string', 'description' => 'Post ID, or "options" for theme options, or "user_2" for a user, or "term_3" for a term' ],
            ],
            'required' => [ 'field_name', 'post_id' ],
        ],
        'execute_callback' => function( $input ) {
            $value = get_field( sanitize_text_field( $input['field_name'] ), sanitize_text_field( $input['post_id'] ) );
            return [
                'field_name' => $input['field_name'],
                'post_id'    => $input['post_id'],
                'value'      => $value,
            ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);


    // -------------------------------------------------------------------------
    // 3.5 Update ACF Field Value
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-acf/acf-update-field-value', [
        'label'       => 'ACF Update Field Value',
        'description' => 'Write an ACF field value to a post, page, user, term, or options page. For image fields, pass the attachment ID (integer) as the value. Use search-media to find attachment IDs for images already in the media library.',
        'category'    => 'wua-mcp-acf',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'field_name' => [ 'type' => 'string', 'description' => 'The field name (not key) to update' ],
                'value'      => [ 'type' => 'string', 'description' => 'The value to set. For image fields, use the attachment ID (e.g. "42").' ],
                'post_id'    => [ 'type' => 'string', 'description' => 'Post ID, or "options" for theme options, or "user_2" for a user, or "term_3" for a term' ],
                'field_type' => [ 'type' => 'string', 'description' => 'Optional. Set to "image" to cast the value as an integer attachment ID before saving.' ],
            ],
            'required' => [ 'field_name', 'value', 'post_id' ],
        ],
        'execute_callback' => function( $input ) {
            $field_name = sanitize_text_field( $input['field_name'] );
            $post_id    = sanitize_text_field( $input['post_id'] );
            $value      = $input['value'];

            if ( ! empty( $input['field_type'] ) && $input['field_type'] === 'image' ) {
                $value      = (int) $value;
                $attachment = get_post( $value );
                if ( ! $attachment || $attachment->post_type !== 'attachment' ) {
                    return new WP_Error( 'attachment_not_found', 'Attachment ID ' . $value . ' not found in the media library.' );
                }
            }

            $result = update_field( $field_name, $value, $post_id );

            return [
                'updated'    => (bool) $result,
                'field_name' => $field_name,
                'post_id'    => $post_id,
            ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);


    // -------------------------------------------------------------------------
    // 3.6 Update ACF Local JSON Field Group
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-acf/acf-update-local-json-field-group', [
        'label'       => 'ACF Update Local JSON Field Group',
        'description' => 'Add or update fields in an existing ACF field group that is saved as Local JSON. Reads the existing JSON file, merges in new or updated fields, and saves it back. Use acf-get-fields first to retrieve existing fields so they can be preserved.',
        'category'    => 'wua-mcp-acf',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'group_key'  => [ 'type' => 'string', 'description' => 'The field group key (e.g. group_64f71c2711ed9)' ],
                'new_fields' => [
                    'type'        => 'array',
                    'description' => 'Array of new field definitions to append to the existing fields.',
                    'items'       => [ 'type' => 'object' ],
                ],
            ],
            'required' => [ 'group_key', 'new_fields' ],
        ],
        'execute_callback' => function( $input ) {
            $group_key = sanitize_text_field( $input['group_key'] );

            $stylesheet = get_option( 'stylesheet' );
            $template   = get_option( 'template' );

            $possible_paths = [
                WP_CONTENT_DIR . '/themes/' . $stylesheet . '/acf-json',
                WP_CONTENT_DIR . '/themes/' . $template   . '/acf-json',
                WP_CONTENT_DIR . '/themes/' . $stylesheet,
                WP_CONTENT_DIR . '/themes/' . $template,
            ];

            $acf_load_paths = apply_filters( 'acf/settings/load_json', [] );
            if ( is_array( $acf_load_paths ) ) {
                $possible_paths = array_merge( $acf_load_paths, $possible_paths );
            }

            $json_file = null;
            foreach ( $possible_paths as $path ) {
                $candidate = trailingslashit( $path ) . $group_key . '.json';
                if ( file_exists( $candidate ) ) {
                    $json_file = $candidate;
                    break;
                }
            }

            if ( ! $json_file ) {
                return new WP_Error( 'json_not_found', 'ACF Local JSON file not found for key: ' . $group_key );
            }

            $group = json_decode( file_get_contents( $json_file ), true );
            if ( ! $group ) {
                return new WP_Error( 'json_parse_error', 'Failed to parse ACF JSON file.' );
            }

            $existing_keys = array_column( $group['fields'] ?? [], 'key' );

            foreach ( $input['new_fields'] as $new_field ) {
                $existing_index = array_search( $new_field['key'], $existing_keys );
                if ( $existing_index !== false ) {
                    $group['fields'][ $existing_index ] = $new_field;
                } else {
                    $group['fields'][] = $new_field;
                }
            }

            $group['modified'] = time();

            $result = file_put_contents( $json_file, json_encode( $group, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

            if ( $result === false ) {
                return new WP_Error( 'json_write_error', 'Failed to write ACF JSON file. Check file permissions.' );
            }

            // NOTE (v2.9): Do NOT call acf_update_field_group() / acf_update_field() here.
            // Local JSON is the source of truth; ACF auto-registers the group from the
            // updated .json file on the next load. Registering it in the DB caused two bugs:
            //   1. ACF's save hook re-wrote the .json file from stale in-memory state,
            //      dropping the fields we just added (returned success, but file lost them).
            //   2. It created a duplicate acf-field-group DB record alongside the
            //      JSON-loaded one (two rows with the same key in the admin).
            // Writing the JSON file above is sufficient and is the reliable pattern.

            return [
                'updated'      => true,
                'group_key'    => $group_key,
                'group_title'  => $group['title'],
                'total_fields' => count( $group['fields'] ),
                'new_fields'   => count( $input['new_fields'] ),
                'json_file'    => $json_file,
            ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);


    // -------------------------------------------------------------------------
    // 3.7 Update ACF Options
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-acf/acf-update-options', [
        'label'       => 'ACF Update Options',
        'description' => 'Write one or more ACF options page field values. Supports all field types: text, link (object), image (attachment ID integer), and repeaters (array of rows). Uses update_field() with the "options" post ID so ACF handles serialisation correctly.',
        'category'    => 'wua-mcp-acf',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'fields' => [
                    'type'        => 'array',
                    'description' => 'Array of field updates to apply. Each item needs a field_name and value.',
                    'items'       => [
                        'type'       => 'object',
                        'properties' => [
                            'field_name' => [
                                'type'        => 'string',
                                'description' => 'The ACF field name (e.g. header_topbar_link, footer_copyright)',
                            ],
                            'value' => [
                                'description' => 'The value to set. Type depends on the field: string for text/textarea, object {"title","url","target"} for link fields, integer for image/file attachment IDs, array of row objects for repeaters.',
                            ],
                            'field_key' => [
                                'type'        => 'string',
                                'description' => 'Optional. The ACF field key (e.g. field_64f6f6a5305a1). Providing this improves reliability for update_field().',
                            ],
                        ],
                        'required' => [ 'field_name', 'value' ],
                    ],
                ],
            ],
            'required' => [ 'fields' ],
        ],
        'execute_callback' => function( $input ) {
            if ( ! function_exists( 'update_field' ) ) {
                return new WP_Error( 'acf_missing', 'ACF Pro is not active.' );
            }

            $results = [];

            foreach ( $input['fields'] as $item ) {
                $field_name = sanitize_text_field( $item['field_name'] );
                $value      = $item['value'];
                // Use field key if provided (more reliable), otherwise field name
                $field_id   = ! empty( $item['field_key'] ) ? sanitize_text_field( $item['field_key'] ) : $field_name;

                // update_field with 'options' as post_id handles the options_ prefix
                // and correct serialisation for all field types
                $updated = update_field( $field_id, $value, 'options' );

                $results[] = [
                    'field_name' => $field_name,
                    'updated'    => (bool) $updated,
                ];
            }

            $success_count = count( array_filter( $results, fn( $r ) => $r['updated'] ) );
            $fail_count    = count( $results ) - $success_count;

            return [
                'total'   => count( $results ),
                'updated' => $success_count,
                'failed'  => $fail_count,
                'results' => $results,
            ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

});
