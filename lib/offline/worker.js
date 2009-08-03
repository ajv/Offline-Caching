google.gears.workerPool.onmessage = function(messageText, senderId, message) {
    
    var response = "Please wait (turbo)";   
    //google.gears.workerPool.sendMessage(response, message.sender);
    
    localServer = google.gears.factory.create("beta.localserver");
    turboStore = localServer.createManagedStore(message.body.tsn);
    turboStore.manifestUrl = message.body.tmf;
    turboStore.checkForUpdate();

    
    
    var timer = google.gears.factory.create('beta.timer');
    var timerId = timer.setInterval(function() { 
        if (turboStore.currentVersion) {
            timer.clearInterval(timerId);
            response = 'Turbo updated. Version ' + turboStore.currentVersion;
            //google.gears.workerPool.sendMessage(response, message.sender);
        } else if (turboStore.updateStatus == 3) {
            response = "Error: " + turboStore.lastErrorMessage + " ";
            //google.gears.workerPool.sendMessage(response, message.sender);            
        }
    }, 500);
};


