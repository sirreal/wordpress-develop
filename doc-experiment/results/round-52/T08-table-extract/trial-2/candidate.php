<?php

function table_to_array( string $html ): array {
    if ( ! class_exists( 'WP_HTML_Processor' ) ) {
        return array();
    }

    $processor = WP_HTML_Processor::create_fragment( $html );
    if ( null === $processor ) {
        return array();
    }

    if ( ! $processor->next_tag( 'table' ) ) {
        return array();
    }

    $table_depth = $processor->get_current_depth();
    $rows = array();
    $current_row = null;
    $current_cell = null;

    while ( $processor->next_token() ) {
        if ( $processor->get_current_depth() < $table_depth ) {
            break;
        }

        $token_type = $processor->get_token_type();

        if ( '#text' === $token_type ) {
            if ( null !== $current_cell ) {
                $current_cell .= $processor->get_modifiable_text();
            }
            continue;
        }

        if ( '#tag' !== $token_type ) {
            continue;
        }

        $tag_name = $processor->get_tag();
        if ( null === $tag_name ) {
            continue;
        }

        if ( ! $processor->is_tag_closer() ) {
            if ( 'TR' === $tag_name ) {
                if ( null !== $current_cell && null !== $current_row ) {
                    $current_row[] = $current_cell;
                    $current_cell = null;
                }

                if ( null !== $current_row ) {
                    $rows[] = $current_row;
                }

                $current_row = array();
            } elseif ( 'TD' === $tag_name || 'TH' === $tag_name ) {
                if ( null === $current_row ) {
                    $current_row = array();
                }

                if ( null !== $current_cell ) {
                    $current_row[] = $current_cell;
                }

                $current_cell = '';
            }

            continue;
        }

        if ( 'TD' === $tag_name || 'TH' === $tag_name ) {
            if ( null !== $current_row ) {
                $current_row[] = null === $current_cell ? '' : $current_cell;
            }

            $current_cell = null;
            continue;
        }

        if ( 'TR' === $tag_name ) {
            if ( null !== $current_cell && null !== $current_row ) {
                $current_row[] = $current_cell;
                $current_cell = null;
            }

            if ( null !== $current_row ) {
                $rows[] = $current_row;
            }

            $current_row = null;
        }
    }

    return $rows;
}
