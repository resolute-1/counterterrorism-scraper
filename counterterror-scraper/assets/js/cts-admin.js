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
                var messageClass = response.success ? 'notice-success' : 'notice-error';
                var message = '<div class="notice ' + messageClass + ' is-dismissible"><p>' + 
                             response.data.replace(/\n/g, '<br>') + '</p></div>';
                
                $('.wrap > h1').after(message);
            },
            error: function(xhr, status, error) {
                $('.wrap > h1').after(
                    '<div class="notice notice-error is-dismissible"><p>Error: ' + error + '</p></div>'
                );
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
        const $message = $('.notice');
        
        $button.prop('disabled', true).text('Fetching...');
        $message.remove();

        $.ajax({
            url: ctsAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'cts_fetch_articles',
                nonce: ctsAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    showMessage('success', response.data.message);
                    if (response.data.created > 0) {
                        showMessage('success', response.data.message + ' <a href="edit.php" class="button">View Draft Posts</a>');
                    }
                } else {
                    showMessage('error', 'Error: ' + (response.data || 'Unknown error occurred'));
                }
            },
            error: function(xhr, status, error) {
                showMessage('error', 'Ajax Error: ' + error + ' (' + status + ')');
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
                var messageClass = response.success ? 'notice-success' : 'notice-error';
                var message = '<div class="notice ' + messageClass + ' is-dismissible"><p>' + 
                             response.data + '</p></div>';
                
                $('.wrap > h1').after(message);
            },
            error: function(xhr, status, error) {
                $('.wrap > h1').after(
                    '<div class="notice notice-error is-dismissible"><p>Error: ' + error + '</p></div>'
                );
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
