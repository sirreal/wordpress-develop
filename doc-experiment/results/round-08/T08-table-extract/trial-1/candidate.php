<?php

function table_to_array( string $html ): array {
    // Try to create a fragment processor
    $processor = WP_HTML_Processor::create_fragment( $html );
    
    // If processor creation failed, return empty array
    if ( null === $processor ) {
        return array();
    }
    
    // Find the first TABLE element
    if ( ! $processor->next_tag( 'TABLE' ) ) {
        return array();
    }
    
    $rows = array();
    $table_depth = $processor->get_current_depth();
    
    // Iterate through tokens within the table
    while ( $processor->next_token() ) {
        $current_depth = $processor->get_current_depth();
        $token_type = $processor->get_token_type();
        $token_name = $processor->get_token_name();
        
        // Stop when we exit the table (depth drops below table depth)
        if ( $current_depth < $table_depth ) {
            break;
        }
        
        // Look for opening TR elements
        if ( 'TR' === $token_name && '#tag' === $token_type && ! $processor->is_tag_closer() ) {
            $row = array();
            $row_depth = $processor->get_current_depth();
            $current_cell_text = '';
            $in_cell = false;
            $cell_depth = 0;
            
            // Iterate through tokens within the row
            while ( $processor->next_token() ) {
                $current_depth = $processor->get_current_depth();
                $token_type = $processor->get_token_type();
                $token_name = $processor->get_token_name();
                
                // Stop when we exit the row (depth equals row_depth means we're at row closer)
                if ( $current_depth <= $row_depth ) {
                    break;
                }
                
                // Look for opening TD and TH elements
                if ( ( 'TD' === $token_name || 'TH' === $token_name ) && '#tag' === $token_type && ! $processor->is_tag_closer() ) {
                    // If we were already in a cell, save it
                    if ( $in_cell ) {
                        $row[] = $current_cell_text;
                    }
                    // Start a new cell
                    $in_cell = true;
                    $current_cell_text = '';
                    $cell_depth = $current_depth;
                } elseif ( 'TD' === $token_name || 'TH' === $token_name ) {
                    // Cell closing tag - check if we should end the cell
                    if ( $in_cell && $current_depth <= $cell_depth && $processor->is_tag_closer() ) {
                        // The cell is closing
                        $in_cell = false;
                    }
                } elseif ( '#text' === $token_type && $in_cell ) {
                    // Collect text content while in a cell
                    $current_cell_text .= $processor->get_modifiable_text();
                }
            }
            
            // Save any remaining cell
            if ( $in_cell ) {
                $row[] = $current_cell_text;
            }
            
            // Add row to results
            if ( ! empty( $row ) ) {
                $rows[] = $row;
            }
        }
    }
    
    return $rows;
}
