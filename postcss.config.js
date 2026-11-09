/**
 * Readable CSS (WordPress.org guideline 4): @wordpress/scripts' own PostCSS plugins, without the cssnano minifier it
 * adds in production when a project has no PostCSS config.
 */
const scripts = require.resolve( '@wordpress/scripts/package.json' );

module.exports = {
	plugins: require( require.resolve( '@wordpress/postcss-plugins-preset', { paths: [ scripts ] } ) ),
};
