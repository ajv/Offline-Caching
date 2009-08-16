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

var OFFLINE_MANIFEST_FILENAME = moodle_cfg['wwwroot']+"/lib/offline/manifest.php";
var OFFLINE_STORE_NAME = "offline_moodle";

var TURBO_MANIFEST_FILENAME = moodle_cfg['wwwroot']+"/lib/offline/turbo_manifest.php";
var TURBO_STORE_NAME = "turbo_moodle";

var localServer;
var store;

var workerPool;
var childId;

var request;
var numPings = 0;
var TIME_BETWEEN_PINGS = 1*1000;
var PING_TIMEOUT_SECONDS = 1*1000;
var isConnected;
var creatingStore = false;
var loading = true;

var handleFailure = function(o){
    offline_message('Synch failed', 'offline-message');
}
var db;
var callback = 
{
    success: handleSuccess,
    failure: handleFailure
};






// Called onload to initialize local server and store variables
function offline_init() {
	
	// Making sure GG is installed and has permissions
    if (!window.google || !google.gears) {
        offline_message(mstr.moodle.mustinstallgears, "offline-message");
        return;
    } 
    if (!google.gears.factory.hasPermission) {
        var siteName = 'Moodle';
        var icon = moodle_cfg['wwwroot']+'/pix/moodlelogo.gif';
        var msg = 'This site would like to use Google Gears to enable fast, '
        + 'offline capabilities of its resources.';
        var allowed = google.gears.factory.getPermission(siteName, icon, msg);
    }

    // Initiate the local store and check if we are in offline mode
    localServer = google.gears.factory.create("beta.localserver");
    offlineStore = localServer.createManagedStore(OFFLINE_STORE_NAME);       
    try {
        if(localServer.canServeLocally(document.URL)) {
            offline_link(offline_remove_store, mstr.moodle.goonline, mstr.moodle.goonlinetitle);
            offline_disable_inactive_links();
            if(typeof(logdata) != "undefined") {
                var timenow = Math.round(new Date().getTime()/1000);
                var db = google.gears.factory.create('beta.database');
                db.open('offline-database');
                db.execute('create table if not exists Logs' +
                ' (Time int, UserID text, CourseID text, IP text, Module text, CmID text, Action text, URL text, Info text)');
                db.execute('insert into Logs values (?, ?, ?, ?, ?, ?, ?, ?, ?)', [timenow, logdata['userid'], logdata['course'], logdata['ip'], logdata['module'], logdata['cmid'], logdata['action'], logdata['url'], logdata['info']]);
                db.close();
            }
			
			var formID = 'mform1';
			var uploadFile = false;

			alert("and");
			/*
			var offlineFormSubmit = function(e){
				alert('here');
				YAHOO.util.Connect.setForm(formID, uploadFile);		
				var serializedForm = YAHOO.util.Connect._sFormData;

				var db = google.gears.factory.create('beta.database');
		        db.open('offline-database');
		        db.execute('create table if not exists Forms (Form text)');
		        db.execute('insert into Forms values (?)', [serializedForm]);
				db.close();
				alert("form saved: "+serializedForm);
				window.location = moodle_cfg['wwwroot'] + '/course/view.php?id=3';
			};
			
			YAHOO.util.Event.on(formID, 'submit', offlineFormSubmit);
			*/

        }
        else {
            offline_link(offline_create_store, mstr.moodle.gooffline, mstr.moodle.goofflinetitle);
            
        }
    } catch (e) {
        offline_message(e.message, "offline-message");
    }

    // Checking server availability
    request = google.gears.factory.create('beta.httprequest');
    offline_is_server_available();

    // This is for turbo (caching in the background)
    workerPool = google.gears.factory.create('beta.workerpool');
    workerPool.onmessage = offline_parent_handler;
    try {
        childId = workerPool.createWorkerFromUrl(moodle_cfg['wwwroot']+'/lib/offline/worker.js');
    } catch (e) {
        offline_message('Could not create worker: ' + e.message, "offline-message");
    }
    workerPool.sendMessage({tmf: TURBO_MANIFEST_FILENAME, tsn: TURBO_STORE_NAME}, childId);

}

function offline_parent_handler(messageText, sender, message) {
    offline_message('Worker message: ' + message.body, "offline-message");
}

