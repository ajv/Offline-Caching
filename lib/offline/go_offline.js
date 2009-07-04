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

var numPings = 0;
var TIME_BETWEEN_PINGS = 3*1000;
var PING_TIMEOUT_SECONDS = 1*1000;
var request;

function pingSuccess() {
    if(request.responseText != "" && request.responseText.indexOf("404 Page not found") == -1){
      document.getElementById('serverStatus').innerHTML = "[Server Accessible]";
    } else {    
      document.getElementById('serverStatus').innerHTML = "[Server Inaccessible]";  
    }
  }

function isServerAvailable() {  
    var resource_to_test = moodle_cfg['wwwroot']+"test.txt";  
    resource_to_test += "?q=" + Math.floor(Math.random() * 100000);  
    request.open('GET', resource_to_test);  
    window.setTimeout("pingSuccess()",PING_TIMEOUT_SECONDS);  
    request.onreadystatechange = function() {    
      if (request.readyState == 4) {
        numPings++;
        document.getElementById('pings').innerHTML = "Number of pings: " + numPings;
      }
    };
    request.send();
    window.setTimeout("isServerAvailable()",TIME_BETWEEN_PINGS);
}


// Called onload to initialize local server and store variables
function init_offline() {
	if (!window.google || !google.gears) {
        textOut("NOTE:  You must install Gears first.", 1);
    } else {
        var request = google.gears.factory.create('beta.httprequest');
        localServer = google.gears.factory.create("beta.localserver");
        store = localServer.createManagedStore(STORE_NAME);
        try {
            if(localServer.canServeLocally(document.URL)) {
                linkOut(removeStore, js_lang_string.goonline);
            }
        } catch (e) {
            alert(e.message);
        }
    }
}

// Create the managed resource store
function createStore() {
    if (!window.google || !google.gears) {
        alert("You must install Gears first.");
        return;
    } 
	textOut(js_lang_string.pleasewait, 1)
    //localServer = google.gears.factory.create("beta.localserver");
    store = localServer.createManagedStore(STORE_NAME);
    store.manifestUrl = MANIFEST_FILENAME;
    store.checkForUpdate();

    var timerId = window.setInterval(function() {
        // When the currentVersion property has a value, all of the resources
        // listed in the manifest file for that version are captured. There is
        // an open bug to surface this state change as an event.
        if (store.currentVersion) {
            window.clearInterval(timerId);
            textOut("Captured "+store.currentVersion+".",1)
            textOut("",1)
            linkOut(removeStore, js_lang_string.goonline);

        } else if (store.updateStatus == 3) {
            textOut("Error: " + store.lastErrorMessage,1);
        }
    }, 500);  
}

// Remove the managed resource store.
function removeStore() {
    if (!window.google || !google.gears) {
        alert("You must install Gears first.");
        return;
    }
	/*if (isServerAvailable()) {
		alert("yes");
    	localServer.removeManagedStore(STORE_NAME);
    	textOut("Erased. ", 1);
    	linkOut(createStore, js_lang_string.gooffline);
	}
	else {
		alert("no");
		
		textOut("Cannot detect a connection. ", 1);
	}*/
	//alert(isServerAvailable());
	localServer.removeManagedStore(STORE_NAME);
	textOut("Erased. ", 1);
	linkOut(createStore, js_lang_string.gooffline);

}

// Utility function to output some status text.
function textOut(s, n) {
    var elm = document.getElementById("offline-message");
    elm.innerHTML = s;
}

function linkOut(functn, status) {
    var link = document.createElement('a');
    link.href = "###";
    link.innerHTML = status;
    link.onclick = functn;
    var elm = document.getElementById("offline-status");
    elm.innerHTML = '';
    //elm.innerHTML += "Go ";
    elm.appendChild(link);
}
