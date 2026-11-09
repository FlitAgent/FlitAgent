/**
 * WordPress dependencies
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

/**
 * External dependencies
 */
const { resolve } = require( 'path' );
const CopyPlugin = require( 'copy-webpack-plugin' );

// Readable CSS (WordPress.org guideline 4): Sass writes expanded output (sass-loader compresses in production).
const expandedSass = ( rule ) => ( {
	...rule,
	use: Array.isArray( rule.use )
		? rule.use.map( ( use ) =>
				use && typeof use.loader === 'string' && use.loader.includes( 'sass-loader' )
					? { ...use, options: { ...use.options, sassOptions: { ...( use.options && use.options.sassOptions ), outputStyle: 'expanded' } } }
					: use
		  )
		: rule.use,
} );

module.exports = {
	...defaultConfig,
	entry: {
		admin: [ './src/admin' ],
	},
	resolve: {
		...defaultConfig.resolve,
		extensions: [ ...defaultConfig.resolve.extensions, '.ts' ],
	},
	output: {
		filename: '[name].js',
		path: resolve( process.cwd(), 'build' ),
		// The fonts and the mark are emitted next to the CSS; URLs relative to the stylesheet.
		publicPath: 'auto',
	},
	// Readable output (WordPress.org guideline 4); src/ and this config ship in the plugin beside build/ (readme.txt, Source code).
	optimization: { ...defaultConfig.optimization, minimize: false },
	plugins: [
		...defaultConfig.plugins,
		// Licences travel with what they cover: the fonts' SIL OFL (condition 2) and the icons' ISC/MIT.
		new CopyPlugin( {
			patterns: [
				{ from: 'src/flit-design/fonts/LICENSE-*.txt', to: 'fonts/[name][ext]' },
				// The Lucide icons' licence (Admin\Ui, src/scss/_icons.scss).
				{ from: 'src/licenses/*.txt', to: 'licenses/[name][ext]' },
			],
		} ),
	],
	module: {
		...defaultConfig.module,
		rules: [
			...defaultConfig.module.rules.map( expandedSass ),
			{
				test: /\.ts$/,
				use: [
					{
						loader: 'ts-loader',
						options: {
							configFile: 'tsconfig.json',
							transpileOnly: true,
						},
					},
				],
			},
		],
	},
};
