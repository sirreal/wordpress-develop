<?php

function table_to_array( string $html ): array {
    $processor = new WP_HTML_Tag_Processor( $html );
    
    // Find the first TABLE element
    if ( ! $processor->next_tag( array( 'tag_name' => 'table' ) ) ) {
        return array();
    }
    
    $rows = array();
    $current_row = array();
    $current_cell_text = '';
    $in_row = false;
    $in_cell = false;
    
    // Continue scanning from the TABLE tag onwards
    while ( $processor->next_token() ) {
        $token_type = $processor->get_token_type();
        $tag_name = $processor->get_tag();
        
        // Handle opening tags
        if ( '#tag' === $token_type && ! $processor->is_tag_closer() ) {
            if ( 'TR' === $tag_name ) {
                // Starting a new row
                $in_row = true;
                $current_row = array();
            } elseif ( ( 'TD' === $tag_name || 'TH' === $tag_name ) && $in_row ) {
                // Starting a new cell
                $in_cell = true;
                $current_cell_text = '';
            }
        }
        // Handle closing tags
        elseif ( '#tag' === $token_type && $processor->is_tag_closer() ) {
            if ( 'TD' === $tag_name || 'TH' === $tag_name ) {
                // End of cell - add accumulated text to current row
                if ( $in_cell ) {
                    $current_row[] = $current_cell_text;
                    $current_cell_text = '';
                    $in_cell = false;
                }
            } elseif ( 'TR' === $tag_name ) {
                // End of row - if we were in a cell, close it first
                if ( $in_cell ) {
                    $current_row[] = $current_cell_text;
                    $current_cell_text = '';
                    $in_cell = false;
                }
                // Add the row to results
                if ( ! empty( $current_row ) || $in_row ) {
                    $rows[] = $current_row;
                }
                $current_row = array();
                $in_row = false;
            } elseif ( 'TABLE' === $tag_name ) {
                // End of table - stop processing
                break;
            }
        }
        // Handle text nodes
        elseif ( '#text' === $token_type && $in_cell ) {
            $current_cell_text .= $processor->get_modifiable_text();
        }
    }
    
    // Handle any remaining open row/cell at end of document
    if ( $in_cell ) {
        $current_row[] = $current_cell_text;
    }
    if ( $in_row && ! empty( $current_row ) ) {
        $rows[] = $current_row;
    }
    
    return $rows;
}
