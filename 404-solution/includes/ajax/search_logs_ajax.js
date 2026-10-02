
jQuery(document).ready(function($) {
    
    // get the URL from the html page.
    var url = $("#logs_ajax_search_field").attr("data-url");
    var cache = {};
    var autocompleteMethod = ($.fn && typeof $.fn.catcomplete === "function") ? "catcomplete" : "autocomplete";
    $("#logs_ajax_search_field")[autocompleteMethod]({
        source: function( request, response ) {
                    var term = request.term;
                    if ( term in cache ) {
                    response( cache[ term ] );
                    return;
                }
                $.getJSON( url, request, function( data, status, xhr ) {
                    // Validate shape: a non-JSON / null body must not
                    // poison the cache or throw inside the widget.
                    if (!Array.isArray(data)) {
                        response([]);
                        return;
                    }
                    cache[ term ] = data;
                    response( data );
                })
                .fail(function(jqXHR, textStatus, errorThrown) {
                    // Transport / parseerror failure: dismiss the
                    // autocomplete loading indicator by handing the
                    // widget an empty result list, and keep the status and
                    // body excerpt that explain it so "no suggestions" can
                    // be told apart from "the lookup failed" (shared seam,
                    // guarded so a missing asset degrades to the empty list).
                    if (typeof abj404AdminAjaxRecordFailure === 'function') {
                        abj404AdminAjaxRecordFailure(jqXHR, {
                            source: 'search-logs-autocomplete',
                            textStatus: textStatus,
                            errorThrown: errorThrown
                        });
                    }
                    response([]);
                });
            },
        delay: 500,
        minLength: 0,
        select: function(event, ui) {
            event.preventDefault();
            // when an item is selected then update the hidden fields to store it.
            $("#logs_ajax_search_field").val(ui.item.label);
            $("#redirect_to_data_field_title").val(ui.item.label);
            $("#redirect_to_data_field_id").val(ui.item.value);

            $("#logs_search_form").submit();
        },
        focus: function(event, ui) {
            // don't change the contents of the textbox just by highlighting something.
            event.preventDefault();
        }
    });
    
});
