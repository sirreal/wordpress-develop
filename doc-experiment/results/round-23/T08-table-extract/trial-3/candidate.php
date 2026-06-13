<?php
function table_to_array( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    if ( ! $processor->next_tag( 'TABLE' ) ) {
        return array();
    }

    $table_depth   = $processor->get_current_depth();
    $rows          = array();
    $current_row   = null;
    $row_depth     = null;
    $current_cell  = null;
    $cell_depth    = null;
    $text_elements = array(
        'SCRIPT'   => true,
        'STYLE'    => true,
        'TEXTAREA' => true,
        'TITLE'    => true,
    );

    while ( $processor->next_token() ) {
        if ( $processor->get_current_depth() < $table_depth ) {
            break;
        }

        $token_type = $processor->get_token_type();
        $token_name = $processor->get_token_name();

        if ( '#tag' === $token_type ) {
            if ( $processor->is_tag_closer() ) {
                if ( null !== $current_cell && $token_name === $current_cell['tag'] && $processor->get_current_depth() < $cell_depth ) {
                    $current_row[] = $current_cell['text'];
                    $current_cell  = null;
                    $cell_depth    = null;
                    continue;
                }

                if ( null !== $current_row && 'TR' === $token_name && $processor->get_current_depth() < $row_depth ) {
                    $rows[]      = $current_row;
                    $current_row = null;
                    $row_depth   = null;
                }

                continue;
            }

            if ( 'TR' === $token_name ) {
                if ( null === $current_row ) {
                    $current_row = array();
                    $row_depth   = $processor->get_current_depth();
                }
                continue;
            }

            if ( ( 'TD' === $token_name || 'TH' === $token_name ) && null !== $current_row && null === $current_cell ) {
                $current_cell = array(
                    'tag'  => $token_name,
                    'text' => '',
                );
                $cell_depth = $processor->get_current_depth();
                continue;
            }

            if ( null !== $current_cell && isset( $text_elements[ $token_name ] ) ) {
                $current_cell['text'] .= $processor->get_modifiable_text();
            }

            continue;
        }

        if ( null !== $current_cell && '#text' === $token_type ) {
            $current_cell['text'] .= $processor->get_modifiable_text();
        }
    }

    return $rows;
}
