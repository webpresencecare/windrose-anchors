/**
 * Windrose Anchors network admin screens: Sites, Usage, Leave safely (one bundle; the server says which screen).
 */
import { createRoot } from '@wordpress/element';

import { data } from './common';
import SitesScreen from './sites';
import UsageScreen from './usage';
import LeaveScreen from './leave';
import './network.scss';

const SCREENS = {
	sites: SitesScreen,
	usage: UsageScreen,
	leave: LeaveScreen,
};

const container = document.getElementById( 'windrose-anchors-app' );
if ( container ) {
	const Screen = SCREENS[ data.screen ] || SitesScreen;
	createRoot( container ).render( <Screen /> );
}
