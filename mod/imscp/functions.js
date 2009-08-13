// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Javascript helper function for IMS Content Package module including
 * dummy SCORM API.
 *
 * @package   mod-imscp
 * @copyright 2009 Petr Skoda (http://skodak.org)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Dummy SCORM API adapter */
var API = new function () {
    this.LMSCommit         = function (parameter) {return "true";};
    this.LMSFinish         = function (parameter) {return "true";};
    this.LMSGetDiagnostic  = function (errorCode) {return "n/a";};
    this.LMSGetErrorString = function (errorCode) {return "n/a";};
    this.LMSGetLastError   = function () {return "0";};
    this.LMSGetValue       = function (element) {return "";};
    this.LMSInitialize     = function (parameter) {return "true";};
    this.LMSSetValue       = function (element, value) {return "true";};
};

var imscp_layout_widget;
var imscp_current_node;
var imscp_buttons = [];
var imscp_bloody_labelclick = false;
var imscp_panel;

function imscp_init() {
    YAHOO.util.Event.onDOMReady(function () {
        imscp_setup();
    });
}

function imscp_setup() {
    // layout
    YAHOO.widget.LayoutUnit.prototype.STR_COLLAPSE = mstr.moodle.hide;
    YAHOO.widget.LayoutUnit.prototype.STR_EXPAND = mstr.moodle.show;

    imscp_layout_widget = new YAHOO.widget.Layout('imscp_layout', {
        minWidth: 600,
        minHeight: 400,
        units: [
            { position: 'left', body: 'imscp_toc', header: mstr.imscp.toc, width: 250, resize: true, gutter: '2px 5px 5px 2px', collapse: true, minWidth:150},
            { position: 'center', body: '<div id="imscp_content"></div>', gutter: '2px 5px 5px 2px', scroll: true}
        ]
    });
    imscp_layout_widget.render();
    var left = imscp_layout_widget.getUnitByPosition('left');
    left.on('collapse', function() {
        imscp_resize_frame();
    });
    left.on('expand', function() {
        imscp_resize_frame();
    });

    // ugly resizing hack that works around problems with resizing of iframes and objects
    left._resize.on('startResize', function() {
        var obj = YAHOO.util.Dom.get('imscp_object');
        obj.style.display = 'none';
    });
    left._resize.on('endResize', function() {
        var obj = YAHOO.util.Dom.get('imscp_object');
        obj.style.display = 'block';
        imscp_resize_frame();
    });

    // TOC tree
    var tree = new YAHOO.widget.TreeView('imscp_tree');
    tree.singleNodeHighlight = true;
    tree.subscribe('labelClick', function(node) {
        imscp_activate_item(node);
        if (node.children.length) {
            imscp_bloody_labelclick = true;
        }
    });
    tree.subscribe('collapse', function(node) {
        if (imscp_bloody_labelclick) {
            imscp_bloody_labelclick = false;
            return false;
        }
    });
    tree.subscribe('expand', function(node) {
        if (imscp_bloody_labelclick) {
            imscp_bloody_labelclick = false;
            return false;
        }
    });
    tree.expandAll();
    tree.render();

    // navigation
    imscp_panel = new YAHOO.widget.Panel('imscp_navpanel', { visible:true, draggable:true, close:false,
                                                           context: ['page', 'bl', 'bl', ["windowScroll", "textResize", "windowResize"]], constraintoviewport:true} );
    imscp_panel.setHeader(mstr.imscp.navigation);
    //TODO: make some better&accessible buttons
    imscp_panel.setBody('<span id="imscp_nav"><button id="nav_skipprev">&lt;&lt;</button><button id="nav_prev">&lt;</button><button id="nav_up">^</button><button id="nav_next">&gt;</button><button id="nav_skipnext">&gt;&gt;</button></span>');
    imscp_panel.render();
    imscp_buttons[0] = new YAHOO.widget.Button('nav_skipprev');
    imscp_buttons[1] = new YAHOO.widget.Button('nav_prev');
    imscp_buttons[2] = new YAHOO.widget.Button('nav_up');
    imscp_buttons[3] = new YAHOO.widget.Button('nav_next');
    imscp_buttons[4] = new YAHOO.widget.Button('nav_skipnext');
    imscp_buttons[0].on('click', function(ev) {
        imscp_activate_item(imscp_skipprev(imscp_current_node));
    });
    imscp_buttons[1].on('click', function(ev) {
        imscp_activate_item(imscp_prev(imscp_current_node));
    });
    imscp_buttons[2].on('click', function(ev) {
        imscp_activate_item(imscp_up(imscp_current_node));
    });
    imscp_buttons[3].on('click', function(ev) {
        imscp_activate_item(imscp_next(imscp_current_node));
    });
    imscp_buttons[4].on('click', function(ev) {
        imscp_activate_item(imscp_skipnext(imscp_current_node));
    });
    imscp_panel.render();

    // finally activate the first item
    imscp_activate_item(tree.getRoot().children[0]);

    // resizing
    imscp_resize_layout(false);

    // fix layout if window resized
    window.onresize = function() {
        imscp_resize_layout(true);
    };
}


function imscp_activate_item(node) {
    if (!node) {
        return;
    }
    imscp_current_node = node;
    imscp_current_node.highlight();

    var content = new YAHOO.util.Element('imscp_content');
    try {
        // first try IE way - it can not set name attribute later
        // and also it has some restrictions on DOM access from object tag
        var obj = document.createElement('<iframe id="imscp_object" src="'+node.href+'">');
    } catch (e) {
        var obj = document.createElement('object');
        obj.setAttribute('id', 'imscp_object');
        obj.setAttribute('type', 'text/html');
        obj.setAttribute('data', node.href);
    }
    var old = YAHOO.util.Dom.get('imscp_object');
    if (old) {
        content.replaceChild(obj, old);
    } else {
        content.appendChild(obj);
    }
    imscp_resize_frame();

    imscp_current_node.focus();
    imscp_fixnav();
}

/**
 * Enables/disables navigation buttons as needed.
 * @return void
 */
function imscp_fixnav() {
    imscp_buttons[0].set('disabled', (imscp_skipprev(imscp_current_node) == null));
    imscp_buttons[1].set('disabled', (imscp_prev(imscp_current_node) == null));
    imscp_buttons[2].set('disabled', (imscp_up(imscp_current_node) == null));
    imscp_buttons[3].set('disabled', (imscp_next(imscp_current_node) == null));
    imscp_buttons[4].set('disabled', (imscp_skipnext(imscp_current_node) == null));
}


function imscp_resize_layout(alsowidth) {
    if (alsowidth) {
        var layout = YAHOO.util.Dom.get('imscp_layout');
        layout.style.width = '500px';
        var newwidth = imscp_get_htmlelement_size('content', 'width');
        if (newwidth > 500) {
            layout.style.width = newwidth+'px';
        }
    }
    var pageheight = imscp_get_htmlelement_size('page', 'height');
    var layoutheight = imscp_get_htmlelement_size(imscp_layout_widget, 'height');
    var newheight = layoutheight + parseInt(YAHOO.util.Dom.getViewportHeight()) - pageheight - 20;
    if (newheight > 400) {
        imscp_layout_widget.setStyle('height', newheight+'px');
    }
    imscp_layout_widget.render();
    imscp_resize_frame();

    imscp_panel.align('bl', 'bl');
}

function imscp_get_htmlelement_size(el, prop) {
    var val = YAHOO.util.Dom.getStyle(el, prop);
    if (val == 'auto') {
        if (el.get) {
            el = el.get('element'); // get real HTMLElement from YUI element
        }
        val = YAHOO.util.Dom.getComputedStyle(YAHOO.util.Dom.get(el), prop);
    }
    return parseInt(val);
}

function imscp_resize_frame() {
    var obj = YAHOO.util.Dom.get('imscp_object');
    if (obj) {
        var content = imscp_layout_widget.getUnitByPosition('center').get('wrap');
        obj.style.width = (content.offsetWidth - 6)+'px';
        obj.style.height = (content.offsetHeight - 10)+'px';
    }
}


function imscp_up(node) {
    if (node.depth > 0) {
        return node.parent;
    }
    return null;
}

function imscp_lastchild(node) {
    if (node.children.length) {
        return imscp_lastchild(node.children[node.children.length-1]);
    } else {
        return node;
    }
}

function imscp_prev(node) {
    if (node.previousSibling && node.previousSibling.children.length) {
        return imscp_lastchild(node.previousSibling);
    }
    return imscp_skipprev(node);
}

function imscp_skipprev(node) {
    if (node.previousSibling) {
        return node.previousSibling;
    } else if (node.depth > 0) {
        return node.parent;
    }
    return null;
}

function imscp_next(node) {
    if (node.children.length) {
        return node.children[0];
    }
    return imscp_skipnext(node);
}

function imscp_skipnext(node) {
    if (node.nextSibling) {
        return node.nextSibling;
    } else if (node.depth > 0) {
        return imscp_skipnext(node.parent);
    }
    return null;
}

