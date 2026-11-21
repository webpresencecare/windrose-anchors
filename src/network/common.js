/**
 * Shared pieces of the network admin screens.
 */
import { useCallback, useState } from '@wordpress/element';
import { Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';

export const data = window.windroseAnchorsNetwork || {};

/**
 * A notice line that screen readers also hear.
 *
 * @return {Array} [ notice element, notify( status, message ) ].
 */
export function useNotice() {
	const [ notice, setNotice ] = useState( null );
	const notify = useCallback( ( status, message ) => {
		setNotice( message ? { status, message } : null );
		if ( message ) {
			speak( message, 'error' === status ? 'assertive' : 'polite' );
		}
	}, [] );
	const element = notice ? (
		<Notice
			status={ notice.status }
			onRemove={ () => setNotice( null ) }
			className="windrose-anchors__notice"
		>
			{ notice.message }
		</Notice>
	) : null;
	return [ element, notify ];
}

/**
 * A file size for people.
 *
 * @param {number} bytes Size in bytes.
 * @return {string} Size.
 */
export function formatBytes( bytes ) {
	let size = Number( bytes ) || 0;
	let unit = 0;
	while ( size >= 1024 && unit < 3 ) {
		size /= 1024;
		unit += 1;
	}
	const number = size.toLocaleString( undefined, {
		maximumFractionDigits: unit ? 1 : 0,
	} );
	switch ( unit ) {
		case 0:
			/* translators: %s: file size in bytes. */
			return sprintf( __( '%s B', 'windrose-anchors' ), number );
		case 1:
			/* translators: %s: file size in kilobytes. */
			return sprintf( __( '%s KB', 'windrose-anchors' ), number );
		case 2:
			/* translators: %s: file size in megabytes. */
			return sprintf( __( '%s MB', 'windrose-anchors' ), number );
		default:
			/* translators: %s: file size in gigabytes. */
			return sprintf( __( '%s GB', 'windrose-anchors' ), number );
	}
}
