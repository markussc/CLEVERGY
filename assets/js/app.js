/*
 * Welcome to your app's main JavaScript file!
 *
 * We recommend including the built version of this JavaScript file
 * (and its CSS file) in your base layout (base.html.twig).
 */

// any CSS you import will output into a single css file (app.css in this case)
import '../css/app.css';
import '../css/global.scss';

// Need jQuery? Install it with "yarn add jquery", then uncomment to import it.
import $ from 'jquery';

// create global $ and jQuery variables
global.$ = global.jQuery = $;

// foundation
require('foundation-sites');

// moment.js
var moment = require('moment');
moment().format();

// chart.js
require('chart.js');

require('../css/base.css');
require('../css/modal.css');
require('../css/owfont-regular.min.css');

