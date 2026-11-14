<?php
/**
 * Imagick editor that can strip EXIF, XMP, IPTC and 8BIM metadata (GPS, camera, date taken) from
 * the stored placement image. WordPress only strips on resizes, not on the crop we save. The ICC
 * colour profile is kept so print colours stay right. Loaded only while a placement image is stored.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
require_once ABSPATH . WPINC . '/class-wp-image-editor-imagick.php';

if ( ! class_exists( 'SPL_Image_Editor_Imagick' ) ) {
	class SPL_Image_Editor_Imagick extends WP_Image_Editor_Imagick {
		/** @return true|WP_Error */
		public function spl_strip_meta() {
			return $this->strip_meta();
		}
	}
}
