<?php
/**
 * WUA MCP Filesystem Abilities
 *
 * @package     wua-mcp-abilities
 * Description: Filesystem abilities for the WUA MCP plugin.
 *              Allows reading, writing, and listing files on the server.
 */

# Table of Contents
# ---------------------------------------------------------
# 1. Register Filesystem Category
# 2. Register Filesystem Abilities
#       F.1  fs-read-file       - Read the contents of a file
#       F.2  fs-write-file      - Write content to a file
#       F.3  fs-list-directory  - List files in a directory
# ---------------------------------------------------------


# Register Filesystem Category
add_action( 'wp_abilities_api_categories_init', function() {

    wp_register_ability_category( 'wua-mcp-filesystem', [
        'label'       => 'WUA MCP Filesystem Abilities',
        'description' => 'Server filesystem abilities for WUA MCP integration',
    ]);

});


# Register Filesystem Abilities
add_action( 'wp_abilities_api_init', function() {

    // -------------------------------------------------------------------------
    // TOOL F.1: Read File
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-filesystem/fs-read-file', [
        'label'       => 'Read File',
        'description' => 'Read the contents of a file on the server. Path should be relative to wp-content (e.g. themes/my-theme/acf-json/group_abc.json) or an absolute path.',
        'category'    => 'wua-mcp-filesystem',
        'input_schema' => [ 'type' => 'object', 'properties' => [ 'path' => [ 'type' => 'string' ] ], 'required' => [ 'path' ] ],
        'execute_callback' => function( $input ) {
            $path = $input['path'];
            if ( strpos( $path, '/' ) !== 0 ) $path = trailingslashit( WP_CONTENT_DIR ) . ltrim( $path, '/' );
            if ( ! file_exists( $path ) ) return new WP_Error( 'file_not_found', 'File not found: ' . $path );
            if ( ! is_readable( $path ) ) return new WP_Error( 'file_not_readable', 'File is not readable: ' . $path );
            $contents = file_get_contents( $path );
            if ( $contents === false ) return new WP_Error( 'read_failed', 'Failed to read file: ' . $path );
            return [ 'path' => $path, 'size' => filesize( $path ), 'modified' => date( 'Y-m-d H:i:s', filemtime( $path ) ), 'contents' => $contents ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // TOOL F.2: Write File
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-filesystem/fs-write-file', [
        'label'       => 'Write File',
        'description' => 'Write content to a file on the server. Path should be relative to wp-content or absolute. Will overwrite existing files.',
        'category'    => 'wua-mcp-filesystem',
        'input_schema' => [ 'type' => 'object', 'properties' => [ 'path' => [ 'type' => 'string' ], 'contents' => [ 'type' => 'string' ] ], 'required' => [ 'path', 'contents' ] ],
        'execute_callback' => function( $input ) {
            $path = $input['path'];
            if ( strpos( $path, '/' ) !== 0 ) $path = trailingslashit( WP_CONTENT_DIR ) . ltrim( $path, '/' );
            if ( strpos( realpath( dirname( $path ) ), WP_CONTENT_DIR ) === false ) return new WP_Error( 'path_not_allowed', 'Writes are only allowed within the wp-content directory.' );
            if ( ! is_writable( dirname( $path ) ) ) return new WP_Error( 'not_writable', 'Directory is not writable: ' . dirname( $path ) );
            $result = file_put_contents( $path, $input['contents'] );
            if ( $result === false ) return new WP_Error( 'write_failed', 'Failed to write file: ' . $path );
            return [ 'written' => true, 'path' => $path, 'size' => $result, 'modified' => date( 'Y-m-d H:i:s', filemtime( $path ) ) ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

    // -------------------------------------------------------------------------
    // TOOL F.3: List Directory
    // -------------------------------------------------------------------------
    wp_register_ability( 'wua-mcp-filesystem/fs-list-directory', [
        'label'       => 'List Directory',
        'description' => 'List files and folders in a directory. Path should be relative to wp-content or absolute.',
        'category'    => 'wua-mcp-filesystem',
        'input_schema' => [ 'type' => 'object', 'properties' => [ 'path' => [ 'type' => 'string' ], 'extension' => [ 'type' => 'string' ] ], 'required' => [ 'path' ] ],
        'execute_callback' => function( $input ) {
            $path = $input['path'];
            if ( strpos( $path, '/' ) !== 0 ) $path = trailingslashit( WP_CONTENT_DIR ) . ltrim( $path, '/' );
            if ( ! is_dir( $path ) ) return new WP_Error( 'not_a_directory', 'Path is not a directory: ' . $path );
            $items = scandir( $path );
            $results = [];
            foreach ( $items as $item ) {
                if ( $item === '.' || $item === '..' ) continue;
                $full_path = trailingslashit( $path ) . $item;
                $is_dir = is_dir( $full_path );
                if ( ! $is_dir && ! empty( $input['extension'] ) ) {
                    $ext = pathinfo( $item, PATHINFO_EXTENSION );
                    if ( $ext !== ltrim( $input['extension'], '.' ) ) continue;
                }
                $results[] = [ 'name' => $item, 'type' => $is_dir ? 'directory' : 'file', 'size' => $is_dir ? null : filesize( $full_path ), 'modified' => date( 'Y-m-d H:i:s', filemtime( $full_path ) ), 'path' => $full_path ];
            }
            return [ 'path' => $path, 'count' => count( $results ), 'items' => $results ];
        },
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'meta' => [ 'mcp' => [ 'public' => true, 'type' => 'tool' ] ],
    ]);

});
