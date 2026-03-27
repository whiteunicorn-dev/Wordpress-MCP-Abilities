<?php
/**
 * WUA MCP Abilities
 *
 * @package     wua-mcp-abilities
 * Plugin Name: WUA MCP Abilities
 * Description: Exposes abilities to AI via MCP
 * Version: 2.4
 */

# Table of Contents
# ---------------------------------------------------------
# 1.  CORS & OPTIONS Headers
# 2.  Required Files
# 3.  Register Category
# 4.  Register Abilities
#       4.1   create-post              - Create a new post
#       4.2   get-posts                - Retrieve posts
#       4.3   get-post                 - Fetch a single post
#       4.4   update-post              - Edit a post
#       4.5   delete-post              - Trash a post
#       4.6   get-pages                - List pages
#       4.7   get-post-meta            - Read post meta
#       4.8   update-post-meta         - Write post meta
#       4.9   bulk-update-post-meta    - Bulk update post meta
#       4.10  search-post-meta         - Search across all post meta values
#       4.11  get-users                - List users
#       4.12  create-user              - Add a user
#       4.13  update-user-role         - Change user role
#       4.14  get-site-info            - Get site info
#       4.15  get-active-plugins       - List active plugins
#       4.16  get-options              - Read WordPress options
#       4.17  get-post-types           - List post types
#       4.18  search-media             - Search media library
#       4.19  set-featured-image       - Set featured image
#       4.20  clear-cache              - Clear cache
#       4.21  get-media-missing-alt    - Find images missing alt text
#       4.22  generate-alt-text        - Generate alt text via Claude API
#       4.23  get-image-thumbnail      - Get base64 thumbnail
#       4.24  media-upload-from-url    - Sideload images from URL
#       4.25  media-upload-base64      - Upload base64 encoded image
#       4.26  run-wp-cron              - Trigger WP Cron
# ---------------------------------------------------------


# =============================================================================
# 1. CORS & OPTIONS Headers
# =============================================================================

add_action( 'rest_api_init', function() {
    remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
    add_filter( 'rest_pre_serve_request', function( $value ) {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ( $origin === 'null' || strpos( $origin, 'http://localhost' ) === 0 || strpos( $origin, 'https://localhost' ) === 0 ) {
            header( 'Access-Control-Allow-Origin: ' . ( $origin ?: 'null' ) );
            header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
            header( 'Access-Control-Allow-Credentials: true' );
            header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce, Content-Disposition' );
        } else {
            header( 'Access-Control-Allow-Origin: *' );
        }
        return $value;
    });
}, 15 );

add_action( 'init', function() {
    if ( $_SERVER['REQUEST_METHOD'] === 'OPTIONS' ) {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ( $origin === 'null' || strpos( $origin, 'http://localhost' ) === 0 ) {
            header( 'Access-Control-Allow-Origin: ' . ( $origin ?: 'null' ) );
            header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
            header( 'Access-Control-Allow-Credentials: true' );
            header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce, Content-Disposition' );
            header( 'HTTP/1.1 200 OK' );
            exit();
        }
    }
});


# =============================================================================
# 2. Required Files
# =============================================================================

require_once plugin_dir_path( __FILE__ ) . 'wua-mcp-acf-abilities.php';
require_once plugin_dir_path( __FILE__ ) . 'wua-mcp-filesystem-abilities.php';
require_once plugin_dir_path( __FILE__ ) . 'wua-mcp-temp-alttext.php';


# =============================================================================
# 3. Register Category
# =============================================================================

add_action( 'wp_abilities_api_categories_init', function() {
    wp_register_ability_category( 'wua-mcp-abilities', [
        'label'       => 'WUA MCP Abilities',
        'description' => 'Custom abilities for WUA MCP integration',
    ]);
});


# =============================================================================
# 4. Register Abilities
# =============================================================================

