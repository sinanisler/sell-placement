<?php
/**
 * Imagick editor that removes all metadata from the stored placement image: EXIF (GPS, camera,
 * date taken), XMP, IPTC, 8BIM and comments. Only the ICC colour profile is put back, so print
 * colours stay right. WordPress's own strip_meta() deliberately keeps EXIF, XMP and IPTC, and it
 * only runs on resizes anyway, so it is not enough here. Loaded only while a placement image is stored.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
require_once ABSPATH . WPINC . '/class-wp-image-editor-imagick.php';

if ( ! class_exists( 'SPL_Image_Editor_Imagick' ) ) {
	class SPL_Image_Editor_Imagick extends WP_Image_Editor_Imagick {
		/**
		 * Call after maybe_exif_rotate(), which has already applied the EXIF orientation to the pixels.
		 *
		 * @return true|WP_Error
		 */
		public function spl_strip_meta() {
			try {
				$icc = array();
				foreach ( array( 'icc', 'icm' ) as $name ) {
					$found = $this->image->getImageProfiles( $name, true );
					if ( ! empty( $found[ $name ] ) ) {
						$icc[ $name ] = $found[ $name ];
					}
				}
				$this->image->stripImage();
				foreach ( $icc as $name => $data ) {
					$this->image->profileImage( $name, $data );
				}
			} catch ( Exception $e ) {
				return new WP_Error( 'spl_strip_meta', $e->getMessage() );
			}
			return true;
		}
	}
}
