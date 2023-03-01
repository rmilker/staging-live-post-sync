<?php
 /**
 * Sync New Posts between staging and live site
 * @param  int     $post_id     Post ID
 * @param  WP_Post $post        Post Object
 * @param  bool    $update      Whether this is an existing post being updated.
 * @param  [type]  $post_before The full post object prior to the update
 */ 
if ( wp_get_environment_type() === 'staging' ) { // trigger the action on staging only

    add_action( 'wp_after_insert_post', 'mpc_sync_post', 999, 4 );
    function mpc_sync_post( $post_id, $post, $update, $post_before ) {

        // bail if not 'post' post type
        if ( get_post_type( $post ) !== 'post' ) return;

        // get featured image
        $fm = get_post_thumbnail_id( $post_id );

        // get metadata
        $post_meta = (object) get_post_meta( $post_id );

        // get categories
        $cats = wp_get_post_categories( $post_id, array( 'fields' => 'ids' ) );

        // get tags
        $tags = wp_get_post_tags( $post_id, array( 'fields' => 'ids' ) );

        // setup args
        $args = array(
            'date'           => $post->post_date,
            'date_gmt'       => $post->post_date_gmt,
            'slug'           => $post->post_name,
            'status'         => $post->post_status,
            'title'          => $post->post_title,
            'content'        => $post->post_content,
            'author'         => $post->post_author,
            'excerpt'        => $post->post_excerpt,
            'meta'           => $post_meta,
            'sticky'         => is_sticky( $post_id ),
            'categories'     => $cats,
            'tags'           => $tags
        );

        // check post status before the request
        $status_not = array('auto-draft', 'trash', 'inherit', 'draft');
        if ( isset( $post->post_status ) && ! in_array( $post->post_status, $status_not ) ) {

            // get API credentials
            $username = '{USERNAME}';
            $password = '{PASSWORD}';
            $url = '{SITEURL}';

            // handle featured image upload
            if ( $fm ) {

                // check if image already exists
                $fm_url = get_the_post_thumbnail_url( $post_id );
                $fname = basename($fm_url);
                $check_media = wp_remote_get( $url.'/media?search='.$fname );
                $check_media_response = json_decode( wp_remote_retrieve_body( $check_media ) );
                $has_media = false;
                if ( $check_media_response ) {
                    // look for matching filename
                    foreach ( $check_media_response as $media_item ) {
                        if ( isset( $media_item->media_details->file ) ) {
                            if ( basename( $media_item->media_details->file ) == $fname ) {
                                $has_media = true;
                                // set existing featured image
                                $args['featured_media'] = $media_item->id;
                                break;
                            }
                        }
                    }
                }

                if ( ! $has_media ) { // if existing match not found, upload it

                    $upload_media = wp_remote_post(
                        $url.'/media',
                        array(
                            'headers' => array(
                                'Authorization' => 'Basic ' . base64_encode( "$username:$password" ),
                                'Content-Disposition' => 'attachment; filename="' . basename( $fm_url ) . '"',
                                'Content-Type: ' . wp_get_image_mime( $fm_url ),
                            ),
                            'body' => file_get_contents( $fm_url ),
                            'timeout' => 20
                        )
                    );

                    // set uploaded attachment as the new featured image
                    if( 'Created' === wp_remote_retrieve_response_message( $upload_media ) ) {
                        $body = json_decode( wp_remote_retrieve_body( $upload_media ) );
                        $args['featured_media'] = $body->id;
                    }
                }

            }

            // check for post update - if this post exists on live based on status and slug
            if ( $update ) {

                $live_post_request = wp_remote_request( 
                    $url.'/posts?slug='.$post->post_name.'&status='.$post->post_status,
                    array(
                        'headers' => array(
                            'Authorization' => 'Basic ' . base64_encode( "$username:$password" )
                        )
                    )
                );

                // if it's a post update, retrieve live post id and set args and url accordingly
                if ( 'OK' === wp_remote_retrieve_response_message( $live_post_request ) ) {
                    
                    $live_post = json_decode( wp_remote_retrieve_body( $live_post_request ) );

                    // if post has already been published and live post not found, check if live post exists as scheduled
                    if ( $post->post_status == 'publish' && ! $live_post ) {
                        $live_post_request = wp_remote_request( 
                            $url.'/posts?slug='.$post->post_name.'&status=future',
                            array(
                                'headers' => array(
                                    'Authorization' => 'Basic ' . base64_encode( "$username:$password" )
                                )
                            )
                        );
                        $live_post = json_decode( wp_remote_retrieve_body( $live_post_request ) );
                    }

                    // if live post found, attach it's ID to the request URL
                    if ( is_array( $live_post ) && $live_post ) {
                        $live_post_id = $live_post[0]->id;
                        $args['id'] = $live_post_id;
                        $url .= '/'.$live_post_id;
                    }
                }
            }

            // send request
            $request = wp_remote_post(
                $url.'/posts',
                array(
                    'headers' => array(
                        'Authorization' => 'Basic ' . base64_encode( "$username:$password" )
                    ),
                    'body' => $args
                )
            );

            // uncomment to debug
            // if ( 'Created' !== wp_remote_retrieve_response_message( $request ) ) {
            //     // log errors
            //     $body = json_decode( wp_remote_retrieve_body( $request ) );
            //     error_log( print_r( $body, true ) );
            // }

        }

    }
}
