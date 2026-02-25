const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

webpackConfig.entry = {
	'sharing-sidebar': path.join(__dirname, 'src', 'sharing-sidebar.js'),
}

module.exports = webpackConfig
