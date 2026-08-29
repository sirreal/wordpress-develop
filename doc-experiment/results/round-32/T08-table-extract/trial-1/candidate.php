<?php
function table_to_array( string $html ): array {
    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor || ! $processor->next_tag( 'TABLE' ) ) {
        return array();
    }

    $table_depth   = $processor->get_current_depth();
    $rows          = array();
    $current_row   = null;
    $current_cell  = null;
    $completed     = false;

    while ( $processor->next_token() ) {
        if ( $processor->get_current_depth() < $table_depth ) {
            $completed = true;
            break;
        }

        if ( '#text' === $processor->get_token_type() ) {
            if ( null !== $current_cell ) {
                $current_cell .= $processor->get_modifiable_text();
            }
            continue;
        }

        if ( '#tag' !== $processor->get_token_type() ) {
            continue;
        }

        $tag = $processor->get_tag();
        if ( null === $tag ) {
            continue;
        }

        if ( $processor->is_tag_closer() ) {
            if ( 'TD' === $tag || 'TH' === $tag ) {
                if ( null !== $current_row && null !== $current_cell ) {
                    $current_row[] = $current_cell;
                    $current_cell  = null;
                }
                continue;
            }

            if ( 'TR' === $tag ) {
                if ( null !== $current_row ) {
                    if ( null !== $current_cell ) {
                        $current_row[] = $current_cell;
                        $current_cell  = null;
                    }
                    $rows[]      = $current_row;
                    $current_row = null;
                }
            }

            continue;
        }

        if ( 'TR' === $tag ) {
            if ( null !== $current_row ) {
                if ( null !== $current_cell ) {
                    $current_row[] = $current_cell;
                    $current_cell  = null;
                }
                $rows[] = $current_row;
            }
            $current_row = array();
            continue;
        }

        if ( ( 'TD' === $tag || 'TH' === $tag ) && null !== $current_row ) {
            if ( null !== $current_cell ) {
                $current_row[] = $current_cell;
            }
            $current_cell = '';
        }
    }

    if ( ! $completed && null !== $processor->get_last_error() ) {
        return array();
    }

    if ( ! $completed ) {
        if ( null !== $current_row ) {
            if ( null !== $current_cell ) {
                $current_row[] = $current_cell;
            }
            $rows[] = $current_row;
        }
    }

    return $rows;
}