add_action( 'wp_abilities_api_init', function() {

    // -------------------------------------------------------------------------
    // 4.1 Create Post
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/create-post', [
        'label'       => 'Create Post',
        'description' => 'Create a new post.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'title'     => [ 'type' => 'string' ],
                'content'   => [ 'type' => 'string' ],
                'status'    => [ 'type' => 'string', 'default' => 'draft' ],
                'post_type' => [ 'type' => 'string', 'default' => 'post' ],
                'fields'    => [ 'type' => 'object' ],
            ],
            'required' => [ 'title', 'content' ],
        ],
        'execute_callback' => function( $input ) {
            $post_id = wp_insert_post([
                'post_title'   => sanitize_text_field( $input['title'] ),
                'post_content' => wp_kses_post( $input['content'] ),
                'post_status'  => $input['status'] ?? 'draft',
                'post_type'    => ! empty( $input['post_type'] ) ? sanitize_text_field( $input['post_type'] ) : 'post',
            ]);
            if ( is_wp_error( $post_id ) ) return $post_id;
            $fields_updated = [];
            if ( ! empty( $input['fields'] ) && is_array( $input['fields'] ) && function_exists( 'update_field' ) ) {
                foreach ( $input['fields'] as $k => $v ) {
                    update_field( sanitize_text_field( $k ), $v, $post_id );
                    $fields_updated[] = $k;
                }
            }
            return [ 'id' => $post_id, 'fields_updated' => $fields_updated ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.2 Get Posts
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/get-posts', [
        'label'       => 'Get Recent Posts',
        'description' => 'Retrieve posts.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'count'       => [ 'type' => 'integer', 'default' => 100 ],
                'post_type'   => [ 'type' => 'string', 'default' => 'post' ],
                'search'      => [ 'type' => 'string' ],
                'post_status' => [ 'type' => 'string', 'default' => 'any' ],
            ],
        ],
        'execute_callback' => function( $input ) {
            $args = [
                'numberposts' => $input['count'] ?? 100,
                'post_type'   => ! empty( $input['post_type'] ) ? sanitize_text_field( $input['post_type'] ) : 'post',
                'post_status' => ! empty( $input['post_status'] ) ? sanitize_text_field( $input['post_status'] ) : 'any',
            ];
            if ( ! empty( $input['search'] ) ) $args['s'] = sanitize_text_field( $input['search'] );
            $posts = get_posts( $args );
            return array_map( fn($p) => [
                'id'        => $p->ID,
                'title'     => $p->post_title,
                'status'    => $p->post_status,
                'post_type' => $p->post_type,
                'url'       => get_permalink( $p->ID ),
            ], $posts );
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.3 Get Post
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/get-post', [
        'label'       => 'Get Post',
        'description' => 'Fetch a post.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'id'        => [ 'type' => 'integer' ],
                'slug'      => [ 'type' => 'string' ],
                'post_type' => [ 'type' => 'string', 'default' => 'post' ],
                'fields'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
            ],
        ],
        'execute_callback' => function( $input ) {
            if ( ! empty( $input['id'] ) ) {
                $post = get_post( $input['id'] );
            } elseif ( ! empty( $input['slug'] ) ) {
                $posts = get_posts([
                    'name'        => sanitize_title( $input['slug'] ),
                    'post_type'   => ! empty( $input['post_type'] ) ? sanitize_text_field( $input['post_type'] ) : 'post',
                    'post_status' => 'any',
                    'numberposts' => 1,
                ]);
                $post = $posts[0] ?? null;
            } else {
                return new WP_Error( 'missing_param', 'Provide id or slug.' );
            }
            if ( ! $post ) return new WP_Error( 'not_found', 'Post not found.' );
            $result = [
                'id'      => $post->ID,
                'title'   => $post->post_title,
                'content' => $post->post_content,
                'status'  => $post->post_status,
                'type'    => $post->post_type,
                'url'     => get_permalink( $post->ID ),
                'date'    => $post->post_date,
            ];
            if ( ! empty( $input['fields'] ) && function_exists( 'get_field' ) ) {
                $result['fields'] = [];
                foreach ( $input['fields'] as $f ) {
                    $result['fields'][ $f ] = get_field( sanitize_text_field( $f ), $post->ID );
                }
            }
            return $result;
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.4 Update Post
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/update-post', [
        'label'       => 'Update Post',
        'description' => 'Edit a post.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'id'      => [ 'type' => 'integer' ],
                'title'   => [ 'type' => 'string' ],
                'content' => [ 'type' => 'string' ],
                'status'  => [ 'type' => 'string' ],
                'fields'  => [ 'type' => 'object' ],
            ],
            'required' => [ 'id' ],
        ],
        'execute_callback' => function( $input ) {
            $args = [ 'ID' => $input['id'] ];
            if ( ! empty( $input['title'] ) )   $args['post_title']   = sanitize_text_field( $input['title'] );
            if ( ! empty( $input['content'] ) )  $args['post_content'] = wp_kses_post( $input['content'] );
            if ( ! empty( $input['status'] ) )   $args['post_status']  = sanitize_text_field( $input['status'] );
            $result = wp_update_post( $args, true );
            if ( is_wp_error( $result ) ) return $result;
            $fields_updated = [];
            if ( ! empty( $input['fields'] ) && function_exists( 'update_field' ) ) {
                foreach ( $input['fields'] as $k => $v ) {
                    update_field( sanitize_text_field( $k ), $v, $result );
                    $fields_updated[] = $k;
                }
            }
            return [ 'updated' => true, 'id' => $result, 'fields_updated' => $fields_updated ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.5 Delete Post
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/delete-post', [
        'label'       => 'Delete Post',
        'description' => 'Trash a post.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [ 'id' => [ 'type' => 'integer' ] ],
            'required'   => [ 'id' ],
        ],
        'execute_callback' => function( $input ) {
            $r = wp_trash_post( $input['id'] );
            if ( ! $r ) return new WP_Error( 'trash_failed', 'Could not trash.' );
            return [ 'trashed' => true, 'id' => $input['id'] ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.6 Get Pages
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/get-pages', [
        'label'       => 'Get Pages',
        'description' => 'List pages.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [ 'count' => [ 'type' => 'integer', 'default' => 100 ] ],
        ],
        'execute_callback' => function( $input ) {
            $pages = get_posts([
                'post_type'   => 'page',
                'numberposts' => $input['count'] ?? 100,
                'post_status' => 'any',
            ]);
            return array_map( fn($p) => [
                'id'     => $p->ID,
                'title'  => $p->post_title,
                'status' => $p->post_status,
                'url'    => get_permalink( $p->ID ),
            ], $pages );
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.7 Get Post Meta
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/get-post-meta', [
        'label'       => 'Get Post Meta',
        'description' => 'Read meta.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'id'  => [ 'type' => 'integer' ],
                'key' => [ 'type' => 'string' ],
            ],
            'required' => [ 'id' ],
        ],
        'execute_callback' => function( $input ) {
            $key  = ! empty( $input['key'] ) ? $input['key'] : '';
            $meta = get_post_meta( $input['id'], $key, true );
            return [ 'id' => $input['id'], 'key' => $key ?: 'all', 'value' => $meta ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.8 Update Post Meta
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/update-post-meta', [
        'label'       => 'Update Post Meta',
        'description' => 'Write meta.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'id'    => [ 'type' => 'integer' ],
                'key'   => [ 'type' => 'string' ],
                'value' => [ 'type' => 'string' ],
            ],
            'required' => [ 'id', 'key', 'value' ],
        ],
        'execute_callback' => function( $input ) {
            $r = update_post_meta( $input['id'], sanitize_text_field( $input['key'] ), sanitize_text_field( $input['value'] ) );
            return [ 'updated' => (bool) $r, 'id' => $input['id'], 'key' => $input['key'] ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.9 Bulk Update Post Meta
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/bulk-update-post-meta', [
        'label'       => 'Bulk Update Post Meta',
        'description' => 'Bulk update meta.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'post_type'   => [ 'type' => 'string' ],
                'post_status' => [ 'type' => 'string', 'default' => 'publish' ],
                'post_ids'    => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
                'mappings'    => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'target_key'   => [ 'type' => 'string' ],
                            'source_key'   => [ 'type' => 'string' ],
                            'static_value' => [ 'type' => 'string' ],
                            'skip_if_set'  => [ 'type' => 'boolean' ],
                        ],
                        'required' => [ 'target_key' ],
                    ],
                ],
            ],
            'required' => [ 'mappings' ],
        ],
        'execute_callback' => function( $input ) {
            $mappings    = $input['mappings'] ?? [];
            $post_status = sanitize_text_field( $input['post_status'] ?? 'publish' );
            if ( ! empty( $input['post_ids'] ) ) {
                $post_ids = array_map( 'intval', $input['post_ids'] );
            } elseif ( ! empty( $input['post_type'] ) ) {
                $post_ids = get_posts([
                    'post_type'   => sanitize_text_field( $input['post_type'] ),
                    'post_status' => $post_status,
                    'numberposts' => -1,
                    'fields'      => 'ids',
                ]);
            } else {
                return new WP_Error( 'missing_target', 'Provide post_type or post_ids.' );
            }
            if ( empty( $post_ids ) ) return [ 'updated' => 0, 'skipped' => 0, 'results' => [] ];
            $results = []; $updated = 0; $skipped = 0;
            foreach ( $post_ids as $post_id ) {
                $pr = [ 'id' => $post_id, 'changes' => [] ];
                foreach ( $mappings as $m ) {
                    $tk = sanitize_text_field( $m['target_key'] );
                    $sk = ! empty( $m['source_key'] ) ? sanitize_text_field( $m['source_key'] ) : null;
                    $sv = $m['static_value'] ?? null;
                    if ( ! empty( $m['skip_if_set'] ) && ! empty( get_post_meta( $post_id, $tk, true ) ) ) { $skipped++; continue; }
                    if ( $sk )         { $value = trim( get_post_meta( $post_id, $sk, true ) ); }
                    elseif ( $sv !== null ) { $value = $sv; }
                    else               { $skipped++; continue; }
                    if ( $value === '' ) { $skipped++; continue; }
                    update_post_meta( $post_id, $tk, sanitize_text_field( $value ) );
                    $pr['changes'][] = [ 'key' => $tk, 'status' => 'updated' ];
                    $updated++;
                }
                $results[] = $pr;
            }
            return [ 'total_posts' => count( $post_ids ), 'updated' => $updated, 'skipped' => $skipped, 'results' => $results ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.10 Search Post Meta
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/search-post-meta', [
        'label'       => 'Search Post Meta',
        'description' => 'Search across all post meta values for a string. Useful for finding which post/page contains specific ACF field content.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'search'      => [ 'type' => 'string', 'description' => 'The string to search for in post meta values.' ],
                'post_type'   => [ 'type' => 'string', 'description' => 'Optional. Limit to a specific post type (e.g. page, post, app_solution). Defaults to any.' ],
                'post_status' => [ 'type' => 'string', 'description' => 'Optional. Post status filter. Defaults to any.' ],
            ],
            'required' => [ 'search' ],
        ],
        'execute_callback' => function( $input ) {
            global $wpdb;
            $search      = sanitize_text_field( $input['search'] );
            $post_type   = ! empty( $input['post_type'] )   ? sanitize_text_field( $input['post_type'] )   : '';
            $post_status = ! empty( $input['post_status'] ) ? sanitize_text_field( $input['post_status'] ) : 'any';

            $meta_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_value LIKE %s",
                '%' . $wpdb->esc_like( $search ) . '%'
            ) );

            if ( empty( $meta_rows ) ) return [ 'found' => 0, 'results' => [] ];

            $post_ids = array_unique( array_column( $meta_rows, 'post_id' ) );
            $posts    = get_posts([
                'post__in'    => $post_ids,
                'post_type'   => $post_type ?: 'any',
                'post_status' => $post_status,
                'numberposts' => -1,
            ]);

            if ( empty( $posts ) ) return [ 'found' => 0, 'results' => [] ];

            $results = [];
            foreach ( $posts as $post ) {
                $matching_keys = [];
                foreach ( $meta_rows as $row ) {
                    if ( (int) $row->post_id !== $post->ID ) continue;
                    if ( strpos( $row->meta_key, '_' ) === 0 ) continue; // skip internal WP keys
                    $matching_keys[] = [ 'meta_key' => $row->meta_key, 'meta_value' => $row->meta_value ];
                }
                if ( empty( $matching_keys ) ) continue;
                $results[] = [
                    'id'        => $post->ID,
                    'title'     => $post->post_title,
                    'post_type' => $post->post_type,
                    'status'    => $post->post_status,
                    'url'       => get_permalink( $post->ID ),
                    'matches'   => $matching_keys,
                ];
            }
            return [ 'found' => count( $results ), 'results' => $results ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.11 Get Users
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/get-users', [
        'label'       => 'Get Users',
        'description' => 'List users.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'count' => [ 'type' => 'integer', 'default' => 100 ],
                'role'  => [ 'type' => 'string' ],
            ],
        ],
        'execute_callback' => function( $input ) {
            $args = [ 'number' => $input['count'] ?? 100 ];
            if ( ! empty( $input['role'] ) ) $args['role'] = sanitize_text_field( $input['role'] );
            $users = get_users( $args );
            return array_map( fn($u) => [
                'id'    => $u->ID,
                'name'  => $u->display_name,
                'email' => $u->user_email,
                'roles' => $u->roles,
            ], $users );
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.12 Create User
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/create-user', [
        'label'       => 'Create User',
        'description' => 'Add user.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'username' => [ 'type' => 'string' ],
                'email'    => [ 'type' => 'string' ],
                'password' => [ 'type' => 'string' ],
                'role'     => [ 'type' => 'string', 'default' => 'subscriber' ],
            ],
            'required' => [ 'username', 'email', 'password' ],
        ],
        'execute_callback' => function( $input ) {
            $r = wp_create_user( sanitize_user( $input['username'] ), $input['password'], sanitize_email( $input['email'] ) );
            if ( is_wp_error( $r ) ) return $r;
            $role = ! empty( $input['role'] ) ? sanitize_text_field( $input['role'] ) : 'subscriber';
            wp_update_user([ 'ID' => $r, 'role' => $role ]);
            return [ 'created' => true, 'id' => $r, 'role' => $role ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.13 Update User Role
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/update-user-role', [
        'label'       => 'Update User Role',
        'description' => 'Change role.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'id'   => [ 'type' => 'integer' ],
                'role' => [ 'type' => 'string' ],
            ],
            'required' => [ 'id', 'role' ],
        ],
        'execute_callback' => function( $input ) {
            $u = new WP_User( $input['id'] );
            if ( ! $u->exists() ) return new WP_Error( 'not_found', 'User not found.' );
            $u->set_role( sanitize_text_field( $input['role'] ) );
            return [ 'updated' => true, 'id' => $input['id'], 'role' => $input['role'] ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.14 Get Site Info
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/get-site-info', [
        'label'       => 'Get Site Info',
        'description' => 'Site info.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [ 'type' => 'object', 'properties' => [] ],
        'execute_callback' => function( $input ) {
            $t = wp_get_theme();
            return [
                'name'         => get_bloginfo('name'),
                'description'  => get_bloginfo('description'),
                'url'          => get_bloginfo('url'),
                'admin_email'  => get_bloginfo('admin_email'),
                'wp_version'   => get_bloginfo('version'),
                'language'     => get_bloginfo('language'),
                'active_theme' => $t->get('Name'),
                'theme_version'=> $t->get('Version'),
            ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.15 Get Active Plugins
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/get-active-plugins', [
        'label'       => 'Get Active Plugins',
        'description' => 'List plugins.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [ 'type' => 'object', 'properties' => [] ],
        'execute_callback' => function( $input ) {
            if ( ! function_exists('get_plugins') ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
            $all    = get_plugins();
            $active = get_option('active_plugins', []);
            $result = [];
            foreach ( $active as $f ) {
                if ( isset($all[$f]) ) {
                    $p        = $all[$f];
                    $result[] = [ 'name' => $p['Name'], 'version' => $p['Version'], 'author' => $p['Author'], 'file' => $f ];
                }
            }
            return $result;
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.16 Get Options
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/get-options', [
        'label'       => 'Get Options',
        'description' => 'Read options.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [ 'keys' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ] ],
            'required'   => [ 'keys' ],
        ],
        'execute_callback' => function( $input ) {
            $r = [];
            foreach ( $input['keys'] as $k ) {
                $r[ sanitize_text_field($k) ] = get_option( sanitize_text_field($k) );
            }
            return $r;
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.17 Get Post Types
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/get-post-types', [
        'label'       => 'Get Post Types',
        'description' => 'List post types.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [ 'public' => [ 'type' => 'integer' ] ],
        ],
        'execute_callback' => function( $input ) {
            $args = [];
            if ( isset($input['public']) ) $args['public'] = (bool) $input['public'];
            $pts = get_post_types( $args, 'objects' );
            return array_values( array_map( fn($pt) => [
                'name'        => $pt->name,
                'label'       => $pt->label,
                'public'      => $pt->public,
                'hierarchical'=> $pt->hierarchical,
                'has_archive' => $pt->has_archive,
                'rest_base'   => $pt->rest_base ?? $pt->name,
            ], $pts ) );
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.18 Search Media
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/search-media', [
        'label'       => 'Search Media',
        'description' => 'Search media library.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'search' => [ 'type' => 'string' ],
                'count'  => [ 'type' => 'integer', 'default' => 10 ],
            ],
            'required' => [ 'search' ],
        ],
        'execute_callback' => function( $input ) {
            $q = new WP_Query([
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => $input['count'] ?? 10,
                's'              => sanitize_text_field($input['search']),
            ]);
            if ( empty($q->posts) ) return [];
            return array_map( fn($p) => [
                'id'       => $p->ID,
                'title'    => $p->post_title,
                'filename' => basename(get_attached_file($p->ID)),
                'url'      => wp_get_attachment_url($p->ID),
                'mime'     => $p->post_mime_type,
            ], $q->posts );
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.19 Set Featured Image
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/set-featured-image', [
        'label'       => 'Set Featured Image',
        'description' => 'Set featured image.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'post_id'       => [ 'type' => 'integer' ],
                'attachment_id' => [ 'type' => 'integer' ],
            ],
            'required' => [ 'post_id', 'attachment_id' ],
        ],
        'execute_callback' => function( $input ) {
            $r = set_post_thumbnail($input['post_id'], $input['attachment_id']);
            return [ 'updated' => (bool)$r ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.20 Clear Cache
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/clear-cache', [
        'label'       => 'Clear Cache',
        'description' => 'Clear cache.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [ 'type' => 'object', 'properties' => [] ],
        'execute_callback' => function( $input ) {
            $cleared = [];
            if ( function_exists('rocket_clean_domain') ) { rocket_clean_domain(); $cleared[] = 'WP Rocket'; }
            wp_cache_flush();
            $cleared[] = 'Object Cache';
            return [ 'cleared' => $cleared ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.21 Get Media Missing Alt
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/get-media-missing-alt', [
        'label'       => 'Get Media Missing Alt',
        'description' => 'Images missing alt.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [ 'count' => [ 'type' => 'integer', 'default' => 100 ] ],
        ],
        'execute_callback' => function( $input ) {
            $attachments = get_posts([
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => $input['count'] ?? 100,
                'post_mime_type' => 'image',
            ]);
            $missing = [];
            foreach ( $attachments as $a ) {
                if ( empty(get_post_meta($a->ID, '_wp_attachment_image_alt', true)) ) {
                    $missing[] = [ 'id' => $a->ID, 'title' => $a->post_title, 'url' => wp_get_attachment_url($a->ID) ];
                }
            }
            return [ 'total_checked' => count($attachments), 'missing_count' => count($missing), 'images' => $missing ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.22 Generate Alt Text
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/generate-alt-text', [
        'label'       => 'Generate Alt Text',
        'description' => 'Generate alt text via Claude API.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'image_base64'      => [ 'type' => 'string' ],
                'mime_type'         => [ 'type' => 'string' ],
                'api_key'           => [ 'type' => 'string' ],
                'context'           => [ 'type' => 'string' ],
                'generate_filename' => [ 'type' => 'boolean' ],
                'file_extension'    => [ 'type' => 'string' ],
            ],
            'required' => [ 'image_base64', 'mime_type', 'api_key' ],
        ],
        'execute_callback' => function( $input ) {
            $gen  = isset($input['generate_filename']) && $input['generate_filename'] === true;
            $ctx  = ! empty($input['context']) ? ' Context: ' . sanitize_text_field($input['context']) . '.' : '';
            $prompt = $gen
                ? 'Analyze this image and respond with a JSON object with exactly two keys: "alt_text" and "filename" (SEO-friendly, lowercase, hyphens, no extension, max 60 chars). Return ONLY valid JSON.' . $ctx
                : 'Write concise alt text under 125 chars. Not starting with Image of or Photo of. Return only the alt text.' . $ctx;
            $payload = [
                'model'      => 'claude-opus-4-5',
                'max_tokens' => $gen ? 200 : 150,
                'messages'   => [[ 'role' => 'user', 'content' => [
                    [ 'type' => 'image', 'source' => [ 'type' => 'base64', 'media_type' => $input['mime_type'], 'data' => $input['image_base64'] ] ],
                    [ 'type' => 'text', 'text' => $prompt ],
                ]]],
            ];
            $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
                'timeout' => 30,
                'headers' => [ 'Content-Type' => 'application/json', 'x-api-key' => sanitize_text_field($input['api_key']), 'anthropic-version' => '2023-06-01' ],
                'body'    => wp_json_encode($payload),
            ]);
            if ( is_wp_error($response) ) return new WP_Error('api_error', $response->get_error_message());
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if ( ! isset($body['content'][0]['text']) ) return new WP_Error('api_error', 'Unexpected response.');
            $text     = trim($body['content'][0]['text']);
            $alt_text = ''; $filename = '';
            if ( $gen ) {
                $clean  = preg_replace('/^```(?:json)?\s*/i', '', $text);
                $clean  = preg_replace('/\s*```$/', '', trim($clean));
                $parsed = json_decode($clean, true);
                if ( $parsed && isset($parsed['alt_text']) ) {
                    $alt_text = trim($parsed['alt_text']);
                    $ext      = ! empty($input['file_extension']) ? '.' . ltrim($input['file_extension'], '.') : '';
                    $filename = sanitize_title($parsed['filename'] ?? '') . $ext;
                } else {
                    $alt_text = $text;
                }
            } else {
                $alt_text = $text;
            }
            return [ 'alt_text' => $alt_text, 'filename' => $filename ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'show_in_rest' => true, 'annotations' => [ 'readonly' => false ], 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.23 Get Image Thumbnail
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/get-image-thumbnail', [
        'label'       => 'Get Image Thumbnail',
        'description' => 'Base64 thumbnail.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'attachment_id' => [ 'type' => 'integer' ],
                'max_size'      => [ 'type' => 'integer', 'default' => 400 ],
            ],
            'required' => [ 'attachment_id' ],
        ],
        'execute_callback' => function( $input ) {
            $id   = (int) $input['attachment_id'];
            $max  = (int) ($input['max_size'] ?? 400);
            $fp   = get_attached_file($id);
            if ( ! $fp || ! file_exists($fp) ) return new WP_Error('file_not_found', 'Not found.');
            $mime = get_post_mime_type($id);
            if ( $mime === 'image/svg+xml' ) return [ 'attachment_id' => $id, 'mime_type' => $mime, 'base64' => base64_encode(file_get_contents($fp)), 'note' => 'SVG' ];
            $img = null;
            if ( $mime === 'image/jpeg' )      $img = @imagecreatefromjpeg($fp);
            elseif ( $mime === 'image/png' )   $img = @imagecreatefrompng($fp);
            elseif ( $mime === 'image/webp' )  $img = @imagecreatefromwebp($fp);
            if ( ! $img ) return new WP_Error('gd_failed', 'GD failed.');
            $ow = imagesx($img); $oh = imagesy($img);
            if ( $ow > $oh ) { $nw = min($ow, $max); $nh = (int) round($oh * $nw / $ow); }
            else             { $nh = min($oh, $max); $nw = (int) round($ow * $nh / $oh); }
            $thumb = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($thumb, $img, 0, 0, 0, 0, $nw, $nh, $ow, $oh);
            imagedestroy($img);
            ob_start(); imagejpeg($thumb, null, 75); $data = ob_get_clean(); imagedestroy($thumb);
            return [ 'attachment_id' => $id, 'mime_type' => 'image/jpeg', 'width' => $nw, 'height' => $nh, 'base64' => base64_encode($data) ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.24 Media Upload From URL
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/media-upload-from-url', [
        'label'       => 'Media Upload From URL',
        'description' => 'Sideload images.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [ 'images' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ] ],
            'required'   => [ 'images' ],
        ],
        'execute_callback' => function( $input ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $results = [];
            foreach ( $input['images'] as $image ) {
                $url = $image['url'] ?? '';
                if ( empty($url) ) { $results[] = [ 'url' => $url, 'success' => false ]; continue; }
                $tmp = download_url($url);
                if ( is_wp_error($tmp) ) { $results[] = [ 'url' => $url, 'success' => false ]; continue; }
                $fa  = [ 'name' => basename(parse_url($url, PHP_URL_PATH)), 'tmp_name' => $tmp ];
                $aid = media_handle_sideload($fa, 0);
                if ( is_wp_error($aid) ) { @unlink($tmp); $results[] = [ 'url' => $url, 'success' => false ]; continue; }
                if ( ! empty($image['title']) )    wp_update_post([ 'ID' => $aid, 'post_title' => sanitize_text_field($image['title']) ]);
                if ( ! empty($image['alt_text']) ) update_post_meta($aid, '_wp_attachment_image_alt', sanitize_text_field($image['alt_text']));
                $results[] = [ 'url' => $url, 'success' => true, 'attachment_id' => $aid ];
            }
            return [ 'uploaded' => count(array_filter($results, fn($r) => $r['success'])), 'results' => $results ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.25 Media Upload Base64
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/media-upload-base64', [
        'label'       => 'Media Upload Base64',
        'description' => 'Upload base64 image.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'filename'  => [ 'type' => 'string' ],
                'mime_type' => [ 'type' => 'string' ],
                'data'      => [ 'type' => 'string' ],
                'title'     => [ 'type' => 'string' ],
                'alt_text'  => [ 'type' => 'string' ],
            ],
            'required' => [ 'filename', 'mime_type', 'data' ],
        ],
        'execute_callback' => function( $input ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $data = base64_decode($input['data']);
            if ( ! $data ) return new WP_Error('decode_failed', 'Decode failed.');
            $tmp = wp_tempnam($input['filename']);
            file_put_contents($tmp, $data);
            $fa  = [ 'name' => sanitize_file_name($input['filename']), 'tmp_name' => $tmp, 'type' => $input['mime_type'] ];
            $aid = media_handle_sideload($fa, 0);
            if ( is_wp_error($aid) ) { @unlink($tmp); return $aid; }
            if ( ! empty($input['title']) )    wp_update_post([ 'ID' => $aid, 'post_title' => sanitize_text_field($input['title']) ]);
            if ( ! empty($input['alt_text']) ) update_post_meta($aid, '_wp_attachment_image_alt', sanitize_text_field($input['alt_text']));
            return [ 'success' => true, 'attachment_id' => $aid, 'media_url' => wp_get_attachment_url($aid) ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // 4.26 Run WP Cron
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-abilities/run-wp-cron', [
        'label'       => 'Run WP Cron',
        'description' => 'Trigger cron.',
        'category'    => 'wua-mcp-abilities',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [ 'hook' => [ 'type' => 'string' ] ],
        ],
        'execute_callback' => function( $input ) {
            if ( ! empty($input['hook']) ) {
                do_action(sanitize_text_field($input['hook']));
                return [ 'ran' => true ];
            }
            $crons = _get_cron_array();
            $ran   = []; $now = time();
            foreach ( $crons as $ts => $hooks ) {
                if ( $ts > $now ) continue;
                foreach ( $hooks as $hook => $events ) {
                    foreach ( $events as $e ) {
                        do_action_ref_array($hook, $e['args']);
                        $ran[] = $hook;
                    }
                }
            }
            return [ 'ran' => $ran ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

});
