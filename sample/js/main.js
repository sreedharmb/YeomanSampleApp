/**
 * @author: Sreedhar M B
 *
 * @since: 27/02/2014 @ 01:00:20
 * @file: main.js
 *
 * @copyright: KNOLSKAPE Solutions Pvt Ltd
 **/

/**
 * FILE DESCRIPTION
 *
 **/
'use strict';

require.config({
    shim: {
        underscore: {
            exports: '_'
        },
        backbone: {
            deps: [
                'underscore',
                'jquery'
            ],
            exports: 'Backbone'
        },
        bootstrap: {
            deps: ['jquery'],
            exports: 'jquery'
        }
    },
    paths: {
        jquery: '../com/vendor/jquery/dist/jquery',
        backbone: '../com/vendor/backbone/backbone',
        underscore: '../com/vendor/underscore/underscore',
        bootstrap: '../com/vendor/sass-bootstrap/dist/js/bootstrap'
    }
});

require([
    'jquery',
    'underscore',
    'backbone'
], function ($, _, Backbone) {
    Backbone.history.start();
});
