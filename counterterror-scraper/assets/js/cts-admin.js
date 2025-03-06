jQuery(document).ready(function($) {
    // Handle summary length input validation
    $('#cts_summary_length').on('input', function() {
        var value = parseInt($(this).val());
        if (value < 100) $(this).val(100);
        if (value > 700) $(this).val(700);
    });

    // Fetch articles functionality
    $('#fetch-articles-btn').on('click', function(e) {
        e.preventDefault();
        
        var button = $(this);
        var result = $('#fetch-result');
        var keywords = $('#cts_keywords').val();
        var feeds = $('#cts_sources').val();
        var summaryLength = $('#cts_summary_length').val();
        
        // Validate inputs
        var errors = [];
        if (!feeds.trim()) {
            errors.push("Please enter at least one feed URL");
        }
        if (!keywords.trim()) {
            errors.push("Please enter keywords for filtering articles");
        }
        if (!summaryLength || summaryLength < 100 || summaryLength > 700) {
            errors.push("Summary length must be between 100 and 700 words");
        }

        if (errors.length > 0) {
            result.html('<div class="error-message">' + errors.join('<br>') + '</div>').show();
            return;
        }

        // Show progress UI
        button.prop('disabled', true);
        result.html(
            '<div class="fetch-progress">' +
            '<div class="progress-indicator">' +
            '<div class="spinner"></div>' +
            '<div class="status">Initializing fetch process...</div>' +
            '</div>' +
            '<div class="progress-details"></div>' +
            '</div>'
        ).show();

        // Start fetch process
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'cts_fetch_articles',
                nonce: ctsAdmin.nonce,
                feeds: feeds,
                keywords: keywords,
                summary_length: summaryLength
            },
            success: function(response) {
                console.log('Success response:', response);
                if (response.success) {
                    var successHtml = 
                        '<div class="success-message">' +
                        '<h4>Fetch Complete</h4>' +
                        '<ul>' +
                        '<li>Created: <strong>' + response.data.created + '</strong> draft posts</li>' +
                        '<li>Skipped: <strong>' + response.data.skipped + '</strong> articles</li>' +
                        '</ul>';

                    if (response.data.created > 0) {
                        successHtml += '<p><a href="edit.php" class="button">View Draft Posts</a></p>';
                    }

                    successHtml += '</div>';
                    result.html(successHtml);
                } else {
                    result.html(
                        '<div class="error-message">' +
                        '<h4>Error During Fetch</h4>' +
                        '<p>' + (response.data || 'Unknown error occurred') + '</p>' +
                        '</div>'
                    );
                }
            },
            error: function(xhr, status, error) {
                console.error('Error details:', {xhr, status, error});
                var errorMessage = xhr.responseText ? xhr.responseText : error;
                result.html(
                    '<div class="error-message">' +
                    '<h4>Server Error</h4>' +
                    '<p>' + errorMessage + '</p>' +
                    '<p class="error-details">Status: ' + status + '</p>' +
                    '</div>'
                );
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });

    // Test feed functionality
    $('#test-feed-btn').on('click', function(e) {
        e.preventDefault();
        
        var button = $(this);
        var result = $('#feed-test-result');
        var feeds = $('#cts_sources').val();

        if (!feeds.trim()) {
            result.html('<div class="error-message">Please enter at least one feed URL</div>').show();
            return;
        }

        button.prop('disabled', true);
        result.html('<div class="testing">Testing feeds...</div>').show();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'cts_test_feed',
                nonce: ctsAdmin.nonce,
                feed_url: feeds.split('\n')[0] // Test first feed
            },
            success: function(response) {
                if (response.success) {
                    result.html('<div class="success-message">' + response.data + '</div>');
                } else {
                    result.html('<div class="error-message">Error: ' + response.data + '</div>');
                }
            },
            error: function(xhr, status, error) {
                result.html('<div class="error-message">Server error: ' + error + '</div>');
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });
});

// Add required CSS
jQuery(document).ready(function($) {
    $('head').append(`
        <style>
            .fetch-progress {
                background: #f8f9fa;
                padding: 15px;
                border-radius: 4px;
                margin-top: 10px;
            }
            
            .progress-indicator {
                display: flex;
                align-items: center;
                margin-bottom: 10px;
            }
            
            .spinner {
                border: 3px solid #f3f3f3;
                border-top: 3px solid #3498db;
                border-radius: 50%;
                width: 20px;
                height: 20px;
                animation: spin 1s linear infinite;
                margin-right: 10px;
            }
            
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
            
            .progress-details {
                font-size: 0.9em;
                color: #666;
            }
            
            .success-message {
                background: #d4edda;
                border: 1px solid #c3e6cb;
                padding: 15px;
                border-radius: 4px;
            }
            
            .error-message {
                background: #f8d7da;
                border: 1px solid #f5c6cb;
                padding: 15px;
                border-radius: 4px;
            }
            
            .testing {
                background: #fff3cd;
                border: 1px solid #ffeeba;
                padding: 15px;
                border-radius: 4px;
            }
        </style>
    `);
});