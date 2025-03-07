jQuery(document).ready(function($) {
    // Handle summary length input validation
    $('input[name="cts_summary_length"]').on('input', function() {
        var value = parseInt($(this).val());
        if (value < 100) $(this).val(100);
        if (value > 700) $(this).val(700);
    });

    // Test feeds functionality
    $('#test-feeds').on('click', function(e) {
        e.preventDefault();
        
        var button = $(this);
        button.prop('disabled', true).text('Testing...');
        
        $.ajax({
            url: ctsAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'cts_test_feed',
                nonce: ctsAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    showMessage('success', response.data);
                } else {
                    showMessage('error', response.data || 'Error testing feeds');
                }
            },
            error: function(xhr, status, error) {
                showMessage('error', 'Ajax Error: ' + (error || 'Unknown error occurred'));
                console.error('Ajax error:', {xhr, status, error});
            },
            complete: function() {
                button.prop('disabled', false).text('Test Feeds');
            }
        });
    });

    // Fetch articles functionality
    $('#fetch-articles').on('click', function(e) {
        e.preventDefault();
        const $button = $(this);
        
        $button.prop('disabled', true).text('Fetching...');
        
        $.ajax({
            url: ctsAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'cts_fetch_articles',
                nonce: ctsAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    const message = response.data.message + 
                        (response.data.created > 0 ? ' <a href="edit.php" class="button">View Draft Posts</a>' : '');
                    showMessage('success', message);
                } else {
                    showMessage('error', 'Error: ' + (response.data || 'Unknown error occurred'));
                }
            },
            error: function(xhr, status, error) {
                showMessage('error', 'Ajax Error: ' + (error || 'Unknown error occurred'));
                console.error('Ajax error:', {xhr, status, error});
            },
            complete: function() {
                $button.prop('disabled', false).text('Fetch Articles Now');
            }
        });
    });

    // Test AI connection functionality
    $('.test-ai').on('click', function(e) {
        e.preventDefault();
        
        var button = $(this);
        var service = button.data('service');
        button.prop('disabled', true).text('Testing...');
        
        $.ajax({
            url: ctsAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'cts_test_ai',
                nonce: ctsAdmin.nonce,
                service: service
            },
            success: function(response) {
                if (response.success) {
                    showMessage('success', response.data);
                } else {
                    showMessage('error', response.data || 'Error testing ' + service);
                }
            },
            error: function(xhr, status, error) {
                showMessage('error', 'Ajax Error: ' + (error || 'Unknown error occurred'));
                console.error('Ajax error:', {xhr, status, error});
            },
            complete: function() {
                button.prop('disabled', false).text('Test ' + service.charAt(0).toUpperCase() + service.slice(1) + ' Connection');
            }
        });
    });

    // Make notices dismissible
    $(document).on('click', '.notice-dismiss', function() {
        $(this).closest('.notice').remove();
    });

    function showMessage(type, message) {
        const $wrap = $('.wrap:first');
        const $notice = $('<div class="notice notice-' + (type === 'success' ? 'success' : 'error') + ' is-dismissible"><p>' + message + '</p></div>');
        $wrap.find('.notice').remove();
        $wrap.prepend($notice);
    }
}); 
