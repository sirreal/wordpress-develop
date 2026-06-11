<?php
function table_to_array( string $html ): array {
    // Create an HTML processor for the fragment
    $processor = WP_HTML_Processor::create_fragment( $html );
    
    if ( ! $processor ) {
        return array();
    }
    
    // Find the first TABLE element
    if ( ! $processor->next_tag( array( 'tag_name' => 'TABLE' ) ) ) {
        return array();
    }
    
    $rows = array();
    $table_depth = $processor->get_current_depth();
    
    // Process tokens inside the table
    while ( $processor->next_token() ) {
        $current_depth = $processor->get_current_depth();
        
        // Stop when we exit the table
        if ( $current_depth < $table_depth ) {
            break;
        }
        
        // Look for TR elements at table/tbody level
        $token_name = $processor->get_token_name();
        $token_type = $processor->get_token_type();
        
        // We want TR elements that are direct children of TABLE, TBODY, THEAD, or TFOOT
        if ( '#tag' === $token_type && 'TR' === $token_name && ! $processor->is_tag_closer() ) {
            $breadcrumbs = $processor->get_breadcrumbs();
            // Check if TR is a direct child of TABLE, TBODY, THEAD, or TFOOT
            $valid_parent = false;
            if ( count( $breadcrumbs ) >= 2 ) {
                $parent = $breadcrumbs[ count( $breadcrumbs ) - 2 ];
                if ( in_array( $parent, array( 'TABLE', 'TBODY', 'THEAD', 'TFOOT' ), true ) ) {
                    $valid_parent = true;
                }
            }
            
            if ( ! $valid_parent ) {
                continue;
            }
            
            // Process this row
            $row = array();
            $row_depth = $processor->get_current_depth();
            
            // Walk through the row to collect cells
            while ( $processor->next_token() ) {
                $token_depth = $processor->get_current_depth();
                
                // Stop when we exit the TR
                if ( $token_depth < $row_depth ) {
                    break;
                }
                
                $tok_name = $processor->get_token_name();
                $tok_type = $processor->get_token_type();
                
                // Look for TD or TH cells
                if ( '#tag' === $tok_type && in_array( $tok_name, array( 'TD', 'TH' ), true ) && ! $processor->is_tag_closer() ) {
                    // Check if it's a direct child of TR
                    $cell_breadcrumbs = $processor->get_breadcrumbs();
                    if ( count( $cell_breadcrumbs ) >= 2 ) {
                        $cell_parent = $cell_breadcrumbs[ count( $cell_breadcrumbs ) - 2 ];
                        if ( 'TR' === $cell_parent ) {
                            // Collect text content of this cell
                            $cell_text = '';
                            $cell_depth = $processor->get_current_depth();
                            
                            while ( $processor->next_token() ) {
                                $cell_tok_depth = $processor->get_current_depth();
                                
                                // Stop when we exit the cell
                                if ( $cell_tok_depth < $cell_depth ) {
                                    break;
                                }
                                
                                $cell_tok_type = $processor->get_token_type();
                                
                                // Accumulate text content
                                if ( '#text' === $cell_tok_type ) {
                                    $cell_text .= $processor->get_modifiable_text();
                                }
                            }
                            
                            $row[] = $cell_text;
                        }
                    }
                }
            }
            
            if ( ! empty( $row ) ) {
                $rows[] = $row;
            }
        }
    }
    
    return $rows;
}
