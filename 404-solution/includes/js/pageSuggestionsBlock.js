/**
 * Editor registration for the dynamic 404 page-suggestions block.
 *
 * This file intentionally has no build step. WordPress supplies the block,
 * element, components, and i18n globals declared by the PHP registration.
 */
(function (wp) {
    'use strict';

    var el = wp.element.createElement;
    var __ = wp.i18n.__;
    var title = __('404 Suggestions', '404-solution');
    var description = __('Shows visitors a list of pages matching the URL they were looking for', '404-solution');

    var blockName = 'abj404/page-suggestions';
    var settings = {
        title: title,
        description: description,
        category: 'widgets',
        edit: function () {
            return el(wp.components.Placeholder, {
                label: title,
                instructions: description
            });
        },
        save: function () {
            return null;
        }
    };

    wp.blocks.registerBlockType(blockName, settings);
}(window.wp));
