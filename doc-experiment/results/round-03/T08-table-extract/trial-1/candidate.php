<?php

function table_to_array( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    
    if ( ! $processor || ! $processor->next_tag( 'TABLE' ) ) {
        return array();
    }
    
    $rows = array();
    $current_row = array();
    $current_cell_text = '';
    $in_cell = false;
    $table_depth = $processor->get_current_depth();
    
    while ( $processor->next_token() ) {
        $depth = $processor->get_current_depth();
        $token_type = $processor->get_token_type();
        $token_name = $processor->get_token_name();
        $breadcrumbs = $processor->get_breadcrumbs();
        
        // Stop when we exit the table
        if ( $depth <= $table_depth ) {
            // Save any pending row before exiting
            if ( ! empty( $current_row ) ) {
                $rows[] = $current_row;
            }
            break;
        }
        
        // Opening tag
        if ( '#tag' === $token_type && ! $processor->is_tag_closer() ) {
            if ( 'TR' === $token_name ) {
                // Starting a new row - save previous row if exists
                if ( ! empty( $current_row ) ) {
                    $rows[] = $current_row;
                    $current_row = array();
                }
            } elseif ( 'TD' === $token_name || 'TH' === $token_name ) {
                // Starting a cell
                $in_cell = true;
                $current_cell_text = '';
            }
        }
        // Text node
        elseif ( '#text' === $token_type ) {
            // Only collect text if we're currently inside a cell
            if ( $in_cell ) {
                $current_cell_text .= $processor->get_modifiable_text();
            }
        }
        // Closing tag
        elseif ( '#tag' === $token_type && $processor->is_tag_closer() ) {
            if ( 'TD' === $token_name || 'TH' === $token_name ) {
                // Ending a cell - save the text
                $current_row[] = $current_cell_text;
                $current_cell_text = '';
                $in_cell = false;
            } elseif ( 'TR' === $token_name ) {
                // Row is closing - save it on next TR open or table close
                // (will be handled above when we encounter next TR or exit table)
            }
        }
    }
    
    return $rows;
}
