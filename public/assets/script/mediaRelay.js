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
                targets: "_all",
                orderable: false,
            },
        ],
        ajax: {
            url: `${domainUrl}relayFileList`,
            error: (error) => {
                console.log(error);
            },
        },
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

    $(document).on("submit", "#addRelayFileForm", function (e) {
        e.preventDefault();

        checkUserType(function () {
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
                        showSuccessToast();
                        relayFileTable.ajax.reload(null, false);
                        $("#addRelayFileModal").modal("hide");
                        $("#relayProgress").hide();
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
