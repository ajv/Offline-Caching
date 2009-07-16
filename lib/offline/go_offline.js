// Copyright 2007, Google Inc.
//
// Redistribution and use in source and binary forms, with or without 
// modification, are permitted provided that the following conditions are met:
//
//  1. Redistributions of source code must retain the above copyright notice, 
//     this list of conditions and the following disclaimer.
//  2. Redistributions in binary form must reproduce the above copyright notice,
//     this list of conditions and the following disclaimer in the documentation
//     and/or other materials provided with the distribution.
//  3. Neither the name of Google Inc. nor the names of its contributors may be
//     used to endorse or promote products derived from this software without
//     specific prior written permission.
//
// THIS SOFTWARE IS PROVIDED BY THE AUTHOR ``AS IS'' AND ANY EXPRESS OR IMPLIED
// WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF 
// MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO
// EVENT SHALL THE AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, 
// SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO,
// PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS;
// OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY,
// WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR 
// OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF 
// ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.

var STORE_NAME = "my_offline_docset";

var MANIFEST_FILENAME = moodle_cfg['wwwroot']+"/lib/offline/manifest.php";

var localServer;
var store;
var initialized = false;

var request;
var numPings = 0;
var TIME_BETWEEN_PINGS = 3*1000;
var PING_TIMEOUT_SECONDS = 1*1000;
var isConnected;
var loading = true;

function pingSuccess() {
    if(request.responseText != "" && request.responseText.indexOf("404 Page not found") == -1){
        if (!isConnected || loading ) {
            document.getElementById('offline-img').innerHTML = "<img src='"+ moodle_cfg['wwwroot'] + "/pix/t/go.gif' title='Server available' />";
        }
        isConnected = true;
    } else {  
        if (isConnected || loading ) {
            document.getElementById('offline-img').innerHTML = "<img src='"+ moodle_cfg['wwwroot'] + "/pix/t/stop.gif' title='Server unavailable' />"; 
        }
        isConnected = false;
    }
    loading = false;
}

function isServerAvailable() {  
    var resource_to_test = moodle_cfg['wwwroot']+"/lib/offline/test.txt"; 
    resource_to_test += "?q=" + Math.floor(Math.random() * 100000);  
    request.open('GET', resource_to_test);  
    window.setTimeout("pingSuccess()",PING_TIMEOUT_SECONDS);  
    request.onreadystatechange = function() {    
      if (request.readyState == 4) {
        numPings++;
        //document.getElementById('pings').innerHTML = "Number of pings: " + numPings;
      }
    };
    request.send();
    window.setTimeout("isServerAvailable()",TIME_BETWEEN_PINGS);
}


// Called onload to initialize local server and store variables
function init_offline() {
    
    if (!window.google || !google.gears) {
        textOut(mstr.moodle.mustinstallgears, 1);
        return;
    } 
    
    if (!google.gears.factory.hasPermission) {
        var siteName = 'Moodle';
        var icon = moodle_cfg['wwwroot']+'/pix/moodlelogo.gif';
        var msg = 'This site would like to use Google Gears to enable fast, '
        + 'offline capabilities of its resources.';
        var allowed = google.gears.factory.getPermission(siteName, icon, msg);
    }

    request = google.gears.factory.create('beta.httprequest');
    localServer = google.gears.factory.create("beta.localserver");
    store = localServer.createManagedStore(STORE_NAME);
        
    try {
        if(localServer.canServeLocally(document.URL)) {
            linkOut(removeStore, mstr.moodle.goonline, mstr.moodle.goonlinetitle);
            disableInactiveLinks();
        }
    } catch (e) {
        textOut(e.message,1);
    }
    isServerAvailable();
}

function disableInactiveLinks() {
    for (i=document.links.length-1; i >= 0; i--) {
        link = document.links[i].href;
        if (link.indexOf('http') != 0) {
            link = document.URL + link;
        }
        if (link.indexOf(moodle_cfg['wwwroot']) != 0) {
            element = document.links[i];
            element.removeAttribute("href");
            element.title = mstr.moodle.unavailableextlink;
        }
        else {
            try {
                if(!localServer.canServeLocally(link)) {
                    element = document.links[i];
                    element.removeAttribute("href");
                    element.title = mstr.moodle.unavailablefeature;
                    if(element.type == 'submit') {
                        element.disabled = true;
                    }

                }
            } catch (e) {
                alert(e.message+' '+link);
            }
        }
    }
    
    var f;
    var e;
    for( f=0; f<document.forms.length; f++) {
        for( e=0; e<document.forms[f].elements.length; e++) {
            document.forms[f].elements[e].disabled = true;
        }
    } 
    
}

function createStore() {
    if(!isConnected){
        textOut(mstr.moodle.cantdetectconnection, 1);
        return;
    }
    textOut(mstr.moodle.pleasewait, 1)
    store = localServer.createManagedStore(STORE_NAME);
    store.manifestUrl = MANIFEST_FILENAME;
    store.checkForUpdate();

    var timerId = window.setInterval(function() {
        if (store.currentVersion) {
            window.clearInterval(timerId);
            textOut("Captured "+store.currentVersion+".",1)
            textOut("",1)
            linkOut(removeStore, mstr.moodle.goonline, mstr.moodle.goonlinetitle);
            disableInactiveLinks();

        } else if (store.updateStatus == 3) {
            textOut("Error: " + store.lastErrorMessage,1);
        }
    }, 500);  
}

function removeStore() {
    if (!window.google || !google.gears) {
        alert(mstr.moodle.mustinstallgears);
        return;
    }
    
    textOut(mstr.moodle.pleasewait,1);
    if (isConnected) {
        localServer.removeManagedStore(STORE_NAME);
        textOut("Erased. ", 1);
        linkOut(createStore, mstr.moodle.gooffline, mstr.moodle.goofflinetitle);
        window.location.reload();
    }
    else {
        textOut(mstr.moodle.cantdetectconnection, 1);
    }
}

function textOut(s, n) {
    var elm = document.getElementById("offline-message");
    elm.innerHTML = s;
}

function linkOut(functn, status, title) {
    var link = document.createElement('a');
    link.href = "###";
    link.innerHTML = status;
    link.onclick = functn;
    link.title = title;
    var elm = document.getElementById("offline-status");
    elm.innerHTML = '';
    elm.appendChild(link);
}
