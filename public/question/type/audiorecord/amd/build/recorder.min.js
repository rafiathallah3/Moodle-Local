define('qtype_audiorecord/recorder', ['jquery', 'core/ajax', 'core/notification'], function ($, Ajax, Notification) {
    return {
        init: function (config) {
            // Use a small timeout to ensure the DOM is fully rendered in Moodle's quiz interface.
            setTimeout(function() {
                var wrapper = $('#' + config.id);
                console.log("Config ID:", config.id);
                console.log("Wrapper found:", wrapper.length > 0);
                
                if (wrapper.length === 0) {
                    // If not found, try searching by class if it's a single instance, 
                    // or wait even longer.
                    console.error("Audiorecord wrapper not found by ID. Retrying...");
                    return;
                }

                var startBtn = wrapper.find('.start-btn');
                var stopBtn = wrapper.find('.stop-btn');
                var timerDisplay = wrapper.find('.timer');
                var previewContainer = wrapper.find('.audiorecord-preview');
                var warningArea = wrapper.find('.audiorecord-warning');

                var mediaRecorder;
                var audioChunks = [];
                var timerInterval;
                var startTime;
                var timeLimit = parseInt(config.timelimit, 10);

                if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                    var errorTxt = "Your browser does not support audio recording or blocks it because this site is not using HTTPS. Please test via localhost or HTTPS.";
                    warningArea.text(errorTxt).removeClass('d-none');
                    startBtn.prop('disabled', true);
                    return;
                }

                function updateTimer() {
                    var elapsed = Math.floor((Date.now() - startTime) / 1000);
                    var remaining = timeLimit - elapsed;

                    if (remaining <= 0) {
                        remaining = 0;
                        if (mediaRecorder && mediaRecorder.state === 'recording') {
                            mediaRecorder.stop();
                        }
                    }

                    var minutes = Math.floor(elapsed / 60);
                    var seconds = elapsed % 60;
                    timerDisplay.text(
                        (minutes < 10 ? '0' : '') + minutes + ':' +
                        (seconds < 10 ? '0' : '') + seconds
                    );
                }

                startBtn.on('click', function () {
                    console.log("Start button clicked, calling getUserMedia");
                    previewContainer.empty();
                    navigator.mediaDevices.getUserMedia({ audio: true })
                        .then(function (stream) {
                            console.log("Microphone access granted, starting MediaRecorder");
                            mediaRecorder = new MediaRecorder(stream, { mimeType: 'audio/webm' });
                            audioChunks = [];

                            mediaRecorder.addEventListener("dataavailable", function (event) {
                                if (event.data.size > 0) {
                                    audioChunks.push(event.data);
                                }
                            });

                            mediaRecorder.addEventListener("stop", function () {
                                stream.getTracks().forEach(function (track) { track.stop(); });

                                clearInterval(timerInterval);
                                startBtn.removeClass('d-none');
                                stopBtn.addClass('d-none');

                                var audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
                                var audioUrl = URL.createObjectURL(audioBlob);

                                var audioEl = $('<audio controls></audio>');
                                audioEl.attr('src', audioUrl);

                                var loadingMsg = $('<div class="alert alert-info mt-2">Uploading recording...</div>');
                                previewContainer.empty().append(audioEl).append(loadingMsg);

                                uploadAudio(audioBlob, loadingMsg);
                            });

                            mediaRecorder.start();

                            startTime = Date.now();
                            timerInterval = setInterval(updateTimer, 1000);

                            startBtn.addClass('d-none');
                            stopBtn.removeClass('d-none');
                        })
                        .catch(function (err) {
                            console.error("getUserMedia error:", err);
                            Notification.exception(err);
                        });
                });

                stopBtn.on('click', function () {
                    if (mediaRecorder && mediaRecorder.state === 'recording') {
                        mediaRecorder.stop();
                    }
                });

                function uploadAudio(blob, loadingMsg) {
                    var reader = new FileReader();
                    reader.readAsDataURL(blob);
                    reader.onloadend = function () {
                        var baseData = reader.result.split(',')[1];

                        var args = {
                            contextid: 0,
                            component: 'user',
                            filearea: 'draft',
                            itemid: parseInt(config.itemid, 10),
                            filepath: '/',
                            filename: config.filename,
                            filecontent: baseData
                        };

                        console.log("Attempting upload with args:", args);

                        Ajax.call([{
                            methodname: 'qtype_audiorecord_upload_file',
                            args: args
                        }])[0].done(function (result) {
                            console.log("Upload success:", result);
                            loadingMsg.removeClass('alert-info').addClass('alert-success').text('Recording saved successfully.');
                        }).fail(function (error) {
                            console.error("AJAX upload failed:", error);
                            var errorMsg = error.message || error.error || 'Unknown error';
                            loadingMsg.removeClass('alert-info').addClass('alert-danger').text('Failed to save recording: ' + errorMsg);
                            Notification.exception(error);
                        });
                    };
                }
            }, 100); // 100ms delay to ensure DOM is ready
        }
    };
});
