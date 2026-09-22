<?php

function table_to_array( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    
    if ( null === $processor ) {
        return array();
    }
    
    // Find the first TABLE element
    if ( ! $processor->next_tag( array( 'tag_name' => 'TABLE' ) ) ) {
        return array();
    }
    
    $result = array();
    $table_depth = $processor->get_current_depth();
    
    // Walk through all tokens inside the table
    while ( $processor->next_token() ) {
        $current_depth = $processor->get_current_depth();
        
        // Stop when we exit the table
        if ( $current_depth < $table_depth ) {
            break;
        }
        
        $token_type = $processor->get_token_type();
        $token_name = $processor->get_token_name();
        
        // Process TR (table row) opening tags
        if ( 'TR' === $token_name && ! $processor->is_tag_closer() ) {
            $row = array();
            $tr_depth = $processor->get_current_depth();
            
            // Walk through tokens in this row
            while ( $processor->next_token() ) {
                $depth = $processor->get_current_depth();
                
                // Exit row when we close the TR
                if ( 'TR' === $processor->get_token_name() && $processor->is_tag_closer() ) {
                    break;
                }
                
                // Only process direct children of the TR
                if ( $depth <= $tr_depth ) {
                    continue;
                }
                
                $tag_name = $processor->get_token_name();
                
                // Process TD and TH opening tags
                if ( ( 'TD' === $tag_name || 'TH' === $tag_name ) && ! $processor->is_tag_closer() ) {
                    $cell_text = '';
                    $cell_depth = $processor->get_current_depth();
                    
                    // Collect all text nodes inside this cell
                    while ( $processor->next_token() ) {
                        $token_depth = $processor->get_current_depth();
                        $token_tag_name = $processor->get_token_name();
                        $is_closer = $processor->is_tag_closer();
                        
                        // Exit cell when we close the TD/TH
                        if ( ( 'TD' === $token_tag_name || 'TH' === $token_tag_name ) && $is_closer ) {
                            break;
                        }
                        
                        // Only process text at the cell level or deeper
                        if ( '#text' === $processor->get_token_type() && $token_depth > $cell_depth ) {
                            $cell_text .= $processor->get_modifiable_text();
                        }
                    }
                    
                    $row[] = $cell_text;
                }
            }
            
            if ( ! empty( $row ) ) {
                $result[] = $row;
            }
        }
    }
    
    return $result;
}