function offline_ping_success() {
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

function offline_is_server_available() {  
    var resource_to_test = moodle_cfg['wwwroot']+"/lib/offline/test.txt"; 
    resource_to_test += "?q=" + Math.floor(Math.random() * 100000);  
    request.open('GET', resource_to_test);  
    window.setTimeout("offline_ping_success()",PING_TIMEOUT_SECONDS);  
    request.onreadystatechange = function() {    
        if (request.readyState == 4) {
            numPings++;
        }
    };
    request.send();
    window.setTimeout("offline_is_server_available()",TIME_BETWEEN_PINGS);
}


function offline_disable_inactive_links() {
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
    /*
    var f;
    var e;
    for( f=0; f<document.forms.length; f++) {
        for( e=0; e<document.forms[f].elements.length; e++) {
            document.forms[f].elements[e].disabled = true;
        }
    } */
}

function offline_create_store() {
    if(!isConnected){
        offline_message(mstr.moodle.cantdetectconnection, "offline-message");
        return;
    }

    if(creatingStore){
        return;
    }
    creatingStore = true;
    
    if(!localServer.canServeLocally(moodle_cfg['wwwroot']+"/lib/offline/gears_init.php")) {
        OFFLINE_MANIFEST_FILENAME = moodle_cfg['wwwroot']+"/lib/offline/manifest_init.php";
    }
    store = localServer.createManagedStore(OFFLINE_STORE_NAME);
    store.manifestUrl = OFFLINE_MANIFEST_FILENAME;
    store.checkForUpdate();

    // The progressbar
    offline_message("", "offline-message");
    offline_message("0%", "pb-percentage");
    var Dom = YAHOO.util.Dom, Event = YAHOO.util.Event, pb, percentage;
    YAHOO.util.Event.onAvailable('pb', function () {
    pb = new YAHOO.widget.ProgressBar({value:0, height:'10px', width: 100, barColor:'orange',backColor:'white',border:'thin solid black'});
    pb.render('pb');    
    });   
    Event.on('pb-percentage','DOMSubtreeModified',function() {
        var newVal = parseInt(Dom.get('pb-percentage').innerHTML,10);
        pb.set('value',newVal);
    });

    var timerId = window.setInterval(function() {
        if (store.currentVersion) {
            window.clearInterval(timerId);
            offline_message("", "offline-message");
            offline_message("", "pb-percentage");
            creatingStore = false;
            pb.destroy();
            offline_link(offline_remove_store, mstr.moodle.goonline, mstr.moodle.goonlinetitle);
            offline_disable_inactive_links();

        } else if (store.updateStatus == 3) {
            offline_message("Error: Unable to go offline. Please check your connection and try again.", "offline-message");
            offline_message("", "pb-percentage");
            creatingStore = false;
            pb.destroy();

        } else {
            store.onprogress = function(details) {
                percentage = Math.round(100*details.filesComplete/details.filesTotal);
                offline_message(percentage + "%", "pb-percentage");
            };
        }
    }, 500);  

}

function offline_update_logs(rs){
    
    if(!rs.isValidRow()) {
		return;
    }
    
	var sUrl = moodle_cfg['wwwroot']+"/lib/offline/update_db.php";
    var postData = 'time=' + rs.field(0) + '&userid=' + rs.field(1) + '&course=' + rs.field(2) + '&ip=' + rs.field(3) + '&module=' + rs.field(4) + '&cmid=' + rs.field(5)
    + '&action=' + rs.field(6) + '&url=' + rs.field(7) + '&info=' + rs.field(8);
    var request = YAHOO.util.Connect.asyncRequest('POST', sUrl, callback, postData);

    var timerId = window.setInterval(function() {
        if(!YAHOO.util.Connect.isCallInProgress(request)) {
            window.clearInterval(timerId);
            rs.next();
            offline_update_logs(rs);
        }
    }, 100);

}


function offline_update_forms(rs){
    
    if(!rs.isValidRow()) {
		return;
    }
    
	var sUrl = moodle_cfg['wwwroot']+"/course/edit.php";
    var postData = rs.field(0);
    var request = YAHOO.util.Connect.asyncRequest('POST', sUrl, callback, postData);

    var timerId = window.setInterval(function() {
        if(!YAHOO.util.Connect.isCallInProgress(request)) {
            window.clearInterval(timerId);
            rs.next();
            offline_update_forms(rs);
        }
    }, 100);

}


function offline_remove_store() {
    if (!window.google || !google.gears) {
        alert(mstr.moodle.mustinstallgears);
        return;
    }
    
    offline_message(mstr.moodle.pleasewait, "offline-message");
    
    if (isConnected) {
    
        offline_message("Please wait. ", "offline-message");
        localServer.removeManagedStore(OFFLINE_STORE_NAME);
        
        // Synchronizing logs
        db = google.gears.factory.create('beta.database');
        db.open('offline-database');    
        var rs = db.execute('select * from Logs order by Time');
        offline_update_logs(rs);
		rs.close(); 
/*
		// Synchronizing forms
		rs = db.execute('select * from Forms');
		offline_update_forms(rs);
		rs.close();
*/		
		db.remove();
        offline_message("Erased. ", "offline-message");
        offline_link(offline_create_store, mstr.moodle.gooffline, mstr.moodle.goofflinetitle);
        window.location.reload();       
    }
    else {
        offline_message(mstr.moodle.cantdetectconnection, "offline-message");
    }
}

function offline_message(string, id) {
    var elm = document.getElementById(id);
    elm.innerHTML = string;
}

function offline_link(functn, status, title) {
    var link = document.createElement('a');
    link.href = "###";
    link.innerHTML = status;
    link.onclick = functn;
    link.title = title;
    var elm = document.getElementById("offline-status");
    elm.innerHTML = '';
    elm.appendChild(link);
}
