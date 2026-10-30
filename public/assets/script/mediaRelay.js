$(document).ready(function () {
    $(".sideBarli").removeClass("activeLi");
    $(".mediaRelaySideA").addClass("activeLi");

    function notify(message, type) {
        if (typeof iziToast !== "undefined") {
            iziToast[type === "error" ? "error" : "info"]({
                title: type === "error" ? "Error" : "Info",
                message: message,
                position: "topRight",
                timeout: 6000,
            });
            return;
        }
        console.log(message);
    }

    var relayFileTable = $("#relayFileTable").DataTable({
        autoWidth: false,
        processing: true,
        serverSide: true,
        serverMethod: "post",
        columnDefs: [
            {
                orderable: false,
                targets: 0,
            },
            {
                orderable: false,
                targets: "_all",
            },
        ],
        ajax: {
            url: `${domainUrl}relayFileList`,
            data: function (d) {
                d.state = $("#relayStateFilter").val();
            },
            error: (error) => {
                console.log(error);
            },
        },
    });

    $("#relayStateFilter").on("change", function () {
        relayFileTable.ajax.reload();
        updateBulkCount();
    });

    function selectedRelayIds() {
        return $.map($("input.relay-row-check:checked"), function (el) {
            return parseInt($(el).val(), 10);
        });
    }

    function updateBulkCount() {
        var count = selectedRelayIds().length;
        $("#relayBulkCount").text(count > 0 ? count + " " + "selected" : "");
        $("#bulkPromote, #bulkEvict, #bulkDelete").prop("disabled", count === 0);
    }

    $("#relaySelectAll").on("change", function () {
        $("input.relay-row-check").prop("checked", $(this).prop("checked"));
        updateBulkCount();
    });

    $(document).on("change", "input.relay-row-check", function () {
        var all = $("input.relay-row-check");
        $("#relaySelectAll").prop("checked", all.length > 0 && all.length === all.filter(":checked").length);
        updateBulkCount();
    });

    $("#relayFileTable").on("draw.dt", function () {
        $("#relaySelectAll").prop("checked", false);
        updateBulkCount();
    });

    function bulkRelayAction(action, confirmText) {
        var ids = selectedRelayIds();

        if (ids.length === 0) {
            return;
        }

        if (!window.confirm(confirmText)) {
            return;
        }

        var payload = {
            action: action,
            relay_file_ids: ids,
        };

        if (action === "evict" || action === "delete") {
            payload.purge_cache = true;
        }

        if (action === "delete") {
            payload.purge_telegram = true;
        }

        $.ajax({
            type: "POST",
            url: `${domainUrl}bulkRelayAction`,
            data: payload,
            success: function (response) {
                if (response.status) {
                    notify(response.done + " OK, " + response.failed + " failed", "info");
                } else {
                    notify("Bulk action failed", "error");
                }
                relayFileTable.ajax.reload(null, false);
            },
            error: function () {
                notify("Bulk action failed", "error");
            },
        });
    }

    $("#bulkPromote").on("click", function () {
        bulkRelayAction("promote", "Promote the selected files into the 9drive cache now?");
    });

    $("#bulkEvict").on("click", function () {
        bulkRelayAction("evict", "Remove the selected files from the 9drive cache?");
    });

    $("#bulkDelete").on("click", function () {
        bulkRelayAction("delete", "Delete the selected files from Telegram Drive and the cache?");
    });

    var relayLinkTable = null;

    function loadLinkTable(fileId) {
        $("#relay_link_file_id").val(fileId);

        if (relayLinkTable) {
            relayLinkTable.destroy();
        }

        relayLinkTable = $("#relayLinkTable").DataTable({
            autoWidth: false,
            processing: true,
            serverSide: true,
            serverMethod: "post",
            columnDefs: [
                {
                    targets: "_all",
                    orderable: false,
                },
            ],
            ajax: {
                url: `${domainUrl}relayLinkList`,
                data: { relay_file_id: fileId },
                error: (error) => {
                    console.log(error);
                },
            },
        });

        $("#relayLinksModal").modal("show");
    }

    function reloadLinks() {
        if (relayLinkTable) {
            relayLinkTable.ajax.reload(null, false);
        }
    }

    /* ------------------------------------------------------------- upload */

    var RELAY_CHUNK_SIZE = 8 * 1024 * 1024; // 8MB per chunk

    function setRelayProgress(percent, text) {
        $("#relayProgressBar")
            .css("width", percent + "%")
            .attr("aria-valuenow", Math.round(percent))
            .text(text !== undefined ? text : Math.round(percent) + "%");
        $("#relayProgress").show();
    }

    /**
     * Resumable upload: 8MB chunks appended server-side into one .part file.
     * The upload id persists in localStorage per file name+size, so a
     * browser that closed mid-upload continues where it stopped.
     */
    function relayChunkedUpload(file) {
        return new Promise((resolve, reject) => {
            const hex = () => {
                let h = "";
                for (let i = 0; i < 32; i++) {
                    h += "0123456789abcdef"[Math.floor(Math.random() * 16)];
                }
                return h;
            };

            const storageKey = "relayUpload:" + file.name + ":" + file.size;
            let uploadId = localStorage.getItem(storageKey);

            if (!uploadId) {
                uploadId = hex();
                localStorage.setItem(storageKey, uploadId);
            }

            const csrf = document.querySelector('meta[name="csrf-token"]').content;
            const start = (received, offset) => {
                if (offset >= file.size) {
                    localStorage.removeItem(storageKey);
                    $.ajax({
                        type: "POST",
                        url: `${domainUrl}uploadRelayComplete`,
                        headers: { "X-CSRF-TOKEN": csrf },
                        data: { upload_id: uploadId, name: file.name, mime: file.type || "application/octet-stream" },
                        success: (r) => (r.status ? resolve(r) : reject(r.message || "Upload failed.")),
                        error: (xhr) => reject((xhr.responseJSON && xhr.responseJSON.message) || "Upload failed."),
                    });
                    return;
                }

                const end = Math.min(offset + RELAY_CHUNK_SIZE, file.size);
                const blob = file.slice(offset, end);

                const form = new FormData();
                form.append("upload_id", uploadId);
                form.append("index", received);
                form.append("file", blob, "chunk.bin");

                $.ajax({
                    type: "POST",
                    url: `${domainUrl}uploadRelayChunk`,
                    headers: { "X-CSRF-TOKEN": csrf },
                    data: form,
                    contentType: false,
                    processData: false,
                    success: (r) => {
                        if (!r.status) {
                            reject(r.message || "Chunk upload failed.");
                            return;
                        }
                        setRelayProgress((r.received / file.size) * 100);
                        start(r.index + 1, r.received);
                    },
                    error: (xhr) => reject((xhr.responseJSON && xhr.responseJSON.message) || "Chunk upload failed."),
                });
            };

            // Ask the server how much it already has (resume support).
            $.ajax({
                type: "POST",
                url: `${domainUrl}uploadRelayStatus`,
                headers: { "X-CSRF-TOKEN": csrf },
                data: { upload_id: uploadId },
                success: (r) => {
                    const received = r.status ? Math.max(0, parseInt(r.received, 10) || 0) : 0;
                    start(0, received - (received % RELAY_CHUNK_SIZE));
                },
                error: () => start(0, 0),
            });
        });
    }

    $(document).on("submit", "#addRelayFileForm", function (e) {
        e.preventDefault();

        const fileInput = document.getElementById("relay_file");

        if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
            notify("No file selected.", "error");
            return;
        }

        checkUserType(function () {
            const file = fileInput.files[0];

            if (file.size > RELAY_CHUNK_SIZE) {
                relayChunkedUpload(file)
                    .then((response) => {
                        if (response.status) {
                            if (response.duplicate) {
                                notify(response.message, "info");
                            } else {
                                showSuccessToast();
                            }
                            relayFileTable.ajax.reload(null, false);
                            $("#addRelayFileModal").modal("hide");
                            $("#relayProgress").hide();
                            fileInput.value = "";
                        } else {
                            notify(response.message || "Upload failed.", "error");
                        }
                    })
                    .catch((message) => notify(String(message), "error"));

                return;
            }

            let formData = new FormData($("#addRelayFileForm")[0]);

            $.ajax({
                type: "POST",
                url: `${domainUrl}uploadRelayFile`,
                data: formData,
                contentType: false,
                processData: false,
                xhr: function () {
                    var xhr = new window.XMLHttpRequest();
                    xhr.upload.addEventListener(
                        "progress",
                        function (event) {
                            if (event.lengthComputable) {
                                var percent = Math.round((event.loaded / event.total) * 100);
                                $("#relayProgressBar")
                                    .css("width", percent + "%")
                                    .attr("aria-valuenow", percent)
                                    .text(percent + "%");
                                $("#relayProgress").show();
                            }
                        },
                        false
                    );
                    return xhr;
                },
                success: function (response) {
                    if (response.status) {
                        if (response.duplicate) {
                            notify(response.message, "info");
                        } else {
                            showSuccessToast();
                        }
                        relayFileTable.ajax.reload(null, false);
                        $("#addRelayFileModal").modal("hide");
                        $("#relayProgress").hide();
                        fileInput.value = "";
                    } else {
                        notify(response.message || "Upload failed.", "error");
                    }
                },
                error: function (xhr) {
                    notify((xhr.responseJSON && xhr.responseJSON.message) || "Upload failed.", "error");
                },
            });
        });
    });

    $("#relay_file").change(function () {
        $("#relayProgressBar").css("width", "0%").attr("aria-valuenow", 0).text("0%");
        $("#relayProgress").hide();
    });

    /* ------------------------------------------------------------ tiering */

    $(document).on("click", ".promote", function (e) {
        e.preventDefault();
        var id = $(this).attr("rel");

        checkUserType(function () {
            $.ajax({
                type: "POST",
                url: `${domainUrl}promoteRelayFile`,
                dataType: "json",
                data: { relay_file_id: id },
                success: function (response) {
                    if (response.status) {
                        showSuccessToast();
                    } else {
                        notify(response.message, "error");
                    }
                    relayFileTable.ajax.reload(null, false);
                },
            });
        });
    });

    $(document).on("click", ".evict", function (e) {
        e.preventDefault();
        var id = $(this).attr("rel");

        checkUserType(function () {
            $.ajax({
                type: "POST",
                url: `${domainUrl}evictRelayFile`,
                dataType: "json",
                data: { relay_file_id: id },
                success: function (response) {
                    if (response.status) {
                        showSuccessToast();
                    } else {
                        notify(response.message, "error");
                    }
                    relayFileTable.ajax.reload(null, false);
                },
            });
        });
    });

    $(document).on("click", ".relayLinks", function (e) {
        e.preventDefault();
        loadLinkTable($(this).attr("rel"));
    });

    $(document).on("click", ".delete", function (e) {
        e.preventDefault();
        var id = $(this).attr("rel");

        checkUserType(function () {
            swal({
                title: "Are you sure?",
                text: "The file record, its links and (optionally) the remote copies will be removed.",
                icon: "error",
                buttons: true,
                dangerMode: true,
                buttons: ["Cancel", "Yes"],
            }).then((confirmed) => {
                if (!confirmed) {
                    return;
                }

                $.ajax({
                    type: "POST",
                    url: `${domainUrl}deleteRelayFile`,
                    dataType: "json",
                    data: {
                        relay_file_id: id,
                        purge_telegram: 1,
                        purge_cache: 1,
                    },
                    success: function (response) {
                        if (response.status) {
                            showSuccessToast();
                            relayFileTable.ajax.reload(null, false);
                        } else {
                            somethingWentWrongToast();
                        }
                    },
                });
            });
        });
    });

    /* -------------------------------------------------------------- links */

    $("#createRelayLink").on("click", function (e) {
        e.preventDefault();
        var fileId = $("#relay_link_file_id").val();

        checkUserType(function () {
            $.ajax({
                type: "POST",
                url: `${domainUrl}createRelayLink`,
                dataType: "json",
                data: {
                    relay_file_id: fileId,
                    mode: $("#relay_link_mode").val(),
                    password: $("#relay_link_password").val(),
                    max_downloads: $("#relay_link_max").val(),
                    ttl: $("#relay_link_ttl").val(),
                },
                success: function (response) {
                    if (response.status) {
                        showSuccessToast();
                        $("#relay_link_password").val("");
                        reloadLinks();
                    } else {
                        notify(response.message, "error");
                    }
                },
            });
        });
    });

    $(document).on("click", ".copyLink", function (e) {
        e.preventDefault();
        var url = $(this).data("url");

        if (navigator.clipboard) {
            navigator.clipboard.writeText(url).then(function () {
                notify(url);
            });
        } else {
            notify(url);
        }
    });

    $(document).on("click", ".toggleLink", function (e) {
        e.preventDefault();
        var id = $(this).attr("rel");

        checkUserType(function () {
            $.ajax({
                type: "POST",
                url: `${domainUrl}toggleRelayLink`,
                dataType: "json",
                data: { relay_link_id: id },
                success: function (response) {
                    if (response.status) {
                        showSuccessToast();
                    } else {
                        notify(response.message, "error");
                    }
                    reloadLinks();
                },
            });
        });
    });

    $(document).on("click", ".deleteLink", function (e) {
        e.preventDefault();
        var id = $(this).attr("rel");

        checkUserType(function () {
            $.ajax({
                type: "POST",
                url: `${domainUrl}deleteRelayLink`,
                dataType: "json",
                data: { relay_link_id: id },
                success: function (response) {
                    if (response.status) {
                        showSuccessToast();
                    } else {
                        notify(response.message, "error");
                    }
                    reloadLinks();
                },
            });
        });
    });
});
