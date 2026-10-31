// Normalize to exactly one trailing slash so `${domainUrl}path` always
// produces a valid URL regardless of whether APP_URL carries a trailing
// slash. Without this a bare APP_URL glued the path onto the host name
// (https://host.genresList) and silently broke every panel page.
var domainUrl = ($("#appUrl").val() || "/").replace(/\/+$/, "") + "/";


// If you want to enable TMDB (Optional)
var TMDB_api_key = $("#TMDB_API_KEY").val();
